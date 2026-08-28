<?php

use App\Console\Commands\PrunePresenceVisitorsCommand;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Visitors\VisitorPresence;
use App\Support\VisitorSessionToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function presenceSite(bool $enabled = true): Site
{
    $account = Account::factory()->create();

    return Site::factory()->for($account)->create([
        'public_key' => 'site_public_presence',
        // A configured domain, because a page address is only stored for a site
        // that has one -- the endpoint is public, so without it we cannot tell
        // this site's pages from an attacker's.
        'domain' => 'shop.test',
        'settings' => ['presence' => ['enabled' => $enabled]],
    ]);
}

function reportPresence(Site $site, string $anonymousId, ?string $pageUrl = null): TestResponse
{
    return test()->postJson(route('widget.presence'), array_filter([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
        'page_url' => $pageUrl,
    ], fn ($v): bool => $v !== null));
}

test('a site that has not opted in stores nothing at all', function (): void {
    // ADR 0019 §1. Not "records but declines to show" -- a desk that has not
    // chosen to watch does not watch, and the default install keeps exactly the
    // privacy posture it had.
    $site = presenceSite(enabled: false);

    reportPresence($site, 'anon-off', 'https://shop.test/pricing')
        ->assertOk()
        ->assertJsonPath('data.reports', false);

    expect(Visitor::query()->where('anonymous_id', 'anon-off')->exists())->toBeFalse();
});

test('a visitor who never made contact is recorded once the site opts in', function (): void {
    $site = presenceSite();

    reportPresence($site, 'anon-present', 'https://shop.test/pricing')
        ->assertStatus(202)
        ->assertJsonPath('data.reports', true);

    $visitor = Visitor::query()->where('anonymous_id', 'anon-present')->firstOrFail();

    expect($visitor->last_seen_at)->not->toBeNull()
        ->and($visitor->current_visit_started_at)->not->toBeNull()
        ->and($visitor->metadata['last_page_url'])->toBe('https://shop.test/pricing');
});

test('the first heartbeat starts a visit, which is the case a gap rule misses', function (): void {
    // An opening heartbeat has no previous one to be "older than", so a rule
    // written only around the fifteen-minute gap never starts a visit at all.
    $site = presenceSite();

    reportPresence($site, 'anon-first');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-first')->firstOrFail();

    expect($visitor->current_visit_started_at)->not->toBeNull(
        'the opening heartbeat did not start a visit, so time on site can never be reported'
    );
});

test('a continuing visit keeps its start, so time on site grows', function (): void {
    $site = presenceSite();

    reportPresence($site, 'anon-continuing');
    $started = Visitor::query()->where('anonymous_id', 'anon-continuing')->firstOrFail()->current_visit_started_at;

    // A second report a minute later is the same visit.
    $this->travel(1)->minutes();
    reportPresence($site, 'anon-continuing');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-continuing')->firstOrFail();

    expect($visitor->current_visit_started_at->timestamp)->toBe($started->timestamp,
        'the visit restarted, so time on site would reset every heartbeat')
        ->and($visitor->last_seen_at->greaterThan($started))->toBeTrue();
});

test('a gap past the recent window starts a new visit', function (): void {
    // Reuses VisitorPresence's own cutoff rather than inventing a session
    // length: a gap long enough to read as quiet is long enough to be a new
    // visit.
    $site = presenceSite();

    reportPresence($site, 'anon-returning');
    $first = Visitor::query()->where('anonymous_id', 'anon-returning')->firstOrFail()->current_visit_started_at;

    $this->travel(VisitorPresence::RECENT_MINUTES + 1)->minutes();
    reportPresence($site, 'anon-returning');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-returning')->firstOrFail();

    expect($visitor->current_visit_started_at->greaterThan($first))->toBeTrue(
        'a visitor returning after the recent window is still on their original visit'
    );
});

test('the server stamps the time, and the widget cannot', function (): void {
    // The endpoint is public. A forged timestamp parked in the future would
    // read active indefinitely and outrun the retention window at once, so the
    // payload carries none and anything sent is ignored.
    $site = presenceSite();

    $this->postJson(route('widget.presence'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-forged',
        'last_seen_at' => now()->addYears(5)->toJSON(),
        'current_visit_started_at' => now()->addYears(5)->toJSON(),
    ])->assertStatus(202);

    $visitor = Visitor::query()->where('anonymous_id', 'anon-forged')->firstOrFail();

    expect($visitor->last_seen_at->isFuture())->toBeFalse('a client timestamp reached last_seen_at');
});

test('a page url is sanitised before it is stored', function (): void {
    $site = presenceSite();

    reportPresence($site, 'anon-token', 'https://shop.test/reset?reset_token=abc#x');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-token')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/reset');
});

test('a tester visitor is not recorded, so an agent does not watch themselves', function (): void {
    $site = presenceSite();

    reportPresence($site, "tester-site-{$site->id}-agent-1", 'https://shop.test/x')
        ->assertOk()
        ->assertJsonPath('data.reports', false);

    expect(Visitor::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('the pruner measures from the last heartbeat, not from creation', function (): void {
    // Which timestamp is the whole rule. From created_at, somebody first seen
    // 31 days ago is deleted WHILE they are heartbeating and reappears as new.
    $site = presenceSite();

    $active = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-old-but-here',
        'created_at' => now()->subDays(90),
        'last_seen_at' => now()->subMinutes(2),
        'presence_only' => true,
    ]);

    $gone = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-long-gone',
        'created_at' => now()->subDays(90),
        'last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 1),
        'presence_only' => true,
    ]);

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($active->id)->exists())->toBeTrue(
        'a visitor who is on the site right now was deleted for being old'
    )->and(Visitor::query()->whereKey($gone->id)->exists())->toBeFalse();
});

test('a visitor who made contact is never pruned', function (): void {
    // They are support history, and tickets.requester_id is nullOnDelete -- so
    // deleting one detaches their tickets from whoever raised them, silently.
    $site = presenceSite();
    $account = $site->account;

    $withConversation = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-had-chat',
        'last_seen_at' => now()->subDays(400),
    ]);
    Conversation::factory()->for($site)->for($withConversation)->create();

    // Deliberately NO conversation. `tickets.conversation_id` is nullable, so
    // this visitor is reachable only through the tickets guard -- and the first
    // version of this test gave them a conversation as well, which meant
    // removing that guard entirely still passed.
    $withTicket = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-had-ticket',
        'last_seen_at' => now()->subDays(400),
    ]);
    Ticket::factory()->for($account)->for($site)->for($withTicket, 'requester')->create();

    expect($withTicket->conversations()->count())->toBe(0, 'the fixture gave them a conversation, so the tickets guard is untested');

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($withConversation->id)->exists())->toBeTrue()
        ->and(Visitor::query()->whereKey($withTicket->id)->exists())->toBeTrue();
});

test('the retention window can be shortened but not lengthened', function (): void {
    // ADR 0019 §4: 30 days is the product's MAXIMUM for a presence-only row,
    // not merely its default, so a configuration that lengthens it does not get
    // to raise the stated policy.
    $site = presenceSite();

    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-clamped',
        'last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 5),
        'presence_only' => true,
    ]);

    config(['wayfindr.presence.retention_days' => 3650]);

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($visitor->id)->exists())->toBeFalse(
        'a configured window longer than the maximum was honoured'
    );
});

