<?php

use App\Console\Commands\PrunePresenceVisitorsCommand;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\Visitors\VisitorPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
