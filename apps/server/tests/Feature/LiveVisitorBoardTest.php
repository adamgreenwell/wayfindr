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
use Illuminate\Testing\TestResponse;

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

function boardHeartbeat(Site $site, string $anonymousId): TestResponse
{
    return test()->postJson(route('widget.presence'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
    ]);
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

test('a heartbeat refused by the creation quota announces nothing', function (): void {
    // The quota returns the unsaved model it declined to write. Announcing it
    // hands LiveVisitorBoard::row() a visitor with no id and no `presence_only`
    // -- unset reads as contacted, so it builds a profile route for a null key
    // and throws. announce() catches and reports, so the heartbeat still
    // succeeds and the damage is silent: one logged exception per refused
    // report, at exactly the moment there are a great many of them.
    $f = boardFixture();

    config()->set('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 1);

    Event::fake([VisitorPresenceUpdated::class]);

    boardHeartbeat($f['site'], 'anon-quota-first')->assertSuccessful();
    boardHeartbeat($f['site'], 'anon-quota-second')->assertSuccessful();

    expect(Visitor::query()->where('anonymous_id', 'anon-quota-second')->exists())
        ->toBeFalse('the quota was not actually exhausted, so this proves nothing');

    // One announcement, for the one visitor that exists.
    Event::assertDispatchedTimes(VisitorPresenceUpdated::class, 1);

    Event::assertDispatched(
        VisitorPresenceUpdated::class,
        fn (VisitorPresenceUpdated $event): bool => $event->visitor->exists
    );
});

test('a close event from a replaced socket cannot open another', function (): void {
    // The generation guard reached the authorization callback and not the close
    // handler, and the close handler is the one that always runs.
    //
    // A failed authorization closes its own socket and schedules a reconnect in
    // the same breath. `close` is delivered asynchronously, so by the time it
    // arrives the reconnect has already fired and a healthy socket is in
    // service -- and the unguarded handler then schedules another reconnect,
    // opening a third socket beside it. Every failed authorization leaves one
    // more, each with its own subscription, and the board counts every
    // arrival once per live socket.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $closeHandler = Str::before(
        Str::after($source, "socket.addEventListener('close', function () {"),
        "socket.addEventListener('error'",
    );

    test()->assertStringContainsString(
        'scheduleReconnect();',
        $closeHandler,
        'the slice is not the close handler',
    );

    test()->assertStringContainsString(
        'generation !== socketGeneration',
        $closeHandler,
        'a close event from a socket nobody is using still schedules a reconnect',
    );
});

test('a heartbeat refused after a revocation announces nothing', function (): void {
    // The `exists` guard on announce() reads the model in memory, and this row
    // existed when the request resolved it. Presence is then switched off --
    // which deletes the presence-only visitors it collected -- and the locked
    // re-read inside stamp() correctly refuses to write.
    //
    // It refused by returning that same model, whose `exists` is still true
    // because nothing told it otherwise. So the heartbeat broadcast a visitor
    // that had just been deleted, and an open board put them back on the
    // screen -- name, page address and all -- seconds after the operator
    // revoked the collection that produced them.
    $f = boardFixture();
    $site = $f['site'];

    $visitor = presentVisitor($site, 'anon-revoked-mid-flight');

    $visitor->forceFill(['presence_only' => true])->save();

    $revoked = false;

    // Between resolve() reading the visitor and stamp() taking the site lock.
    // Hooked on the VISITOR read: the site is read by the resolver before
    // record() is even called, so a hook there fires too early -- resolve()
    // then finds nothing and hands back a model that never existed, which the
    // guard already catches. The case this is about needs a row that WAS
    // found and has since gone.
    Visitor::retrieved(function (Visitor $read) use (&$revoked, $site): void {
        if ($revoked || $read->site_id !== $site->id) {
            return;
        }

        $revoked = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => false]]),
        ]);
        DB::table('visitors')->where('site_id', $site->id)->where('presence_only', true)->delete();
    });

    Event::fake([VisitorPresenceUpdated::class]);

    boardHeartbeat($site, 'anon-revoked-mid-flight')->assertSuccessful();

    expect($revoked)->toBeTrue('the race never happened, so this proves nothing')
        ->and(Visitor::query()->where('anonymous_id', 'anon-revoked-mid-flight')->exists())
        ->toBeFalse('the revocation did not delete the row, so this proves nothing');

    Event::assertNotDispatched(VisitorPresenceUpdated::class);
});