test('bootstrap cannot swallow a returning visitor\'s new visit', function (): void {
    // Presence is not the only writer of last_seen_at -- bootstrap,
    // conversation start, message fetch and typing all stamp it. A returning
    // visitor who OPENS THE PANEL before their first heartbeat arrives would
    // have last_seen_at refreshed by bootstrap first, and a rule living only in
    // the presence recorder would then see a recent timestamp, keep the
    // previous visit's start, and report a visit spanning days.
    $site = presenceSite();

    reportPresence($site, 'anon-bootstrap-first');
    $first = Visitor::query()->where('anonymous_id', 'anon-bootstrap-first')->firstOrFail()->current_visit_started_at;

    $this->travel(VisitorPresence::RECENT_MINUTES + 1)->minutes();

    // The panel opens before the heartbeat. This is the write that used to hide
    // the gap from everything downstream.
    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-bootstrap-first',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-bootstrap-first')->firstOrFail();

    expect($visitor->current_visit_started_at->greaterThan($first))->toBeTrue(
        'bootstrap refreshed last_seen_at without starting the new visit, so time on site spans the gap'
    );
});

test('a writer that does not touch last_seen_at leaves the visit alone', function (): void {
    // The transition keys on last_seen_at changing, so an unrelated save must
    // not restart somebody's visit.
    $site = presenceSite();

    reportPresence($site, 'anon-untouched');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-untouched')->firstOrFail();
    $started = $visitor->current_visit_started_at;

    $this->travel(1)->hours();

    $visitor->forceFill(['name' => 'Given a name by intake'])->save();

    expect($visitor->fresh()->current_visit_started_at->timestamp)->toBe($started->timestamp);
});

test('a visitor who makes contact mid-sweep is not deleted from under it', function (): void {
    // The chunk is a snapshot. A visitor selected as stale can start a chat
    // before the loop reaches them -- and conversations.visitor_id CASCADES, so
    // an unconditional delete by primary key takes the support request that
    // just landed with it.
    //
    // Interposed on the chunk SELECT, which is exactly that window.
    $site = presenceSite();

    $stale = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-mid-sweep',
        'last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 1),
        'presence_only' => true,
    ]);

    $interposed = false;

    DB::listen(function ($query) use ($site, $stale, &$interposed): void {
        if ($interposed
            || ! str_contains($query->sql, 'from "visitors"')
            || ! str_contains($query->sql, 'order by "id" asc')) {
            return;
        }

        $interposed = true;

        // They came back, and started a conversation, after we decided they
        // were gone.
        Conversation::factory()->for($site)->for($stale)->create();
    });

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect($interposed)->toBeTrue('nothing landed during the sweep, so this proves nothing');

    expect(Visitor::query()->whereKey($stale->id)->exists())->toBeTrue(
        'a visitor who made contact mid-sweep was deleted, taking their conversation with them'
    );
    expect(Conversation::query()->where('visitor_id', $stale->id)->exists())->toBeTrue(
        'the conversation that just landed was cascaded away'
    );
});

test('a heartbeat cannot plant another host in front of an agent', function (): void {
    // The endpoint is public and so is the site key, so this value is
    // attacker-controlled -- and stored addresses render as clickable
    // target="_blank" links on the agent ticket page. Presence is the newest
    // public writer, which makes it the newest way in.
    $site = presenceSite();

    reportPresence($site, 'anon-phish', 'https://attacker.example/login')->assertStatus(202);

    $visitor = Visitor::query()->where('anonymous_id', 'anon-phish')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBeNull(
        'a foreign host was stored and will be rendered as a link to an agent'
    );

    // The visitor is still recorded: presence is about who is here, and we can
    // know that without believing where they claim to be.
    expect($visitor->last_seen_at)->not->toBeNull();
});

test('a token in the path does not reach an agent either', function (): void {
    $site = presenceSite();

    reportPresence($site, 'anon-path-token', 'https://shop.test/reset-password/9f2c8a1b4e6d7c3f0a5b2e8d1c4f7a9b');

    expect(Visitor::query()->where('anonymous_id', 'anon-path-token')->firstOrFail()->metadata['last_page_url'])
        ->toBe('https://shop.test/reset-password/[redacted]');
});

test('a visitor who only ever opened the widget is never pruned', function (): void {
    // The bug this column exists to prevent, and it would have hit EVERY
    // install -- including ones that never enabled presence.
    //
    // BootstrapController creates a visitor the moment somebody opens the
    // widget, and ADR 0016 §1 counts opening the widget as making contact. Such
    // a row has no conversation and no ticket, so a pruner inferring
    // "never made contact" from their absence would have deleted a decade of
    // them, irreversibly, on the first scheduled run after upgrading.
    $site = presenceSite();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-opened-only',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-opened-only')->firstOrFail();

    $visitor->forceFill(['last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 60)])->save();

    expect($visitor->fresh()->conversations()->count())->toBe(0, 'the fixture made contact, so this proves nothing');

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($visitor->id)->exists())->toBeTrue(
        'a visitor who opened the widget was deleted as though they had never made contact'
    );
});

test('every row that predates the column is safe', function (): void {
    // Defaulting to false is the whole protection: a legacy row cannot be
    // presence-only, because presence did not exist when it was written.
    $site = presenceSite();

    $legacy = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-legacy',
        'last_seen_at' => now()->subYears(2),
    ]);

    // Read back rather than trusted from the instance: `create()` does not
    // reload database defaults, so the in-memory value is null while the stored
    // one is false. Both are safe here -- the pruner matches on `true` and
    // neither is -- but the column default is the thing being asserted.
    expect($legacy->fresh()->presence_only)->toBeFalse('the column does not default to false');

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($legacy->id)->exists())->toBeTrue();
});

test('opening the widget later takes a presence row out of scope', function (): void {
    // A visitor first seen by a heartbeat, who then opens the panel. They have
    // made contact now, and no conversation exists to prove it.
    $site = presenceSite();

    reportPresence($site, 'anon-then-opened');

    expect(Visitor::query()->where('anonymous_id', 'anon-then-opened')->firstOrFail()->presence_only)->toBeTrue();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-then-opened',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-then-opened')->firstOrFail();

    expect($visitor->presence_only)->toBeFalse('opening the widget left them prunable');

    $visitor->forceFill(['last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 1)])->save();

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($visitor->id)->exists())->toBeTrue();
});

test('an inbound email does not put somebody on the website', function (): void {
    // The live board answers "who is on the site right now". InboundMailRouter
    // stamps a sighting for every sender, including one whose null anonymous_id
    // proves they never loaded the widget -- so keying the visit boundary off
    // the cross-channel timestamp put an email correspondent on the board with
    // a time-on-site counting up while they sat in their mail client.
    $site = presenceSite();
    $site->forceFill(['inbound_address' => 'support@shop.test'])->save();

    $message = InboundMessage::fromPayload([
        'to' => $site->inbound_address,
        'from' => 'mailer@elsewhere.test',
        'subject' => 'Help please',
        'text' => 'My order has not arrived.',
    ]);

    app(InboundMailRouter::class)->route($message);

    $visitor = Visitor::query()->where('email', 'mailer@elsewhere.test')->firstOrFail();

    expect($visitor->last_seen_at)->not->toBeNull('an email is still contact')
        ->and($visitor->anonymous_id)->toBeNull()
        ->and($visitor->last_web_seen_at)->toBeNull('an email is not a page load')
        ->and($visitor->current_visit_started_at)->toBeNull('an email started a website visit');
});

