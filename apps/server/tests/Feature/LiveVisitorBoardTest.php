<?php

use App\Broadcasting\SitePresenceChannel;
use App\Enums\AccountRole;
use App\Events\VisitorPresenceUpdated;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Visitors\LiveVisitorBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * The board answers "who is on the site right now", which the visitor directory
 * cannot: that list is every channel at once, ordered by any contact of any
 * kind, and it describes people rather than a moment.
 */
function boardFixture(bool $enabled = true): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $site = Site::factory()->for($account)->create([
        'domain' => 'shop.test',
        'settings' => ['presence' => ['enabled' => $enabled]],
    ]);

    return ['account' => $account, 'agent' => $agent, 'site' => $site];
}

function presentVisitor(Site $site, string $anonymousId, array $attributes = []): Visitor
{
    return Visitor::factory()->for($site)->create(array_merge([
        'anonymous_id' => $anonymousId,
        'last_web_seen_at' => now(),
        'last_seen_at' => now(),
        'current_visit_started_at' => now()->subMinutes(3),
        'presence_only' => true,
    ], $attributes));
}

test('somebody browsing right now is on the board', function (): void {
    $f = boardFixture();

    presentVisitor($f['site'], 'anon-here', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    $board = LiveVisitorBoard::for($f['site']);

    expect($board)->toHaveCount(1)
        ->and($board->first()['page_url'])->toBe('https://shop.test/pricing')
        ->and($board->first()['made_contact'])->toBeFalse()
        ->and($board->first()['state'])->toBe('active');
});

test('an email correspondent is not on the website', function (): void {
    // The whole reason the board reads its own column. Somebody who emailed an
    // hour ago is contact and belongs in the directory; they are not here, and
    // an agent acting on that goes looking for a browser that does not exist.
    $f = boardFixture();

    Visitor::factory()->for($f['site'])->create([
        'anonymous_id' => null,
        'email' => 'mailer@elsewhere.test',
        'last_seen_at' => now(),
        'last_web_seen_at' => null,
    ]);

    expect(LiveVisitorBoard::for($f['site']))->toHaveCount(0);
});

test('somebody who left drops off', function (): void {
    $f = boardFixture();

    presentVisitor($f['site'], 'anon-gone', [
        'last_web_seen_at' => now()->subMinutes(LiveVisitorBoard::PRESENT_MINUTES + 1),
    ]);

    expect(LiveVisitorBoard::for($f['site']))->toHaveCount(0);
});

test('an agent testing their own site does not appear on it', function (): void {
    // Otherwise an agent watches themselves browse, which the visitor directory
    // already refuses for the same reason.
    $f = boardFixture();

    presentVisitor($f['site'], 'tester-site-'.$f['site']->id);

    expect(LiveVisitorBoard::for($f['site']))->toHaveCount(0);
});

test('a visitor who has been in touch is marked as such', function (): void {
    // "A stranger is reading your pricing page" and "a customer you know is
    // back" are different situations and an agent chooses differently on each.
    $f = boardFixture();

    $visitor = presentVisitor($f['site'], 'anon-known', ['presence_only' => false, 'name' => 'Dana']);
    Conversation::factory()->for($f['site'])->for($visitor)->create();

    $row = LiveVisitorBoard::for($f['site'])->first();

    expect($row['made_contact'])->toBeTrue()
        ->and($row['conversations_count'])->toBe(1)
        ->and($row['name'])->toBe('Dana');
});

test('the board belongs to one site', function (): void {
    $f = boardFixture();
    $other = Site::factory()->for($f['account'])->create(['domain' => 'other.test']);

    presentVisitor($f['site'], 'anon-ours');
    presentVisitor($other, 'anon-theirs');

    expect(LiveVisitorBoard::for($f['site']))->toHaveCount(1)
        ->and(LiveVisitorBoard::for($f['site'])->first()['id'])
        ->not->toBe(Visitor::query()->where('anonymous_id', 'anon-theirs')->firstOrFail()->id);
});

test('an agent can open the board for their own site', function (): void {
    $f = boardFixture();

    presentVisitor($f['site'], 'anon-visible', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('On the site now')
        ->assertSee('https://shop.test/pricing');
});

test('a site that does not watch says so instead of showing an empty board', function (): void {
    // An operator looking at nothing deserves to know whether nobody is here or
    // nothing is being recorded.
    $f = boardFixture(enabled: false);

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('stays empty by design', false);
});

test('an agent from another account cannot open the board', function (): void {
    $f = boardFixture();
    $stranger = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);

    test()->actingAs($stranger)
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertNotFound();
});

test('only an agent who can see the site may watch its board', function (): void {
    $f = boardFixture();
    $channel = new SitePresenceChannel;

    $stranger = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);

    expect($channel->join($f['agent'], $f['site']->id))->toBeTrue()
        ->and($channel->join($stranger, $f['site']->id))->toBeFalse()
        ->and($channel->join(null, $f['site']->id))->toBeFalse();
});