test('the board is told the row limit it has to respect', function (): void {
    // The browser cannot infer this. It needs the limit to know whether its own
    // row count is the whole population -- and to stop inserting past it.
    $f = boardFixture();

    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'board-key');
    config()->set('broadcasting.connections.reverb.options.host', 'reverb.internal');
    config()->set('broadcasting.connections.reverb.options.port', 8080);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertSee('"displayLimit":'.LiveVisitorBoard::DISPLAY_LIMIT, false);
});

test('the count follows the rows when no row is hidden', function (): void {
    // `presentTotal` is the server's uncapped figure and the rows are capped at
    // 200, so a departure was never allowed to lower it -- the rows below the
    // cap were not on the page to leave it.
    //
    // That reasoning does not hold on a board showing everybody. There, the
    // rows ARE the population, and refusing to lower the total left an empty
    // table under "3 on the site now" for the minute until the next resync.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $refresh = Str::before(Str::after($source, 'function refreshCount(total) {'), 'function ');

    test()->assertStringContainsString(
        'presentTotal = present;',
        $refresh,
        'the slice is not refreshCount()',
    );

    test()->assertStringContainsString(
        'boardIsWhole()',
        $refresh,
        'a departure cannot lower the count even when every visitor is on the page',
    );

    // And the test of wholeness is the server's limit, not a guess.
    test()->assertStringContainsString(
        'presentTotal <= displayLimit',
        $source,
        'the board decides it is showing everybody without reference to the limit',
    );
});

test('a realtime arrival is counted and the board stays within its limit', function (): void {
    // Two halves of one thing. The insert has to count the arrival even when
    // there is no room to show them, and it has to evict a row so the board
    // does not grow past the bound the server renders to -- by every distinct
    // visitor who reports in between resyncs, on a busy site.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $insert = Str::before(
        Str::after($source, 'appended to the bottom would put the person who just'),
        'function dropDeparted',
    );

    test()->assertStringContainsString(
        'rows.insertBefore(fresh, rows.firstChild);',
        $insert,
        'the slice is not the insert branch',
    );

    // Counted, but only where a missing row means a new person. On a capped
    // board somebody outside the rendered 200 is already in the total and their
    // next heartbeat also arrives with no row to match -- so an unguarded
    // increment climbed away from the real population every 45 seconds, once
    // per capped visitor, until the resync pulled it back.
    test()->assertStringContainsString(
        'if (boardIsWhole()) {'.'
'.'                            presentTotal = presentTotal + 1;',
        $insert,
        'the arrival count is not gated on the board showing everybody',
    );

    test()->assertStringContainsString(
        'rendered.length > displayLimit',
        $insert,
        'realtime inserts can grow the board past the limit the server renders to',
    );
});

test('a resync applies its total before replaying what it missed', function (): void {
    // A visitor who arrives between the snapshot being queried and its response
    // landing is in the buffer and not in the snapshot. Replaying them adds a
    // row and counts them -- and then applying the snapshot's older total took
    // it away again, leaving the table holding somebody the heading did not.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $applyTotal = mb_strpos($source, 'refreshCount(freshCount ? Number(freshCount.textContent) || 0 : undefined);');
    $replayBuffer = mb_strpos($source, 'pending.forEach(applyVisitor);');

    expect($applyTotal)->not->toBeFalse('the resync no longer applies a snapshot total')
        ->and($replayBuffer)->not->toBeFalse('the resync no longer replays its buffer')
        ->and($applyTotal)->toBeLessThan(
            $replayBuffer,
            'the snapshot total is applied after the buffer, so it discards buffered arrivals'
        );
});