test('a returning visitor who emails does not resume a website visit', function (): void {
    // The harder half: this visitor HAS an anonymous_id, so a rule that merely
    // asked "have they ever used the widget" would still fabricate a visit.
    $site = presenceSite();
    $site->forceFill(['inbound_address' => 'support@shop.test'])->save();

    reportPresence($site, 'anon-emails-later');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-emails-later')->firstOrFail();
    $visitor->forceFill([
        'email' => 'known@shop.test',
        'last_web_seen_at' => now()->subDays(3),
        'last_seen_at' => now()->subDays(3),
        'current_visit_started_at' => now()->subDays(3),
    ])->save();

    $startedAt = $visitor->fresh()->current_visit_started_at;

    $message = InboundMessage::fromPayload([
        'to' => $site->inbound_address,
        'from' => 'known@shop.test',
        'subject' => 'Following up',
        'text' => 'Any news?',
    ]);

    app(InboundMailRouter::class)->route($message);

    $visitor->refresh();

    expect($visitor->last_seen_at->isToday())->toBeTrue('the email was not recorded as contact')
        ->and($visitor->last_web_seen_at->toDateString())->toBe(now()->subDays(3)->toDateString())
        ->and($visitor->current_visit_started_at->eq($startedAt))->toBeTrue('the email moved the visit');
});

test('a website sighting is recorded as contact without the writer saying so', function (): void {
    // Convergence: a web writer sets the website column and the model derives
    // the cross-channel one. A writer that had to set both would eventually
    // set one, and the visitor directory would show somebody as out of touch
    // while they were on the site.
    $site = presenceSite();

    reportPresence($site, 'anon-derives');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-derives')->firstOrFail();

    expect($visitor->last_web_seen_at)->not->toBeNull()
        ->and($visitor->last_seen_at)->not->toBeNull('the sighting was not recorded as contact')
        ->and($visitor->last_seen_at->eq($visitor->last_web_seen_at))->toBeTrue();
});

test('fetching messages runs the visit transition like every other writer', function (): void {
    // The stock widget calls refreshMessages() BEFORE bootstrap when a
    // returning visitor opens the panel. That writer used a relationship
    // update, which is a mass update and dispatches no model events -- so it
    // refreshed the sighting without starting a visit, bootstrap then saw a
    // recent timestamp and left the old start alone, and the board reported a
    // visit still running from the previous session.
    $site = presenceSite();

    reportPresence($site, 'anon-refresh');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-refresh')->firstOrFail();

    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    $stale = now()->subDays(2);
    $visitor->forceFill([
        'last_web_seen_at' => $stale,
        'last_seen_at' => $stale,
        'current_visit_started_at' => $stale,
    ])->save();

    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    test()->getJson(route('conversations.messages.index', [
        'supportCode' => $conversation->support_code,
    ]).'?'.http_build_query([
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-refresh',
        'visitor_token' => $token,
    ]))->assertSuccessful();

    $visitor->refresh();

    expect($visitor->current_visit_started_at->isToday())
        ->toBeTrue('the visit still spans the previous session');
});

test('one visitor cannot spend another visitor behind the same address', function (): void {
    // The heartbeat is the one widget endpoint every visitor hits continuously,
    // so the shared per-IP key every other limiter uses divides the quota by
    // the number of people behind an office or carrier NAT. The symptom is
    // silent: valid heartbeats take a 429 and those visitors flicker to
    // inactive on the board while nobody reports an error.
    config()->set('wayfindr.widget_rate_limits.presence_per_minute', 2);
    config()->set('wayfindr.widget_rate_limits.presence_per_ip_per_minute', 1000);

    $site = presenceSite();

    reportPresence($site, 'anon-noisy')->assertSuccessful();
    reportPresence($site, 'anon-noisy')->assertSuccessful();
    reportPresence($site, 'anon-noisy')->assertStatus(429);

    // The colleague at the next desk has spent nothing.
    reportPresence($site, 'anon-quiet')->assertSuccessful();
});

test('the per-address ceiling still bounds a forged client', function (): void {
    // Rekeying to the visitor must not remove the abuse cap -- rotating the
    // anonymous ID is free, and creating rows is the thing worth bounding.
    config()->set('wayfindr.widget_rate_limits.presence_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_per_ip_per_minute', 3);

    $site = presenceSite();

    foreach (range(1, 3) as $i) {
        reportPresence($site, 'anon-forged-'.$i)->assertSuccessful();
    }

    reportPresence($site, 'anon-forged-4')->assertStatus(429);
});

test('an admin can turn presence on from the dashboard', function (): void {
    // The product path, end to end. The feature shipped with a setting that
    // only a factory or a hand-written SQL UPDATE could reach, which is not an
    // opt-in an operator can give -- ADR 0019 §1 describes a decision somebody
    // takes deliberately, and there was nowhere to take it.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'settings' => [],
    ]);

    expect(SitePresenceReporting::for($site)->enabled)->toBeFalse('presence was on by default');

    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), ['presence_enabled' => '1'])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect(SitePresenceReporting::for($site->fresh())->enabled)->toBeTrue();

    // And the switch is what the endpoint actually reads.
    reportPresence($site->fresh(), 'anon-after-toggle')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-after-toggle')->exists())->toBeTrue();

    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), [])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect(SitePresenceReporting::for($site->fresh())->enabled)->toBeFalse('it could not be turned back off');
});

test('turning presence on is not an ordinary agent decision', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    test()->actingAs($agent)
        ->put(route('dashboard.sites.presence.update', $site), ['presence_enabled' => '1'])
        ->assertForbidden();

    expect(SitePresenceReporting::for($site->fresh())->enabled)->toBeFalse();
});

test('creating durable rows is bounded even when traffic is not', function (): void {
    // The traffic limits are the cheap half. A forged client rotating anonymous
    // IDs turns every accepted request into a visitor that lives for the whole
    // retention window, so a ceiling sized for a busy office becomes millions
    // of rows a day when it is spent on creation.
    config()->set('wayfindr.widget_rate_limits.presence_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_per_ip_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 3);

    $site = presenceSite();

    foreach (range(1, 6) as $i) {
        reportPresence($site, 'anon-mint-'.$i)->assertSuccessful();
    }

    expect(Visitor::query()->where('anonymous_id', 'like', 'anon-mint-%')->count())
        ->toBe(3, 'row creation was not bounded');
});

test('a visitor already known is refreshed regardless of the creation cap', function (): void {
    // The cap must bound minting, not reporting. Somebody the site already
    // knows costs nothing durable, and throttling them would make the board
    // wrong for exactly the visitors it is right about.
    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 1);

    $site = presenceSite();

    reportPresence($site, 'anon-known')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-known')->firstOrFail();

    $visitor->forceFill(['last_web_seen_at' => now()->subHour()])->save();

    // The creation quota is spent. This is not a creation.
    reportPresence($site, 'anon-known')->assertSuccessful();

    expect($visitor->fresh()->last_web_seen_at->diffInMinutes(now()))
        ->toBeLessThan(2, 'a known visitor stopped being refreshed');
});

