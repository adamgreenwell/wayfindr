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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

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

    $mailer = Visitor::factory()->for($f['site'])->create([
        'anonymous_id' => null,
        'email' => 'mailer@elsewhere.test',
        'last_seen_at' => now(),
    ]);

    // Cleared after creating: the factory makes a widget visitor and fills the
    // website sighting in from `last_seen_at`, and cannot tell an explicit null
    // from an absent one. An email correspondent is contact with no browser
    // behind it, which is precisely what this test is about.
    $mailer->forceFill(['last_web_seen_at' => null])->save();

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

test('the broadcast row carries what the rendered row shows', function (): void {
    // The board's own query loads the count with withCount(); a broadcast
    // carries one visitor resolved somewhere else. Letting it default to zero
    // meant the first heartbeat silently rewrote "3 conversations" as "0" and
    // dropped the profile link -- within 45 seconds of page load, looking like
    // the page had lost them.
    $f = boardFixture();

    $visitor = presentVisitor($f['site'], 'anon-contacted', ['presence_only' => false, 'name' => 'Dana']);
    Conversation::factory()->for($f['site'])->for($visitor)->create();
    Conversation::factory()->for($f['site'])->for($visitor)->create();

    // Resolved fresh, exactly as the event does rather than through the board.
    $broadcast = LiveVisitorBoard::row(Visitor::query()->findOrFail($visitor->id));
    $rendered = LiveVisitorBoard::for($f['site'])->firstOrFail();

    expect($broadcast['conversations_count'])->toBe(2)
        ->and($broadcast['conversations_count'])->toBe($rendered['conversations_count'])
        ->and($broadcast['profile_url'])->toBe($rendered['profile_url'])
        ->and($broadcast['profile_url'])->not->toBeNull();
});

test('a stranger has no profile to link to', function (): void {
    $f = boardFixture();

    presentVisitor($f['site'], 'anon-stranger');

    expect(LiveVisitorBoard::for($f['site'])->firstOrFail()['profile_url'])->toBeNull();
});

test('a site that stopped watching shows nobody, not everybody', function (): void {
    // Contacted visitors keep reporting through bootstrap and message fetches,
    // so an unguarded query put a nonzero count above a paragraph explaining
    // that the board stays empty by design.
    $f = boardFixture(enabled: false);

    presentVisitor($f['site'], 'anon-still-here', ['presence_only' => false]);

    $response = test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('stays empty by design', false);

    expect($response->viewData('visitors'))->toHaveCount(0);
});

test('the board recovers from a failed subscription', function (): void {
    // A failed authorization leaves the socket healthy and unsubscribed, so no
    // close event fires and the reconnect that only the close handler schedules
    // never runs. The board then sits connected to nothing for the rest of the
    // session, looking exactly like a quiet afternoon.
    //
    // Read from the template rather than a rendered page: the behaviour lives
    // in a socket callback no server-side test can drive, and the template is
    // the artifact under test.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $authorize = Str::before(
        Str::after($source, 'function authorize(activeSocket, socketId) {'),
        'function handleSocketMessage',
    );

    // The slice is proven before anything is asserted about it -- `.catch(`
    // appears several times in this script, and a slice that grabbed the wrong
    // one would assert over the wrong code and pass.
    test()->assertStringContainsString(
        'pusher:subscribe',
        $authorize,
        'the slice is not the authorize function',
    );

    expect($authorize)->toContain('scheduleReconnect();')
        ->and($authorize)->toContain('activeSocket.close();');
});

test('a refreshed row moves to the newest-first position', function (): void {
    // The server orders by the latest sighting. Replacing a row in place meant
    // a visitor already on the board kept their original position for ever --
    // the ordering frozen at page load while the timestamps changed underneath.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $apply = Str::before(Str::after($source, 'function applyVisitor(visitor) {'), 'function dropDeparted');

    test()->assertStringContainsString(
        'rows.insertBefore(fresh, rows.firstChild)',
        $apply,
        'the slice is not applyVisitor',
    );

    expect($apply)->toContain('existing.remove()')
        ->and($apply)->not->toContain('existing.replaceWith');
});

