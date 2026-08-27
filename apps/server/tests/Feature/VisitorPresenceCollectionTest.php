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
    ]);

    $gone = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-long-gone',
        'created_at' => now()->subDays(90),
        'last_seen_at' => now()->subDays(PrunePresenceVisitorsCommand::MAXIMUM_DAYS + 1),
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
    ]);

    config(['wayfindr.presence.retention_days' => 3650]);

    $this->artisan('wayfindr:prune-presence-visitors')->assertExitCode(0);

    expect(Visitor::query()->whereKey($visitor->id)->exists())->toBeFalse(
        'a configured window longer than the maximum was honoured'
    );
});