test('bootstrap survives losing the race to create the visitor', function (): void {
    // A new visitor's first page load puts bootstrap and the first heartbeat in
    // flight together. Both read no row, both insert, and the unique constraint
    // lets exactly one win -- and the loser used to be a 500 that left the
    // widget with no configuration at all.
    //
    // Sequencing the two requests does NOT reproduce it: whichever runs second
    // simply finds the row and updates it. The conflict only exists when the
    // row appears between the read and the insert, so that is what is staged --
    // a competing insert at the moment bootstrap commits to creating.
    $site = presenceSite();
    $raced = false;

    Visitor::creating(function (Visitor $creating) use (&$raced, $site): void {
        if ($raced || $creating->anonymous_id !== 'anon-raced') {
            return;
        }

        $raced = true;

        // The heartbeat, landing in the gap. Written through the query builder
        // so it does not re-enter this hook.
        DB::table('visitors')->insert([
            'site_id' => $site->id,
            'anonymous_id' => 'anon-raced',
            'presence_only' => true,
            'last_seen_at' => now(),
            'last_web_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-raced',
    ])->assertSuccessful();

    expect($raced)->toBeTrue('the race never happened, so this proves nothing');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-raced')->firstOrFail();

    expect(Visitor::query()->where('anonymous_id', 'anon-raced')->count())->toBe(1)
        ->and($visitor->presence_only)->toBeFalse('the row the heartbeat won kept its presence-only mark');
});

test('a visitor pruned mid-request still gets their conversation', function (): void {
    // The pruner re-checks its predicates under a lock, which makes it safe
    // against a writer that has already committed and not against one still in
    // flight. The unsafe ordering is narrow and real: the request resolves the
    // visitor, the pruner takes the lock and deletes, and the conversation
    // insert then fails its foreign key -- so the visitor is told their message
    // could not be sent, for a reason that has nothing to do with them.
    //
    // Driven deterministically: the row is deleted the moment the request has
    // read it, which is exactly the window and cannot be produced by ordinary
    // sequencing in one process.
    $site = presenceSite();

    reportPresence($site, 'anon-returns')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-returns')->firstOrFail();
    $visitorId = $visitor->id;
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    // The request reads this visitor more than once: the intake check, then the
    // token resolve, then the locked read inside the transaction. The window
    // being reproduced is between the token resolve and the lock, so the delete
    // goes after the SECOND read -- deleting on the first only reproduces a
    // missing visitor, which the token resolver already rejects with a 401.
    $reads = 0;
    $pruned = false;

    Visitor::retrieved(function (Visitor $read) use (&$reads, &$pruned, $visitorId): void {
        if ($pruned || $read->id !== $visitorId) {
            return;
        }

        $reads++;

        if ($reads < 2) {
            return;
        }

        $pruned = true;

        Visitor::query()->whereKey($visitorId)->delete();
    });

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-returns',
        'visitor_token' => $token,
        'subject' => 'My order has not arrived',
    ])->assertSuccessful();

    expect($pruned)->toBeTrue('the race never happened, so this proves nothing');

    $recreated = Visitor::query()->where('anonymous_id', 'anon-returns')->firstOrFail();

    expect($recreated->id)->not->toBe($visitorId)
        ->and(Conversation::query()->where('visitor_id', $recreated->id)->exists())
        ->toBeTrue('the conversation was lost with the visitor');
});

test('bootstrap does not hand out a token for a visitor it just lost', function (): void {
    // Eloquent does not treat an update matching zero rows as a failure, so a
    // prune landing between the read and the write left bootstrap answering
    // 200 with a session token naming a visitor that no longer exists. Every
    // conversation and message request afterwards then failed token resolution
    // with a 401 the visitor could do nothing about -- worse than an error,
    // because it looks like success right up until they try to say something.
    $site = presenceSite();

    reportPresence($site, 'anon-lost')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-lost')->firstOrFail();
    $goneId = $visitor->id;
    $pruned = false;

    Visitor::retrieved(function (Visitor $read) use (&$pruned, $goneId): void {
        if ($pruned || $read->id !== $goneId) {
            return;
        }

        $pruned = true;

        Visitor::query()->whereKey($goneId)->delete();
    });

    $response = test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-lost',
    ])->assertSuccessful();

    expect($pruned)->toBeTrue('the race never happened, so this proves nothing');

    // The token has to name a visitor that is actually there.
    $token = $response->json('data.visitor.token');
    $current = Visitor::query()->where('anonymous_id', 'anon-lost')->firstOrFail();

    expect($current->id)->not->toBe($goneId);

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-lost',
        'visitor_token' => $token,
        'subject' => 'Still here',
    ])->assertSuccessful();
});

test('two first heartbeats for one visitor do not collide', function (): void {
    // The presence endpoint's own retry, which until now had no test at all.
    // On PostgreSQL this is not merely a caught exception: a constraint
    // violation aborts the surrounding transaction, so a retry that is not
    // isolated runs on a connection refusing every statement.
    $site = presenceSite();
    $raced = false;

    Visitor::creating(function (Visitor $creating) use (&$raced, $site): void {
        if ($raced || $creating->anonymous_id !== 'anon-twin') {
            return;
        }

        $raced = true;

        DB::table('visitors')->insert([
            'site_id' => $site->id,
            'anonymous_id' => 'anon-twin',
            'presence_only' => true,
            'last_seen_at' => now(),
            'last_web_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    reportPresence($site, 'anon-twin')->assertSuccessful();

    expect($raced)->toBeTrue('the race never happened, so this proves nothing')
        ->and(Visitor::query()->where('anonymous_id', 'anon-twin')->count())->toBe(1);
});

test('a site can be watched without saying which page', function (): void {
    // Redaction is a heuristic and cannot be made a proof -- no shape separates
    // a short lowercase token from a short lowercase word. A site that puts
    // secrets in path segments gets a real answer instead of a rule that is
    // right most of the time.
    $site = presenceSite();
    $site->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => false]]])->save();

    reportPresence($site, 'anon-nopage', 'https://shop.test/invite/ABCDEF')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-nopage')->firstOrFail();

    expect($visitor->metadata['last_page_url'] ?? null)->toBeNull('a page address was stored anyway');

    // And the widget is told, so it need not put one on the wire at all. (The
    // endpoint that carries this to the widget is on the branch above; what
    // belongs here is that the payload says so.)
    expect(SitePresenceReporting::for($site->fresh())->toPayload())
        ->toMatchArray(['reports' => true, 'page_urls' => false]);
});

test('turning presence off forgets the people it collected', function (): void {
    // Switching it off is a revocation. Leaving the rows to age out over thirty
    // days would mean the visitor directory still listing people who never made
    // contact on a site whose operator has just said not to watch them.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_revoke',
        'settings' => ['presence' => ['enabled' => true]],
    ]);

    reportPresence($site, 'anon-silent')->assertSuccessful();
    reportPresence($site, 'anon-spoke')->assertSuccessful();

    $spoke = Visitor::query()->where('anonymous_id', 'anon-spoke')->firstOrFail();
    Conversation::factory()->for($site)->for($spoke)->create();

    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), [])
        ->assertRedirect();

    expect(Visitor::query()->where('anonymous_id', 'anon-silent')->exists())
        ->toBeFalse('a visitor who never made contact was kept after the operator revoked')
        ->and(Visitor::query()->where('anonymous_id', 'anon-spoke')->exists())
        ->toBeTrue('somebody who wrote in was deleted');
});