test('an archived site does not open a socket it cannot subscribe to', function (): void {
    // SitePresenceChannel queries servable() and refuses every authorization.
    // Handing the page a config anyway meant the socket opened, the auth
    // failed, the reconnect fired, and the agent watched "Reconnecting to live
    // updates" for as long as the tab stayed open -- retrying something
    // refused by design.
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'board-key');
    config()->set('broadcasting.connections.reverb.options.client_host', 'realtime.shop.test');
    config()->set('broadcasting.connections.reverb.options.port', 8080);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $f = boardFixture();
    presentVisitor($f['site'], 'anon-archived-board');

    // Live first, so the difference is the archiving and not the config.
    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('pusher:subscribe', false);

    $f['site']->forceFill(['archived_at' => now()])->save();

    $response = test()->actingAs($f['agent'])->get(route('dashboard.sites.live', $f['site']->fresh()));

    if ($response->status() === 200) {
        $response->assertDontSee('pusher:subscribe', false);
    } else {
        // Refusing the page outright is also a correct answer.
        expect($response->status())->toBeIn([403, 404]);
    }
});

test('the board resyncs whenever it subscribes', function (): void {
    // Reverb does not replay, so anything broadcast between the server
    // rendering the page and the subscription existing is gone -- and after a
    // reconnect that gap is however long the socket was down.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $authorize = Str::before(
        Str::after($source, 'function authorize(activeSocket, socketId) {'),
        'function handleSocketMessage',
    );

    test()->assertStringContainsString(
        'pusher:subscribe',
        $authorize,
        'the slice is not the authorize function',
    );

    // The resync hangs off the SUBSCRIPTION CONFIRMATION, not the
    // authorization: the auth returning only means the subscribe frame was
    // sent, so a snapshot taken then still has a window on the far side of it.
    $handler = Str::before(
        Str::after($source, 'function handleSocketMessage(message) {'),
        'function connect()',
    );

    test()->assertStringContainsString(
        'pusher_internal:subscription_succeeded',
        $handler,
        'the slice is not the socket message handler',
    );

    expect($handler)->toContain('resyncBoard();')
        ->and($source)->toContain('function resyncBoard()')
        ->and($authorize)->not->toContain('resyncBoard();');
});

test('an open board does not keep showing revoked visitors', function (): void {
    // Another operator revoking presence deletes the rows this board is
    // showing, page addresses and all -- and nothing is broadcast for a
    // deletion, so an open board would display them until each aged out.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    test()->assertStringContainsString(
        'function resyncBoard()',
        $source,
        'there is no resync to schedule',
    );

    expect($source)->toContain('window.setInterval(resyncBoard, 60000);');
});

test('a resync does not overwrite events that arrived during it', function (): void {
    // Events landing while the snapshot is being fetched are NEWER than it.
    // Replacing the rows wholesale overwrites them with older state -- and
    // that is the likely ordering rather than the unlucky one, since a
    // broadcast beats the page render it raced.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $resync = Str::before(Str::after($source, 'function resyncBoard() {'), 'var socketScheme');

    test()->assertStringContainsString(
        'replaceChildren',
        $resync,
        'the slice is not resyncBoard',
    );

    expect($resync)->toContain('resyncBuffer = pending;')
        ->and($resync)->toContain('pending.forEach(applyVisitor);');

    $handler = Str::before(
        Str::after($source, 'function handleSocketMessage(message) {'),
        'function connect()',
    );

    expect($handler)->toContain('resyncBuffer.push(visitor);');
});

test('an overtaken resync does not replace a newer one', function (): void {
    // The subscribe resync and the minute timer overlap. If the older request
    // lands last it replaces the newer board with staler markup -- and replays
    // a buffer that stopped collecting when the second call took over.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $resync = Str::before(Str::after($source, 'function resyncBoard() {'), 'var socketScheme');

    test()->assertStringContainsString(
        'replaceChildren',
        $resync,
        'the slice is not resyncBoard',
    );

    expect($resync)->toContain('var seq = ++resyncSequence;')
        ->and($resync)->toContain('if (seq !== resyncSequence) {');
});