test('a deactivated agent loses the board with everything else', function (): void {
    $f = boardFixture();
    $channel = new SitePresenceChannel;

    $f['agent']->forceFill(['deactivated_at' => now()])->save();

    expect($channel->join($f['agent']->fresh(), $f['site']->id))->toBeFalse();
});

test('an archived site has no board to subscribe to', function (): void {
    // A subscription outlives the check that created it, so refusing to create
    // one is a different guarantee from refusing to broadcast -- and both are
    // needed.
    $f = boardFixture();
    $channel = new SitePresenceChannel;

    $f['site']->forceFill(['archived_at' => now()])->save();

    expect($channel->join($f['agent'], $f['site']->id))->toBeFalse();
});

test('a channel name that is not a site id is refused', function (): void {
    // The channel name comes from the client. `sites.4abc.presence` reaching a
    // query as `4` is how a typo becomes somebody else's site.
    $f = boardFixture();
    $channel = new SitePresenceChannel;

    expect($channel->join($f['agent'], $f['site']->id.'abc'))->toBeFalse()
        ->and($channel->join($f['agent'], 'not-a-number'))->toBeFalse()
        ->and($channel->join($f['agent'], '999999'))->toBeFalse();
});

test('an archived site broadcasts nothing even to a live subscription', function (): void {
    $f = boardFixture();
    $visitor = presentVisitor($f['site'], 'anon-archived');

    $event = new VisitorPresenceUpdated($f['site'], $visitor);

    expect($event->broadcastWhen())->toBeTrue();

    $f['site']->forceFill(['archived_at' => now()])->save();

    expect((new VisitorPresenceUpdated($f['site']->fresh(), $visitor))->broadcastWhen())
        ->toBeFalse();
});

test('a heartbeat announces the visitor to the board', function (): void {
    Event::fake([VisitorPresenceUpdated::class]);

    $f = boardFixture();

    test()->postJson(route('widget.presence'), [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => 'anon-announced',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    Event::assertDispatched(
        VisitorPresenceUpdated::class,
        fn (VisitorPresenceUpdated $event): bool => $event->visitor->anonymous_id === 'anon-announced'
            && $event->site->is($f['site']),
    );
});

test('the board subscribes when the install runs realtime', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'board-key');
    config()->set('broadcasting.connections.reverb.options.host', 'reverb.internal');
    config()->set('broadcasting.connections.reverb.options.client_host', 'realtime.shop.test');
    config()->set('broadcasting.connections.reverb.options.port', 8080);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $f = boardFixture();
    presentVisitor($f['site'], 'anon-live');

    $response = test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk();

    // The CLIENT host, not the internal one a browser cannot resolve.
    $response->assertSee('realtime.shop.test', false)
        ->assertDontSee('reverb.internal', false)
        ->assertSee('private-sites.'.$f['site']->id.'.presence', false);
});

test('an install without realtime says the list is a snapshot', function (): void {
    // Better than a board that looks live and is not.
    config()->set('broadcasting.default', 'log');

    $f = boardFixture();
    presentVisitor($f['site'], 'anon-static');

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('does not run realtime updates', false)
        ->assertDontSee('pusher:subscribe', false);
});

test('realtime is refused when the browser cannot be told where to connect', function (): void {
    // A half-configured Reverb produces a socket URL to nowhere, and the page
    // would sit there saying "Updating live" while nothing arrived.
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'board-key');
    config()->set('broadcasting.connections.reverb.options.host', null);
    config()->set('broadcasting.connections.reverb.options.client_host', null);
    config()->set('broadcasting.connections.reverb.options.port', 8080);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $f = boardFixture();

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertDontSee('pusher:subscribe', false);
});