test('an event that arrives late does not overwrite a newer row', function (): void {
    // One visitor with two tabs writes twice. The database serialises those,
    // but each broadcast happens after its own commit and they can reach Reverb
    // in the other order -- so the board replaced a row it had just updated
    // with the older event's contents: a stale page address, a stale contact
    // state, a sighting time that moves backwards. It stayed that way until the
    // next heartbeat or the minute's resync.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $apply = Str::before(Str::after($source, 'function applyVisitor(visitor) {'), 'function dropDeparted');

    test()->assertStringContainsString(
        'rows.insertBefore(fresh, rows.firstChild);',
        $apply,
        'the slice is not applyVisitor()',
    );

    test()->assertStringContainsString(
        'isNewerThanRow(existing, visitor)',
        $apply,
        'an event about a visitor already on the board is applied without checking its age',
    );

    // Ordered by what the SERVER stamped, not by arrival.
    $compare = Str::before(Str::after($source, 'function isNewerThanRow(row, visitor) {'), '}');

    test()->assertStringContainsString(
        'row.dataset.lastSeen',
        $compare,
        'the comparison does not read the sighting time the server rendered',
    );

    test()->assertStringContainsString(
        'visitor.last_web_seen_at',
        $compare,
        'the comparison does not read the sighting time the event carries',
    );

    // A value that cannot be read must not silently drop a real update.
    test()->assertStringContainsString(
        'isNaN(had) || isNaN(has)',
        $compare,
        'an unreadable timestamp discards the update instead of applying it',
    );
});

test('the board renders a count that matches the rows beside it', function (): void {
    // The rows and the total came from two separate queries. A visitor
    // committing between them is in the count and not in the table -- and it
    // does not end there: during a resync the socket event for that visitor is
    // buffered, so the browser applies this count and then replays them as a
    // new arrival, adding them a second time on a board that shows everybody.
    //
    // Below the cap the rows ARE the population, so there is no need to ask
    // twice and no window to be inconsistent in.
    $f = boardFixture();
    $site = $f['site'];

    presentVisitor($site, 'anon-already-here');

    $arrived = false;

    // A heartbeat landing between the two reads.
    DB::listen(function ($query) use (&$arrived, $site): void {
        if ($arrived || ! str_contains($query->sql, 'from "visitors"')) {
            return;
        }

        $arrived = true;

        presentVisitor($site, 'anon-arrived-mid-request');
    });

    $response = test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $site))
        ->assertOk();

    expect($arrived)->toBeTrue('nothing arrived mid-request, so this proves nothing');

    $html = $response->getContent();

    preg_match('/data-live-count[^>]*>(\d+)</', $html, $count);
    $rendered = preg_match_all('/data-visitor-id="/', $html);

    expect($count)->not->toBeEmpty('the board did not render a count');

    expect((int) $count[1])->toBe(
        $rendered,
        'the heading counts somebody the table does not show, on a board with room for everybody'
    );
});

test('a resync that finds presence switched off clears what it was showing', function (): void {
    // The resync exists so a board corrects itself. Its one early return was
    // "no rows element in the response", which is exactly the shape the page
    // takes when another operator has just switched presence OFF -- so the
    // response that carried the revocation was the one the board ignored.
    //
    // The visitors and their page addresses then stayed on screen until the
    // local expiry aged each row out, up to fifteen minutes after the operator
    // revoked the collection that produced them.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $resync = Str::before(Str::after($source, "var fresh = parsed.querySelector('[data-live-rows]');"), 'function ');

    test()->assertStringContainsString(
        'resyncSequence',
        $resync,
        'the slice is not the resync response handler',
    );

    test()->assertStringNotContainsString(
        "if (!fresh) {\n                            return;\n                        }",
        $resync,
        'a response with no rows is still ignored rather than read as a revocation',
    );

    test()->assertStringContainsString(
        'clearBoard()',
        $resync,
        'a resync that finds presence off does not clear the board',
    );

    // And the page really does drop that element when presence is off, which
    // is what makes its absence mean something.
    $f = boardFixture(false);
    presentVisitor($f['site'], 'anon-was-here');

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk()
        ->assertDontSee('data-live-rows', false);
});

test('an agent who may use the board can find it', function (): void {
    // The board authorises any agent who can view the site -- the route and the
    // broadcast channel both say so. The only link to it sat inside the
    // admins-only half of the presence section, and a repo-wide search finds no
    // other way in, so the people the board was built for could not reach it.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'settings' => ['presence' => ['enabled' => true]],
    ]);

    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $link = route('dashboard.sites.live', $site);

    // The agent can use it, so the agent can see the way to it.
    test()->actingAs($agent)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee($link, false);

    test()->actingAs($agent)
        ->get($link)
        ->assertOk();

    // And it is still there for the admin, who also sees the settings form.
    test()->actingAs($admin)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee($link, false);

    // Off means no board and no link, for either of them.
    $site->forceFill(['settings' => ['presence' => ['enabled' => false]]])->save();

    test()->actingAs($agent)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertDontSee($link, false);
});