test('the count is not capped by the display limit', function (): void {
    // The list stops at 200 so one page stays readable. Telling an agent "200"
    // when more than that are on the site is the one number here they would
    // have taken at face value.
    $f = boardFixture();

    // Past the cap, or the two queries agree and this proves nothing.
    $total = LiveVisitorBoard::DISPLAY_LIMIT + 12;
    $rows = [];

    foreach (range(1, $total) as $i) {
        $rows[] = [
            'site_id' => $f['site']->id,
            'anonymous_id' => 'anon-many-'.$i,
            'presence_only' => true,
            'last_seen_at' => now(),
            'last_web_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('visitors')->insert($rows);

    expect(LiveVisitorBoard::for($f['site']))->toHaveCount(LiveVisitorBoard::DISPLAY_LIMIT)
        ->and(LiveVisitorBoard::countFor($f['site']))->toBe($total);

    // The rendered count comes from the uncapped query, not from the rows.
    $response = test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk();

    expect($response->viewData('presentCount'))->toBe($total);

    // And somebody who left is in neither.
    presentVisitor($f['site'], 'anon-departed', [
        'last_web_seen_at' => now()->subMinutes(LiveVisitorBoard::PRESENT_MINUTES + 1),
    ]);

    expect(LiveVisitorBoard::countFor($f['site']))->toBe($total, 'the count includes somebody who left');
});

test('a live refresh does not replace the total with the row count', function (): void {
    // Past the display limit the two numbers differ, and overwriting the total
    // with the row count on the first heartbeat put the capped figure back on a
    // page that had just rendered the real one.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $refresh = Str::before(Str::after($source, 'function refreshCount(total) {'), 'function durationFrom');

    test()->assertStringContainsString(
        'presentTotal',
        $refresh,
        'the slice is not refreshCount',
    );

    // The rendered total is only replaced by a number the SERVER supplied.
    expect($refresh)->toContain("if (typeof total === 'number') {")
        ->and($source)->toContain("var freshCount = parsed.querySelector('[data-live-count]');");
});

test('a callback from a replaced socket cannot open another', function (): void {
    // An authorization fetch for a socket that has since been replaced can
    // still resolve, and its failure path would schedule a reconnect while the
    // current socket is healthy -- opening one nobody closes, and another
    // after that.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $connect = Str::before(Str::after($source, 'function connect() {'), 'window.addEventListener');

    test()->assertStringContainsString(
        'new WebSocket(socketUrl)',
        $connect,
        'the slice is not connect()',
    );

    expect($connect)->toContain('var generation = ++socketGeneration;')
        ->and($source)->toContain('activeSocket.wayfindrGeneration !== socketGeneration');
});

test('an unreachable broadcaster does not stop presence being recorded', function (): void {
    // The event is ShouldBroadcastNow, so it is dispatched synchronously.
    // Inside the transaction a Reverb that is merely unreachable threw, rolled
    // the visitor save back and failed the heartbeat -- realtime being down
    // would have stopped presence being COLLECTED, which is the wrong blast
    // radius by a wide margin.
    $f = boardFixture();

    Event::listen(VisitorPresenceUpdated::class, function (): void {
        throw new RuntimeException('reverb is down');
    });

    test()->postJson(route('widget.presence'), [
        'site_public_key' => $f['site']->public_key,
        'anonymous_id' => 'anon-broadcast-down',
        'page_url' => 'https://shop.test/pricing',
    ])->assertSuccessful();

    $visitor = Visitor::query()->where('anonymous_id', 'anon-broadcast-down')->first();

    expect($visitor)->not->toBeNull('a broadcast failure rolled back the visitor')
        ->and($visitor->metadata['last_page_url'])->toBe('https://shop.test/pricing');
});