test('sustained row creation is bounded, not just bursts', function (): void {
    // Thirty a minute held all day is 43,200 rows and about 1.3 million across
    // the retention window, so the burst allowance that makes an office work is
    // by itself a licence to grow the table without end.
    config()->set('wayfindr.widget_rate_limits.presence_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_per_ip_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_day', 4);

    $site = presenceSite();

    foreach (range(1, 8) as $i) {
        reportPresence($site, 'anon-day-'.$i)->assertSuccessful();
    }

    expect(Visitor::query()->where('anonymous_id', 'like', 'anon-day-%')->count())
        ->toBe(4, 'the daily creation budget did not hold');
});

test('starting a conversation counts as contact even without bootstrap', function (): void {
    // This route does not require bootstrap, so the flag meaning "never made
    // contact" has to be cleared here too. Nothing was at risk -- the pruner
    // refuses to delete anybody with a conversation -- but the record would
    // have said something untrue about somebody who had just written in.
    $site = presenceSite();

    reportPresence($site, 'anon-writes')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-writes')->firstOrFail();

    expect($visitor->presence_only)->toBeTrue();

    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-writes',
        'visitor_token' => $token,
        'subject' => 'A question',
    ])->assertSuccessful();

    expect($visitor->fresh()->presence_only)->toBeFalse('a visitor who wrote in is still marked as never having made contact');
});

test('an email correspondent does not read as being on the site', function (): void {
    // "Active" on a visitor means somebody is at the other end right now. After
    // mail and web were separated, the cross-channel timestamp stopped being
    // able to say that -- an email correspondent read as active while they sat
    // in their mail client, and an agent would offer to cobrowse with a browser
    // that was not open.
    $site = presenceSite();

    $mailer = Visitor::factory()->for($site)->create([
        'anonymous_id' => null,
        'email' => 'mailer@elsewhere.test',
        'last_seen_at' => now(),
    ]);

    // Cleared after creating: the factory fills this in from `last_seen_at`
    // for the widget visitor it normally makes, and cannot tell an explicit
    // null from an absent one.
    $mailer->forceFill(['last_web_seen_at' => null])->save();

    // `unknown` rather than `not_reported`: this surface has always named the
    // null case that way, and VisitorPresence keeps the cutoffs rather than the
    // labels, which is the part that must not diverge.
    expect($mailer->presenceState())->toBe('unknown')
        // And the cross-channel column still answers its own question.
        ->and($mailer->last_seen_at->isToday())->toBeTrue();

    // Somebody actually browsing does read as active.
    reportPresence($site, 'anon-browsing')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-browsing')->firstOrFail()->presenceState())
        ->toBe(VisitorPresence::ACTIVE);
});

test('turning page addresses off clears the ones already stored', function (): void {
    // The form recommends this switch to operators whose paths carry codes or
    // tokens, so "from now on" is the wrong scope: a visitor who does not
    // heartbeat again keeps the address that prompted the change for thirty
    // days, on an agent's screen the whole time.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_purge',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    reportPresence($site, 'anon-stored', 'https://shop.test/pricing')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-stored')->firstOrFail()->metadata['last_page_url'])
        ->toBe('https://shop.test/pricing');

    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), ['presence_enabled' => '1'])
        ->assertRedirect();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-stored')->firstOrFail();

    expect($visitor->metadata['last_page_url'] ?? null)
        ->toBeNull('an address the operator asked us to stop keeping was kept');

    // The visitor is still there; only the address went.
    expect($visitor->last_web_seen_at)->not->toBeNull();
});

test('a heartbeat does not undo context written while it was in flight', function (): void {
    // `metadata` is one JSON column, so writing it replaces the whole value. A
    // heartbeat that read the row, then waited while bootstrap committed new
    // host context, would merge into its stale snapshot and put the old
    // context back.
    $site = presenceSite();

    reportPresence($site, 'anon-merge', 'https://shop.test/pricing')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-merge')->firstOrFail();
    $raced = false;

    // The competing write, landing after this request has read the row.
    Visitor::retrieved(function (Visitor $read) use (&$raced, $visitor): void {
        if ($raced || $read->id !== $visitor->id) {
            return;
        }

        $raced = true;

        // `where('id', ...)`, not `whereKey()`: that is an Eloquent method and
        // the query builder does not have it, so the competing write silently
        // did nothing and the test passed against the bug.
        DB::table('visitors')->where('id', $visitor->id)->update([
            'metadata' => json_encode(['last_page_url' => 'https://shop.test/pricing', 'plan' => 'pro']),
        ]);
    });

    reportPresence($site, 'anon-merge', 'https://shop.test/checkout')->assertSuccessful();

    expect($raced)->toBeTrue('the race never happened, so this proves nothing');

    $metadata = Visitor::query()->where('anonymous_id', 'anon-merge')->firstOrFail()->metadata;

    expect($metadata['plan'] ?? null)->toBe('pro', 'the heartbeat erased context written while it was in flight')
        ->and($metadata['last_page_url'])->toBe('https://shop.test/checkout');
});

test('the retention sweep is actually scheduled and resolvable', function (): void {
    // The whole of ADR 0019 §4 is a promise that rows disappear. That promise
    // is only as good as the scheduler being able to find the command, and
    // `bootstrap/app.php` carries an explicit `withCommands()` list that this
    // command is deliberately NOT in -- it is discovered from
    // app/Console/Commands, as `wayfindr:expire-idle-cobrowse-sessions` has
    // been for as long as cobrowse has shipped.
    //
    // Written down because the arrangement looks like an omission: anyone
    // reading the list will wonder, and this answers them without their having
    // to run the scheduler to find out.
    expect(array_keys(Artisan::all()))
        ->toContain('wayfindr:prune-presence-visitors');

    $scheduled = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($scheduled->filter(fn (string $c): bool => str_contains($c, 'prune-presence-visitors')))
        ->not->toBeEmpty('the retention sweep is never run');
});

test('a heartbeat in flight cannot outlive the revocation', function (): void {
    // The endpoint checks the setting on the way in. An operator revoking
    // presence between that check and the write would have their deletion pass
    // over a row this request then created -- one visitor left behind who never
    // made contact, on a site that had just said not to watch them, until the
    // retention sweep. The dashboard promises otherwise in as many words.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_revoked',
        'settings' => ['presence' => ['enabled' => true]],
    ]);

    $revoked = false;

    // The operator's revocation, landing after the endpoint has read the
    // setting and before the row is written.
    Site::retrieved(function (Site $read) use (&$revoked, $site): void {
        if ($revoked || $read->id !== $site->id) {
            return;
        }

        $revoked = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => false]]),
        ]);
    });

    test()->postJson(route('widget.presence'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-revoked',
    ])->assertSuccessful();

    expect($revoked)->toBeTrue('the race never happened, so this proves nothing')
        ->and(Visitor::query()->where('anonymous_id', 'anon-revoked')->exists())
        ->toBeFalse('a visitor was recorded for a site that had just revoked presence');
});