test('the board hides page addresses the moment their collection is revoked', function (): void {
    // Turning page addresses off commits the setting first and then sweeps the
    // stored rows, which is the safe order -- the sweep would otherwise hold a
    // row lock per visitor across the whole table while an operator waited on a
    // form post. The cost is a window: between the commit and the sweep
    // reaching a row, that row still holds an address.
    //
    // Another agent loading or resyncing the board in that window saw
    // addresses whose collection had been revoked, and a sweep that fails or
    // is interrupted leaves them there indefinitely. The board answers from the
    // policy in force rather than trusting the cleanup to have finished.
    $f = boardFixture();
    $site = $f['site'];

    $site->forceFill([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => false]],
    ])->save();

    // A row the sweep has not reached.
    presentVisitor($site, 'anon-not-swept-yet', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $site))
        ->assertOk()
        ->assertDontSee('shop.test/pricing', false);

    // The broadcast payload answers the same way; a board already open must not
    // be handed one either.
    $visitor = Visitor::query()->where('anonymous_id', 'anon-not-swept-yet')->sole();

    $payload = (new VisitorPresenceUpdated($site->fresh(), $visitor))->broadcastWith();

    expect($payload['visitor']['page_url'])->toBeNull(
        'a realtime update carried an address whose collection had been revoked'
    );

    // With the policy on again, the same row does show it -- so the assertions
    // above are about the policy and not about the address being absent.
    $site->forceFill([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ])->save();

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $site))
        ->assertOk()
        ->assertSee('shop.test/pricing', false);
});

test('the board answers the keepalive the server sends it', function (): void {
    // Reverb sends an application-level `pusher:ping` on a quiet connection
    // (`REVERB_APP_PING_INTERVAL`, sixty seconds by default) and closes the
    // socket if no `pusher:pong` comes back. These are protocol MESSAGES, not
    // WebSocket control frames, so the browser does not answer them -- the
    // bundled Pusher client does, and this board does not use it.
    //
    // The board hid the consequence rather than avoiding it: the socket is
    // retired, the close handler reconnects, and the subscription resyncs. It
    // looks like a working board with a gap in it every minute or so.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $handler = Str::before(Str::after($source, 'function handleSocketMessage(message) {'), 'function ');

    test()->assertStringContainsString(
        "event.event === 'pusher:connection_established'",
        $handler,
        'the slice is not the message handler',
    );

    test()->assertStringContainsString(
        "event.event === 'pusher:ping'",
        $handler,
        'the board never answers the keepalive, so the server retires its socket',
    );

    test()->assertStringContainsString(
        'pusher:pong',
        $handler,
        'the board recognises the keepalive without answering it',
    );
});