test('the heartbeat answers with the settings in force', function (): void {
    // A visitor who never opens the panel never calls bootstrap, so the config
    // they fetched at page load would be the newest answer that tab ever gets
    // -- and those are exactly the visitors this feature exists for. Rejecting
    // their writes server-side does not help: the address has already crossed
    // the wire by then.
    $site = presenceSite();

    reportPresence($site, 'anon-hears')
        ->assertSuccessful()
        ->assertJsonPath('data.reports', true)
        ->assertJsonPath('data.page_urls', true);

    $site->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => false]]])->save();

    reportPresence($site, 'anon-hears')
        ->assertSuccessful()
        ->assertJsonPath('data.reports', true)
        ->assertJsonPath('data.page_urls', false);

    $site->forceFill(['settings' => ['presence' => ['enabled' => false]]])->save();

    reportPresence($site, 'anon-hears')
        ->assertSuccessful()
        ->assertJsonPath('data.reports', false);
});

test('a site that keeps no page addresses keeps none from any writer', function (): void {
    // The board and the visitor profile read one field, so an address
    // suppressed on the heartbeat and stored by bootstrap is a setting that
    // does not mean anything.
    $site = presenceSite();
    $site->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => false]]])->save();

    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-writers',
        'page_url' => 'https://shop.test/invite/ABCDEF',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-writers')->firstOrFail();

    expect($visitor->metadata['last_page_url'] ?? null)->toBeNull('bootstrap stored an address the site does not keep');

    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-writers',
        'visitor_token' => $token,
        'page_url' => 'https://shop.test/invite/ABCDEF',
        'subject' => 'Help',
    ])->assertSuccessful();

    expect($visitor->fresh()->metadata['last_page_url'] ?? null)
        ->toBeNull('starting a conversation stored an address the site does not keep');
});

test('a heartbeat in flight cannot restore an address the operator just removed', function (): void {
    // The endpoint captures the page on the way in. An operator switching
    // addresses off between that and the write would have their sweep pass
    // over this visitor and then watch this request put one back.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_inflight',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    reportPresence($site, 'anon-inflight', 'https://shop.test/pricing')->assertSuccessful();

    $switched = false;

    Site::retrieved(function (Site $read) use (&$switched, $site): void {
        if ($switched || $read->id !== $site->id) {
            return;
        }

        $switched = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => true, 'page_urls' => false]]),
        ]);
    });

    reportPresence($site, 'anon-inflight', 'https://shop.test/checkout')->assertSuccessful();

    expect($switched)->toBeTrue('the race never happened, so this proves nothing')
        ->and(Visitor::query()->where('anonymous_id', 'anon-inflight')->firstOrFail()->metadata['last_page_url'] ?? null)
        ->toBeNull('an address was stored after the operator switched them off');
});

test('switching everything off leaves no addresses behind', function (): void {
    // Turning presence off deletes the visitors it collected, but contacted
    // visitors stay -- and they hold addresses too, written by bootstrap. An
    // operator unchecking the page-address box while switching presence off
    // would otherwise keep exactly the addresses they unchecked it for.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_bothoff',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-contacted',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-contacted')->firstOrFail();

    expect($visitor->metadata['last_page_url'])->toBe('https://shop.test/pricing');

    // Everything off in one submission.
    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), [])
        ->assertRedirect();

    expect($visitor->fresh())->not->toBeNull('a contacted visitor was deleted')
        ->and($visitor->fresh()->metadata['last_page_url'] ?? null)
        ->toBeNull('an address survived the operator switching addresses off');
});

test('the visitor directory filters on the same sighting it labels', function (): void {
    // Left on the shared default, the Active filter answered a different
    // question from the badge printed next to each row.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create(['domain' => 'shop.test']);

    // A real widget visitor who browsed days ago and emailed today. Keeping an
    // anonymous_id matters: a NULL one is excluded from this index by the
    // tester filter regardless of presence, so a fixture without one would
    // pass whichever column the filter used.
    $emailedToday = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-emailed-today',
        'email' => 'known@shop.test',
        'last_seen_at' => now(),
        'last_web_seen_at' => now()->subDays(3),
    ]);

    $browsing = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-browsing']);

    $response = test()->actingAs($agent)
        ->get(route('dashboard.visitors.index', ['presence' => 'active']))
        ->assertOk();

    $listed = collect($response->viewData('visitors')->items())->pluck('id');

    expect($listed)->toContain($browsing->id);

    // assertNotContains, NOT expect()->not->toContain($id, $message):
    // `toContain` is variadic, so a message becomes a second needle and the
    // negated form asserts that neither appears -- trivially true. This file's
    // sibling documents the same trap three times over, and it still caught me:
    // the mutation that reverts the filter column left the test green.
    test()->assertNotContains(
        $emailedToday->id,
        $listed->all(),
        'somebody whose only activity today was an email was listed as being on the site',
    );
});

test('bootstrap cannot restore an address the operator just removed', function (): void {
    // The heartbeat already reads the setting under the site lock. Bootstrap
    // and conversation start took the visitor lock but read the setting from
    // the copy of the site they arrived with, so the cleanup could finish first
    // and they would write the address back afterwards.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_bootrace',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    $switched = false;

    Site::retrieved(function (Site $read) use (&$switched, $site): void {
        if ($switched || $read->id !== $site->id) {
            return;
        }

        $switched = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => true, 'page_urls' => false]]),
        ]);
    });

    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-bootrace',
        'page_url' => 'https://shop.test/invite/ABCDEF',
    ])->assertSuccessful();

    expect($switched)->toBeTrue('the race never happened, so this proves nothing');

    $visitor = Visitor::query()->where('anonymous_id', 'anon-bootrace')->firstOrFail();

    expect($visitor->metadata['last_page_url'] ?? null)
        ->toBeNull('bootstrap stored an address after the operator switched them off');
});

test('the realtime presence payload agrees with itself', function (): void {
    // State from one column and the moment from the other produced a payload
    // saying `quiet` and "seen two minutes ago" at once -- and the agent page
    // interpolates that moment into the detail line, so somebody who had
    // emailed read as having just been on the site.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['domain' => 'shop.test']);

    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-emailed',
        'last_seen_at' => now(),
        'last_web_seen_at' => now()->subHours(3),
    ]);

    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    $payload = $conversation->visitorPresencePayload();

    expect($payload['state'])->toBe('quiet')
        ->and($payload['last_seen_at'])->toBe($visitor->last_web_seen_at->toJSON());
});

test('another settings form cannot undo a revocation', function (): void {
    // `settings` is one JSON column, so every form does a read-modify-write of
    // the whole value. A request that loaded the site before a revocation and
    // saved after it put the revoked value back -- an operator switching
    // presence off and then saving the rating prompt could restore it, having
    // done nothing that looks like enabling anything.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_stale',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    // What a concurrent form holds: the settings as they were BEFORE the
    // revocation, about to be written back with its own change applied.
    $staleSettings = $site->settings;

    test()->actingAs($owner)
        ->put(route('dashboard.sites.presence.update', $site), [])
        ->assertRedirect();

    expect(SitePresenceReporting::for($site->fresh())->enabled)->toBeFalse();

    // The other form saves, carrying its stale copy plus its own edit.
    $site->mutateSettings(function (array $settings) use ($staleSettings): array {
        $stale = $staleSettings;
        $stale['rating'] = ['enabled' => true, 'intro' => null];

        // A writer that ignored the locked read would write exactly this.
        return $settings + ['rating' => $stale['rating']];
    });

    expect(SitePresenceReporting::for($site->fresh())->enabled)
        ->toBeFalse('a later settings save restored a revoked presence setting')
        ->and($site->fresh()->settings['rating']['enabled'])->toBeTrue('the other form lost its own change');
});