test('a broadcast asks the database what the policy is, not the model it was handed', function (): void {
    // The event carries the site the REQUEST resolved. A revocation committing
    // between the heartbeat's own commit and this dispatch leaves that model
    // describing a policy that no longer exists -- and the visitor model
    // carries the address from before the sweep -- so the board was handed the
    // revoked address and kept it until its next resync.
    //
    // The same staleness applied to the archived check beside it, which is why
    // one re-read serves both.
    $f = boardFixture();
    $site = $f['site'];

    $site->forceFill([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ])->save();

    $visitor = presentVisitor($site, 'anon-mid-revocation', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    // The operator revokes, underneath the models this event is holding.
    DB::table('sites')->where('id', $site->id)->update([
        'settings' => json_encode(['presence' => ['enabled' => true, 'page_urls' => false]]),
    ]);

    $event = new VisitorPresenceUpdated($site, $visitor);

    expect($event->broadcastWith()['visitor']['page_url'])->toBeNull(
        'the broadcast read its policy from a model resolved before the revocation'
    );

    // And archiving, read the same way.
    DB::table('sites')->where('id', $site->id)->update(['archived_at' => now()]);

    expect((new VisitorPresenceUpdated($site, $visitor))->broadcastWhen())->toBeFalse(
        'a site archived after the request resolved it was still broadcast for'
    );
});

test('nothing is broadcast for a site that has stopped collecting', function (): void {
    // A heartbeat that commits just before `updatePresence()` takes the site
    // lock is a legitimate write: `stamp()` saw reporting on under its own
    // lock, wrote the row, and returned it. The revocation then commits and
    // deletes the presence-only visitors it collected -- and `announce()`, one
    // statement later, broadcasts a row that no longer exists.
    //
    // `broadcastWhen()` refused archived sites and nothing else, so an open
    // board put that visitor back on screen, page address and all, seconds
    // after the operator revoked the collection that produced them.
    $f = boardFixture();
    $site = $f['site'];

    $visitor = presentVisitor($site, 'anon-after-revocation', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    // Still collecting: the event is for a board to hear.
    expect((new VisitorPresenceUpdated($site, $visitor))->broadcastWhen())->toBeTrue(
        'a site that is collecting does not broadcast, so this proves nothing'
    );

    DB::table('sites')->where('id', $site->id)->update([
        'settings' => json_encode(['presence' => ['enabled' => false]]),
    ]);

    expect((new VisitorPresenceUpdated($site, $visitor))->broadcastWhen())->toBeFalse(
        'a site that has stopped collecting still broadcast a visitor to open boards'
    );
});

test('a cleared board refuses the events already on their way to it', function (): void {
    // `clearBoard()` empties the table and closes the socket, and closing does
    // not cancel a message the browser has already queued. An update dispatched
    // before the revocation therefore arrives after the rows are gone and puts
    // the visitor -- and possibly their page address -- straight back on
    // screen, where they stay until the next resync a minute later.
    //
    // `pageClosing` stopped the reconnect and nothing else.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $handler = Str::before(Str::after($source, 'function handleSocketMessage(message) {'), 'function ');

    test()->assertStringContainsString(
        'event.event === config.eventName',
        $handler,
        'the slice is not the message handler',
    );

    test()->assertStringContainsString(
        'boardCleared',
        $handler,
        'a message queued before the revocation is still applied after it',
    );

    // And the flag is actually set where the board is cleared.
    $clear = Str::before(Str::after($source, 'function clearBoard('), 'function ');

    test()->assertStringContainsString(
        'boardCleared = true;',
        $clear,
        'clearing the board does not record that it was cleared',
    );
});

test('the board reads the policy as it is at render, not at route binding', function (): void {
    // The site here is the model the ROUTE resolved, on the way in. Another
    // operator revoking page addresses between that binding and this query
    // leaves it describing a policy that has already been replaced -- and the
    // sweep is still walking the rows, so the addresses are still there to be
    // rendered.
    //
    // The same window the broadcast closed by re-reading; this is the other
    // half of it, on the page.
    $f = boardFixture();
    $site = $f['site'];

    $site->forceFill([
        'settings' => ['presence' => ['enabled' => true, 'page_urls' => true]],
    ])->save();

    presentVisitor($site, 'anon-render-race', [
        'metadata' => ['last_page_url' => 'https://shop.test/pricing'],
    ]);

    $revoked = false;

    // Committed after the route has resolved the site and before the board
    // queries its rows.
    Site::retrieved(function (Site $read) use (&$revoked, $site): void {
        if ($revoked || $read->id !== $site->id) {
            return;
        }

        $revoked = true;

        DB::table('sites')->where('id', $site->id)->update([
            'settings' => json_encode(['presence' => ['enabled' => true, 'page_urls' => false]]),
        ]);
    });

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $site))
        ->assertOk()
        ->assertDontSee('shop.test/pricing', false);

    expect($revoked)->toBeTrue('the race never happened, so this proves nothing');
});

test('a board whose agent has lost access to the site shuts itself down', function (): void {
    // Channel authorisation is checked when the socket SUBSCRIBES and never
    // again. An operator removing an agent from a site therefore does nothing
    // to a board that agent already has open: the subscription stays live and
    // keeps delivering visitor identities and page addresses, for as long as
    // the tab is open.
    //
    // The resync is the one thing that would notice -- it gets a 404 from the
    // site authorisation -- and it treated every non-OK response as a
    // transient failure, on the reasonable-sounding grounds that a board
    // missing somebody is what it was a moment ago. That is right for a 500
    // and wrong for a 404: one is the server having a bad moment, the other is
    // the answer.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $resync = Str::before(
        Str::after($source, 'function resyncBoard() {'),
        '// Holds events that arrive while a snapshot',
    );

    test()->assertStringContainsString(
        'resyncSequence',
        $resync,
        'the slice is not resyncBoard()',
    );

    test()->assertStringContainsString(
        'response.status === 403 || response.status === 404',
        $resync,
        'a terminal authorisation answer is treated as a transient failure',
    );

    test()->assertStringContainsString(
        'clearBoard(',
        $resync,
        'losing access to the site does not shut the board down',
    );

    // UNCONDITIONALLY. The sequence guard exists to stop an older snapshot's
    // ROWS replacing newer ones, and a denial is not a snapshot -- it is a fact
    // about the viewer, and it does not go stale. Two overlapping resyncs where
    // the newer one hangs and the older returns 404 would otherwise discard the
    // only answer either of them produced, and the subscription lives on.
    $denial = Str::before(
        Str::after($resync, 'if (response.status === 403 || response.status === 404) {'),
        'return null;',
    );

    // An OVERTAKEN denial is neither obeyed nor dropped.
    //
    // Dropping it is the original hole: if the request that overtook it never
    // answers, nothing ever acts on the denial and the subscription lives on.
    // Obeying it blindly is the opposite mistake -- access may have been
    // restored, and the newer answer is the one that knows -- so a stale 404
    // would shut down a board that is now perfectly entitled to be open.
    //
    // It asks again instead, and the answer to that is definitive.
    test()->assertStringContainsString(
        'seq === resyncSequence',
        $denial,
        'a denial acts without checking whether a newer answer exists',
    );

    test()->assertStringContainsString(
        'denialRecheckPending',
        $denial,
        'an overtaken denial is dropped or obeyed rather than re-asked',
    );

    // And the route really does answer 404 for an agent who cannot see it,
    // which is what makes that status mean something.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'settings' => ['presence' => ['enabled' => true]],
    ]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $assigned = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    // A site with named support agents is visible only to them, so assigning
    // somebody else is what removing this agent looks like.
    $site->supportAgents()->sync([$assigned->id]);

    test()->actingAs($agent)
        ->get(route('dashboard.sites.live', $site))
        ->assertNotFound();
});

test('a board page rendered after a revocation renders the revoked page', function (): void {
    // The revocation path the resync depends on is "the response has no
    // [data-live-rows] element". That only holds if the response is built from
    // the CURRENT settings -- and this action read them from the model the
    // route resolved, so a revocation committing between the two rendered a
    // full board, rows element and all.
    //
    // Which means the in-flight resync that fetched this page saw a normal
    // board, did not call clearBoard(), and carried on showing visitors until
    // some later resync happened to land after the binding.
    $f = boardFixture();
    $site = $f['site'];

    presentVisitor($site, 'anon-render-after-revocation');

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

    test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $site))
        ->assertOk()
        ->assertDontSee('data-live-rows', false);

    expect($revoked)->toBeTrue('the race never happened, so this proves nothing');
});

test('a board cleared by a revocation comes back when the revocation is undone', function (): void {
    // Clearing latches, deliberately: a message already queued for the closed
    // socket must not repopulate a board the operator has just emptied. But the
    // latch outlived the reason for it. An operator who switched presence off
    // and back on left every open board a zombie -- rows redrawn once a minute
    // by the snapshot, every socket message ignored, the socket closed and
    // barred from reconnecting, and the status line still saying presence is
    // off for a site that is collecting again.
    //
    // A snapshot that HAS rows is the revocation being undone, and it is the
    // same signal read the other way: the absence of that element is what
    // cleared the board in the first place.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $resync = Str::before(
        Str::after($source, 'function resyncBoard() {'),
        '// Holds events that arrive while a snapshot',
    );

    test()->assertStringContainsString(
        'restoreBoard()',
        $resync,
        'a snapshot that finds the board enabled again leaves it latched shut',
    );

    $restore = Str::before(Str::after($source, 'function restoreBoard() {'), "\n                }");

    // Every latch clearBoard() set has to come off, or the board comes back
    // half alive in a way no test of one flag would catch.
    test()->assertStringContainsString('boardCleared = false;', $restore, 'the board still ignores socket messages');
    test()->assertStringContainsString('pageClosing = false;', $restore, 'the board still refuses to reconnect');
    test()->assertStringContainsString('connect();', $restore, 'the board never opens a socket again');
});