test('a site that never enabled presence still keeps page addresses', function (): void {
    // Folding `enabled` into the page-address flag looked tidy and reached
    // every install: a default site has presence off and addresses on, so the
    // payload said `page_urls: false`, the widget copied that into the setting
    // it applies to bootstrap and conversation start, and every site that had
    // never touched presence stopped storing page addresses entirely.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_default',
        'settings' => [],
    ]);

    $payload = SitePresenceReporting::for($site)->toPayload();

    expect($payload['reports'])->toBeFalse('presence is off by default')
        ->and($payload['page_urls'])->toBeTrue('a default site was told not to keep page addresses');

    // And the ordinary path still stores one.
    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-default',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-default')->firstOrFail()->metadata['last_page_url'])
        ->toBe('https://shop.test/pricing');
});

test('a conversation started with addresses off keeps none either', function (): void {
    // The widget omits the address, but the endpoint is public: a custom or
    // older client keeps sending one, and `started_page_url` outlives the
    // visitor row it sits beside.
    $site = presenceSite();
    $site->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => false]]])->save();

    reportPresence($site, 'anon-started')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-started')->firstOrFail();
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-started',
        'visitor_token' => $token,
        'page_url' => 'https://shop.test/invite/ABCDEF',
        'subject' => 'Help',
    ])->assertSuccessful();

    $conversation = Conversation::query()->where('visitor_id', $visitor->id)->firstOrFail();

    expect($conversation->metadata['started_page_url'] ?? null)
        ->toBeNull('a client that kept sending an address had it stored anyway');
});

test('a site archived mid-request stops accepting heartbeats', function (): void {
    // Archiving that commits between the resolver reading the site and the
    // locked reread would let the write through -- and broadcastWhen() then
    // suppresses the event, so the row would exist with nothing on any board
    // ever showing it.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_archiving',
        'settings' => ['presence' => ['enabled' => true]],
    ]);

    $archived = false;

    Site::retrieved(function (Site $read) use (&$archived, $site): void {
        if ($archived || $read->id !== $site->id) {
            return;
        }

        $archived = true;

        DB::table('sites')->where('id', $site->id)->update(['archived_at' => now()]);
    });

    reportPresence($site, 'anon-archiving')->assertSuccessful();

    expect($archived)->toBeTrue('the race never happened, so this proves nothing')
        ->and(Visitor::query()->where('anonymous_id', 'anon-archiving')->exists())
        ->toBeFalse('a heartbeat landed on a site that had just been archived');
});

test('an existing visitor never spends the creation quota', function (): void {
    // The substance of the race fix: a request that turns out to be updating
    // rather than creating must not consume a creation slot. Two first
    // heartbeats for one anonymous ID both believe they are creating, and the
    // loser would otherwise spend the budget on an insert that collides.
    //
    // The interleaving itself is not reproducible in-process -- a competing
    // insert made inside the test transaction is rolled back with the savepoint
    // the collision unwinds -- so this asserts the property the fix turns on:
    // an already-resolved row costs nothing.
    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 1);

    $site = presenceSite();

    reportPresence($site, 'anon-first')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-first')->exists())->toBeTrue();

    // The single creation slot is now spent. This visitor already exists, so
    // reporting again must not need one.
    reportPresence($site, 'anon-first')->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-first')->firstOrFail();

    expect($visitor->last_web_seen_at)->not->toBeNull('an existing visitor stopped being refreshed');

    // And a genuinely new one is still refused, so the budget is real.
    reportPresence($site, 'anon-second')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-second')->exists())
        ->toBeFalse('the creation budget was not enforced at all');
});

/**
 * Change the site's domain the first time a query matching $needle runs, so a
 * request that resolved the site before the rename does its write after it.
 * This is the only way to stage the window between resolving a site and taking
 * the lock inside a single-process test.
 */
function presenceRenameSiteDuring(string $needle, Site $site, string $newDomain): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $needle, $site, $newDomain): void {
        if ($fired || ! str_contains($query->sql, $needle)) {
            return;
        }

        $fired = true;

        DB::table('sites')->where('id', $site->id)->update(['domain' => $newDomain]);
    });
}

test('starting a conversation sanitises the page address against the domain in force at the write', function (): void {
    // The site is renamed between this request resolving it and the request
    // taking its lock. The address the visitor is on belongs to the OLD host,
    // which is no longer the site's own -- so it is not ours to keep, and the
    // rule that "an address whose host is not the site's own is not stored"
    // has to be read against the row we locked, not the copy we arrived with.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-renamed-mid-flight']);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    presenceRenameSiteDuring('"public_key" = ?', $site, 'elsewhere.test');

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => $token,
        'subject' => 'Something is broken',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    $visitor->refresh();

    expect($visitor->metadata['last_page_url'] ?? null)->toBeNull();
});

test('bootstrap sanitises the page address against the domain in force at the write', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    presenceRenameSiteDuring('"public_key" = ?', $site, 'elsewhere.test');

    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-bootstrap-renamed',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-bootstrap-renamed')->sole();

    expect($visitor->metadata['last_page_url'] ?? null)->toBeNull();
});

test('a site archived mid-heartbeat tells the widget to stop reporting', function (): void {
    // The write half of this race is covered above: the locked reread refuses
    // the row. The ANSWER was still built from the settings, which say
    // presence is on, so the widget was told `reports: true` by the very
    // request that had just declined to record it.
    //
    // That is not a cosmetic disagreement. The next heartbeat gets a 404 from
    // the resolver, and a 404 is not a configuration payload -- a client
    // following the documented merge-and-ignore rule never learns anything
    // from it, so the last instruction it ever received is "keep reporting".
    // It goes on sending heartbeats, and page addresses with them, to a site
    // whose operator has taken it out of service.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_archiving_answer',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    $archived = false;

    Site::retrieved(function (Site $read) use (&$archived, $site): void {
        if ($archived || $read->id !== $site->id) {
            return;
        }

        $archived = true;

        DB::table('sites')->where('id', $site->id)->update(['archived_at' => now()]);
    });

    $response = reportPresence($site, 'anon-archiving-answer');

    $response->assertSuccessful();

    expect($archived)->toBeTrue('the race never happened, so this proves nothing')
        ->and($response->json('data.reports'))->toBeFalse(
            'the request that refused to record a visitor told the widget to keep sending them'
        );
});

test('an archived site reports nothing whatever its settings say', function (): void {
    $site = Site::factory()->create([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
        'archived_at' => now(),
    ]);

    $reporting = SitePresenceReporting::for($site);

    // `page_urls` is not forced: it answers what a report may CONTAIN, which
    // stays the site's own policy and is read on paths that have nothing to do
    // with presence. `reports` is the one that has to become false.
    expect($reporting->enabled)->toBeFalse()
        ->and($reporting->pageUrls)->toBeTrue();
});