test('the board measures against the clock that stamped its rows', function (): void {
    // Every timestamp here is server-stamped, and two things the board does
    // with them -- how long somebody has been on the site, and whether they
    // have gone -- compared them against the WORKSTATION's clock.
    //
    // An agent's machine running more than `presentMinutes` ahead therefore
    // read every fresh heartbeat as already expired: the resync restored the
    // rows and the fifteen-second sweep removed them again, so a busy site
    // showed an empty board for most of every minute, with nothing on screen
    // suggesting the clock was the problem. A laptop resumed from suspend does
    // this.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    // Neither reading may use the raw browser clock.
    $sweep = Str::before(Str::after($source, 'function dropDeparted() {'), 'function ');
    $duration = Str::before(Str::after($source, 'function durationFrom(startedAt) {'), 'function ');

    test()->assertStringContainsString('cutoff', $sweep, 'the slice is not dropDeparted()');
    test()->assertStringNotContainsString('Date.now()', $sweep, 'the expiry sweep uses the agent workstation clock');
    test()->assertStringNotContainsString('Date.now()', $duration, 'the time-on-site uses the agent workstation clock');
    test()->assertStringContainsString('serverNow()', $sweep, 'the sweep does not use the server clock');
    test()->assertStringContainsString('serverNow()', $duration, 'the duration does not use the server clock');

    // And the page really carries a server timestamp for it to adopt.
    $f = boardFixture();
    presentVisitor($f['site'], 'anon-clock');

    $response = test()->actingAs($f['agent'])
        ->get(route('dashboard.sites.live', $f['site']))
        ->assertOk();

    expect($response->getContent())->toMatch('/data-server-now="[^"]+"/');

    // Refreshed by each snapshot rather than fixed at page load, so a clock
    // that drifts during a long session is corrected.
    $resync = Str::before(
        Str::after($source, 'function resyncBoard() {'),
        '// Holds events that arrive while a snapshot',
    );

    test()->assertStringContainsString(
        'adoptServerClock(',
        $resync,
        'the board never re-reads the server clock after page load',
    );
});

test('the board keeps its own connection alive', function (): void {
    // Answering the server's ping is only half the protocol. The client is
    // expected to speak on an otherwise silent connection -- the server
    // declares an `activity_timeout` on connect and disconnects a client that
    // says nothing for that long.
    //
    // And whatever sits between the browser and Reverb is counting too. On a
    // default nginx the websocket location inherits `proxy_read_timeout 60s`,
    // so a silent socket is torn down at sixty seconds with no close frame.
    // Measured on staging: an idle socket died at exactly 60s (code 1006)
    // while one sending a ping every 25s was still open at 113s. Reverb's own
    // ping is on the same sixty-second interval, so it never arrived first --
    // which is why answering it changed nothing on its own.
    $source = file_get_contents(resource_path('views/agent/sites/live.blade.php'));

    $keepalive = Str::before(Str::after($source, 'function startKeepalive(activeSocket, activityTimeoutSeconds) {'), "\n                }");

    test()->assertStringContainsString(
        "event: 'pusher:ping'",
        $keepalive,
        'the board never sends a keepalive of its own',
    );

    // Derived from what the SERVER declared, not a number picked here.
    test()->assertStringContainsString(
        'activityTimeoutSeconds',
        $keepalive,
        'the keepalive interval ignores the timeout the server declared',
    );

    // Started from the connection payload, on the socket that carried it.
    $handler = Str::before(Str::after($source, 'function handleSocketMessage(message) {'), "\n                function ");

    test()->assertStringContainsString(
        'startKeepalive(message.target, established.activity_timeout)',
        $handler,
        'the keepalive is not started from the connection payload',
    );

    // And stopped everywhere the board stops caring about the socket.
    foreach (['clearBoard(reason) {', "socket.addEventListener('close'"] as $site) {
        $slice = Str::before(Str::after($source, $site), '}');

        test()->assertStringContainsString(
            'stopKeepalive();',
            $slice,
            'the keepalive outlives the socket it belongs to, at: '.$site,
        );
    }
});