test('the visitor directory empty state describes the sites it is actually showing', function (): void {
    // The directory's own explanation of what it contains: "Wayfindr records
    // somebody when they open the chat, not when they load a page, so this
    // lists people who reached out." True of every site before this feature,
    // and false the moment presence is on -- said on the one screen where an
    // agent has no rows to contradict it.
    //
    // Scoped to what the filter selects, not to the account: an agent looking
    // at one site should be told how THAT site behaves.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $quiet = Site::factory()->for($account)->create([
        'name' => 'Quiet',
        'settings' => ['presence' => ['enabled' => false]],
    ]);
    $watching = Site::factory()->for($account)->create([
        'name' => 'Watching',
        'settings' => ['presence' => ['enabled' => true]],
    ]);

    $browsing = 'also lists people who were only browsing';
    $reachedOut = 'lists people who reached out';

    // No site has presence on: the original sentence, unqualified.
    $watching->forceFill(['settings' => ['presence' => ['enabled' => false]]])->save();

    test()->actingAs($agent)
        ->get(route('dashboard.visitors.index', ['search' => 'nobody-matches-this']))
        ->assertOk()
        ->assertSee($reachedOut, false)
        ->assertDontSee($browsing, false);

    $watching->forceFill(['settings' => ['presence' => ['enabled' => true]]])->save();

    // One of the visible sites is watching, and no filter narrows it.
    test()->actingAs($agent)
        ->get(route('dashboard.visitors.index', ['search' => 'nobody-matches-this']))
        ->assertOk()
        ->assertSee($browsing, false);

    // Filtered to the site that is NOT watching: the presence sentence is not
    // about anything on this screen.
    test()->actingAs($agent)
        ->get(route('dashboard.visitors.index', ['search' => 'nobody-matches-this', 'site' => $quiet->id]))
        ->assertOk()
        ->assertSee($reachedOut, false)
        ->assertDontSee($browsing, false);

    // Filtered to the one that is.
    test()->actingAs($agent)
        ->get(route('dashboard.visitors.index', ['search' => 'nobody-matches-this', 'site' => $watching->id]))
        ->assertOk()
        ->assertSee($browsing, false);
});

test('the widget learns about presence without making contact', function (): void {
    // The endpoint that answers this is the one a page load is allowed to ask.
    // Bootstrap cannot be: it creates or touches a visitor row and marks them
    // as having made contact, so asking IT whether to watch people who have not
    // made contact answers the question by destroying it.
    $site = presenceSite();

    $response = test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertOk();

    expect($response->json('data.presence.reports'))->toBeTrue()
        ->and($response->json('data.presence.every'))
        ->toBe(SitePresenceReporting::HEARTBEAT_SECONDS);

    // And nothing was recorded by asking.
    expect(Visitor::query()->count())->toBe(0, 'a configuration read created a visitor');
});

test('a site that has not opted in says so before anybody reports', function (): void {
    $site = presenceSite(enabled: false);

    test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertOk()
        ->assertJsonPath('data.presence.reports', false);
});

test('reading site configuration does not spend the budget for starting a chat', function (): void {
    // Presence made this a per-PAGE-LOAD read rather than a per-panel-opening
    // one. Sharing bootstrap's bucket meant passive browsing from one office
    // could exhaust it, and the visitor who then tried to start a conversation
    // got a 429 for somebody else's page views.
    config()->set('wayfindr.widget_rate_limits.bootstrap_per_minute', 2);
    config()->set('wayfindr.widget_rate_limits.config_per_minute', 100);

    $site = presenceSite();

    foreach (range(1, 6) as $i) {
        test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
            ->assertOk();
    }

    // Bootstrap has spent nothing.
    test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-after-config-reads',
    ])->assertSuccessful();
});

test('the configuration read is still bounded', function (): void {
    config()->set('wayfindr.widget_rate_limits.config_per_minute', 3);

    $site = presenceSite();

    foreach (range(1, 3) as $i) {
        test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))->assertOk();
    }

    test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertStatus(429);
});

test('the presence caption agrees with the state beside it', function (): void {
    // Computed from the cross-channel timestamp, the caption disagreed with the
    // state it sits next to: `quiet`, "2 minutes ago" -- the state describing
    // the website and the words describing an email.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['domain' => 'shop.test']);

    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-caption',
        'last_seen_at' => now(),
        'last_web_seen_at' => now()->subHours(4),
    ]);

    $payload = Conversation::factory()->for($site)->for($visitor)->create()->visitorPresencePayload();

    expect($payload['state'])->toBe('quiet')
        ->and($payload['last_seen_label'])->toBe($visitor->last_web_seen_at->diffForHumans());
});

test('bootstrap answers with the settings it actually wrote against', function (): void {
    // The write reads the locked row while the response was built from the copy
    // the request arrived with, so a revocation landing in between produced an
    // answer telling the widget to keep reporting against a setting the write
    // had already refused.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'public_key' => 'site_public_answer',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ]);

    $revoked = false;

    Site::retrieved(function (Site $read) use (&$revoked, $site): void {
        if ($revoked || $read->id !== $site->id) {
            return;
        }

        $revoked = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => false]]),
        ]);
    });

    $response = test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-answer',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    expect($revoked)->toBeTrue('the race never happened, so this proves nothing')
        ->and($response->json('data.site.presence.reports'))
        ->toBeFalse('the answer told the widget to report against a setting the write refused');
});

test('the widget is told the retention this install applies', function (): void {
    // The disclosure names a number of days. Baking 30 into the copy made an
    // operator who shortened the window tell every visitor something untrue.
    config()->set('wayfindr.presence.retention_days', 7);

    $site = presenceSite();

    test()->getJson(route('widget.appearance', ['site_public_key' => $site->public_key]))
        ->assertOk()
        ->assertJsonPath('data.presence.retention_days', 7);

    // Clamped the same way the pruner clamps it, so the notice cannot promise
    // longer than the sweep will allow.
    config()->set('wayfindr.presence.retention_days', 400);

    expect(SitePresenceReporting::retentionDays())
        ->toBe(PrunePresenceVisitorsCommand::MAXIMUM_DAYS);
});

test('the settings page quotes the same retention as the visitor notice', function (): void {
    // An operator reading "30 days" on the page where they configure this,
    // while their install deletes after seven, is being told something untrue
    // by the surface that exists to tell them the truth.
    config()->set('wayfindr.presence.retention_days', 7);

    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create(['domain' => 'shop.test']);

    test()->actingAs($owner)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('deleted 7 days after they were last seen', false)
        ->assertDontSee('deleted 30 days after they were last seen', false);
});

test('the settings help does not promise page reporting that is switched off', function (): void {
    // An operator who enables presence but leaves addresses off was told the
    // widget reports which page they are on -- contradicting the checkbox
    // directly beside it and the payload the widget actually sends.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => false]],
    ]);

    test()->actingAs($owner)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertDontSee('and which page they are on', false);

    $site->forceFill(['settings' => ['presence' => ['enabled' => true, 'page_urls' => true]]])->save();

    test()->actingAs($owner)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('and which page they are on', false);
});
