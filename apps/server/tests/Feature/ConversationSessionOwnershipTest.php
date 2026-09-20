<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\ContinuedVisitorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * A conversation belongs to the SESSION that opened it, not to the browser
 * identity that happens to name it.
 *
 * An anonymous id is shown to agents and travels in the widget's own requests,
 * so it is knowable; a token naming that visitor can be minted by anyone holding
 * it and a site's public key, because bootstrap is unauthenticated by design.
 * Matching the visitor was therefore never a check -- it was a lookup wearing
 * one.
 *
 * Every assertion here goes through the product's own endpoints. The rest of the
 * suite sets ownership with `conversationOwnedBySession()`, which force-fills the
 * column to keep pre-existing tests about their own subjects -- so those tests
 * cannot see whether the WRITE works at all. Two separate bugs shipped into this
 * branch behind exactly that blind spot: a closure that did not capture the value
 * it inserted, and a column missing from the model's fillable list. Both stored
 * null, which reads as owned by nobody.
 */
function sessionOwnershipWorld(): array
{
    $site = Site::factory()->for(Account::factory())->create([
        'public_key' => 'site_public_own',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-own']);

    return [$site, $visitor];
}

/**
 * A token the way a browser gets one: from bootstrap, presenting nothing.
 *
 * Presenting nothing is the point -- bootstrap continues the session a presented
 * token proves, so passing the previous token would return the SAME session and
 * the "another session" tests below would silently assert nothing.
 */
function ownershipToken($test, Site $site, string $anonymousId = 'anon-own'): string
{
    // `withToken()` persists on the test case, so an earlier request's token
    // would still be attached here -- and bootstrap CONTINUES the session a
    // presented token proves, so this would hand back the same session it was
    // called to get a different one from. The guard in the test below caught
    // exactly that.
    return $test->withoutToken()->postJson(route('widget.bootstrap'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
    ])->assertSuccessful()->json('data.visitor.token');
}

/**
 * The token's own payload, read the way the server wrote it.
 *
 * Deliberately not through `VisitorSessionToken`'s readers: what these tests
 * assert is what the token CONTAINS, and asking the class under test to report
 * on itself would pass just as happily if both halves agreed on the wrong value.
 */
function ownershipPayload(string $token): array
{
    return json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A token the way the server minted them before sessions were identified.
 *
 * Hand-built rather than mocked, because the upgrade case IS a payload with no
 * `session_id` key, encrypted with this install's own app key -- a token the
 * server itself issued and will still accept. Nothing in the class under test
 * can produce one any more, which is exactly why the upgrade path needs it.
 */
function ownershipPreSessionToken(Site $site, Visitor $visitor, ?CarbonImmutable $sessionStartedAt = null): string
{
    $sessionStartedAt ??= CarbonImmutable::now();

    return Crypt::encryptString(json_encode([
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'anonymous_id' => (string) $visitor->anonymous_id,
        'issued_at' => $sessionStartedAt->toJSON(),
        'session_started_at' => $sessionStartedAt->toJSON(),
        // No `session_id`, and no `ttl_minutes`: both postdate this token.
    ], JSON_THROW_ON_ERROR));
}

function ownershipOpen($test, Site $site, string $token, string $anonymousId = 'anon-own')
{
    return $test->withToken($token)->postJson(route('conversations.store'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
    ]);
}

function ownershipPost($test, Site $site, string $token, string $supportCode, string $body = 'Hello?', string $anonymousId = 'anon-own')
{
    return $test->withToken($token)->postJson('/api/conversations/'.$supportCode.'/messages', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
        'body' => $body,
    ]);
}

function ownershipRead($test, Site $site, string $token, string $supportCode, string $anonymousId = 'anon-own')
{
    return $test->withToken($token)->getJson('/api/conversations/'.$supportCode.'/messages?'.http_build_query([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
    ]));
}

test('a conversation records the session that opened it', function (): void {
    [$site] = sessionOwnershipWorld();
    $token = ownershipToken($this, $site);

    $supportCode = ownershipOpen($this, $site, $token)->assertCreated()->json('data.support_code');

    $conversation = Conversation::query()->where('support_code', $supportCode)->sole();
    $sessionId = ownershipPayload($token)['session_id'] ?? null;

    // Named individually. "It is not null" would pass for the sentinel, which
    // would hand this row to the time rule -- reachable by any session that
    // began before it, which is the property this whole change removes.
    expect($sessionId)->toBeString()->not->toBe('')
        ->and($conversation->owner_session_id)->toBe($sessionId)
        ->and($conversation->owner_session_id)->not->toBe(Conversation::LEGACY_OWNER_SESSION);
});

test('the session that opened a conversation can post to it and read it back', function (): void {
    [$site] = sessionOwnershipWorld();
    $token = ownershipToken($this, $site);
    $supportCode = ownershipOpen($this, $site, $token)->assertCreated()->json('data.support_code');

    ownershipPost($this, $site, $token, $supportCode, 'Which plan fits us?')->assertCreated();

    ownershipRead($this, $site, $token, $supportCode)
        ->assertOk()
        ->assertJsonPath('data.messages.0.body', 'Which plan fits us?');
});

test('another session naming the same visitor cannot reach the conversation', function (): void {
    [$site] = sessionOwnershipWorld();
    $owner = ownershipToken($this, $site);
    $supportCode = ownershipOpen($this, $site, $owner)->assertCreated()->json('data.support_code');

    // A second bootstrap with the same anonymous id: exactly what an attacker
    // holding a visitor's browser identity and the site's public key can mint,
    // unauthenticated, at will.
    $stranger = ownershipToken($this, $site);

    expect(ownershipPayload($stranger)['session_id'])
        ->not->toBe(ownershipPayload($owner)['session_id'], 'Two bootstraps must name two sessions, or this test asserts nothing.');

    // Refused as NOT FOUND, matching a support code that does not exist: telling
    // these apart would answer "does this code belong to this visitor" for a
    // caller who cannot reach it either way.
    ownershipPost($this, $site, $stranger, $supportCode)->assertNotFound();
    ownershipRead($this, $site, $stranger, $supportCode)->assertNotFound();

    // The gate lives in the shared resolver, so it applies to every endpoint
    // that acts on a conversation rather than to the two checked above.
    $this->withToken($stranger)->postJson('/api/conversations/'.$supportCode.'/typing', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-own',
        'is_typing' => true,
    ])->assertNotFound();

    expect(Conversation::query()->where('support_code', $supportCode)->sole()->messages()->count())->toBe(0);
});

test('a rotated token still owns the conversation its session opened', function (): void {
    [$site] = sessionOwnershipWorld();
    $token = ownershipToken($this, $site);
    $supportCode = ownershipOpen($this, $site, $token)->assertCreated()->json('data.support_code');

    $rotated = $this->withToken($token)->postJson(route('widget.session.refresh'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-own',
    ])->assertSuccessful()->json('data.visitor.token');

    expect($rotated)->not->toBe($token)
        ->and(ownershipPayload($rotated)['session_id'])->toBe(ownershipPayload($token)['session_id']);

    ownershipPost($this, $site, $rotated, $supportCode)->assertCreated();
});

test('a token minted before sessions were identified cannot open a conversation', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // Refused rather than recorded. The alternatives are both worse: the
    // sentinel would hand the row to the time rule, and null would lock the
    // visitor out of a conversation they had just opened.
    ownershipOpen($this, $site, ownershipPreSessionToken($site, $visitor))
        ->assertStatus(401);

    expect(Conversation::query()->count())->toBe(0);
});

test('bootstrapping with a pre-session token adopts a session without restarting it', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    $before = CarbonImmutable::now()->subHours(3);
    $old = ownershipPreSessionToken($site, $visitor, $before);

    $upgraded = $this->withToken($old)->postJson(route('widget.bootstrap'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-own',
    ])->assertSuccessful()->json('data.visitor.token');

    $payload = ownershipPayload($upgraded);

    // The id is MINTED, because the old token names no session. Carrying the
    // empty value forward instead is the whole failure: `??=` does not fire on
    // '', so the successor would name no session either, and its holder could
    // open no conversation for as long as the browser kept rotating it.
    expect($payload['session_id'] ?? null)->toBeString()->not->toBe('');

    // The START is carried forward, unchanged. It is what makes the visitor's
    // conversation from before the upgrade reachable, and restarting it here
    // would refuse them their own open conversation.
    expect(CarbonImmutable::parse($payload['session_started_at'])->equalTo($before))->toBeTrue(
        'The continued session start must survive the upgrade, or a pre-upgrade conversation becomes unreachable.'
    );

    ownershipOpen($this, $site, $upgraded)->assertCreated();
});

test('refreshing a pre-session token adopts a session without restarting it', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    $before = CarbonImmutable::now()->subHours(3);

    // The OTHER upgrade path, and the one the value object cannot cover:
    // `refresh()` reads the id straight out of the payload and hands it to the
    // mint, so an empty one has to be caught at the mint itself. A widget with a
    // lifetime configured rotates on a timer without ever bootstrapping again,
    // so this is how most upgraded browsers arrive.
    $rotated = $this->withToken(ownershipPreSessionToken($site, $visitor, $before))
        ->postJson(route('widget.session.refresh'), [
            'site_public_key' => $site->public_key,
            'anonymous_id' => 'anon-own',
        ])->assertSuccessful()->json('data.visitor.token');

    $payload = ownershipPayload($rotated);

    expect($payload['session_id'] ?? null)->toBeString()->not->toBe('');
    expect(CarbonImmutable::parse($payload['session_started_at'])->equalTo($before))->toBeTrue(
        'Rotation must not restart the session it is continuing.'
    );

    ownershipOpen($this, $site, $rotated)->assertCreated();
});

test('a continued session reports no id rather than an empty one', function (): void {
    // Stated as a contract on the value object, not inferred from a request.
    // Its callers ask "is there an id to carry forward" with `??`, which answers
    // that correctly for null and wrongly for ''. The mint hardens against the
    // empty string too -- these are two jobs, reporting and enforcing, and the
    // suite should fail if either stops doing its own.
    $startedAt = CarbonImmutable::now();

    expect((new ContinuedVisitorSession($startedAt, ''))->sessionId)->toBeNull()
        ->and((new ContinuedVisitorSession($startedAt, null))->sessionId)->toBeNull()
        ->and((new ContinuedVisitorSession($startedAt, 'sess-1'))->sessionId)->toBe('sess-1');
});

test('an expired token cannot trade itself for a live one naming the same session', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);

    [$site] = sessionOwnershipWorld();
    $token = ownershipToken($this, $site);
    $supportCode = ownershipOpen($this, $site, $token)->assertCreated()->json('data.support_code');

    $this->travel(31)->minutes();

    // Bootstrap is deliberately tolerant -- it is reachable with no token at
    // all, and the presence funnel needs it to stay that way -- so it answers
    // rather than refusing. What it must not do is CONTINUE the dead token's
    // session: that would hand back a live credential naming it, and the
    // lifetime this install configured would bound nothing. Anyone still
    // holding the expired token, which is exactly who a lifetime exists to
    // retire, could recover the session's conversations indefinitely.
    $traded = $this->withToken($token)->postJson(route('widget.bootstrap'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-own',
    ])->assertSuccessful()->json('data.visitor.token');

    expect(ownershipPayload($traded)['session_id'])
        ->not->toBe(ownershipPayload($token)['session_id'], 'An expired token must not carry its session into its replacement.');

    ownershipPost($this, $site, $traded, $supportCode)->assertNotFound();
    ownershipRead($this, $site, $traded, $supportCode)->assertNotFound();

    // The replacement is a working token for a NEW session, not a refusal.
    ownershipOpen($this, $site, $traded)->assertCreated();
});

test('a conversation from before this control is reachable by a session that began before it', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    $token = ownershipToken($this, $site);

    $this->travel(5)->minutes();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-LEG']);
    $conversation->forceFill(['owner_session_id' => Conversation::LEGACY_OWNER_SESSION])->save();

    ownershipPost($this, $site, $token, 'WF-OWN-LEG')->assertCreated();
});

test('a conversation from before this control is not reachable by a session that began after it', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-LEG']);
    $conversation->forceFill(['owner_session_id' => Conversation::LEGACY_OWNER_SESSION])->save();

    // A session that starts after the row exists cannot be the one that opened
    // it, and cannot claim an earlier start: a start is only carried forward
    // from a token that already proved it.
    $this->travel(5)->minutes();

    ownershipPost($this, $site, ownershipToken($this, $site), 'WF-OWN-LEG')->assertNotFound();
});

test('a visitor mid-conversation when the install upgrades keeps their conversation', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // The whole upgrade, in order. A browser in a session that began before the
    // upgrade, holding a conversation the migration marked as predating the
    // control, opens the panel once afterwards.
    $sessionBegan = CarbonImmutable::now();
    $old = ownershipPreSessionToken($site, $visitor, $sessionBegan);

    $this->travel(2)->minutes();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-MID']);
    $conversation->forceFill(['owner_session_id' => Conversation::LEGACY_OWNER_SESSION])->save();

    $this->travel(2)->minutes();
    $upgraded = $this->withToken($old)->postJson(route('widget.bootstrap'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-own',
    ])->assertSuccessful()->json('data.visitor.token');

    // Reachable because the upgraded token still says when the session began --
    // four minutes ago, before the conversation existed. A token that restarted
    // the clock would be refused here, and the visitor would watch an open
    // conversation become unreachable for no reason they could see.
    ownershipPost($this, $site, $upgraded, 'WF-OWN-MID', 'Still here?')->assertCreated();
    ownershipRead($this, $site, $upgraded, 'WF-OWN-MID')
        ->assertOk()
        ->assertJsonPath('data.messages.0.body', 'Still here?');
});

test('the post-activation sweep claims what a previous release left with no session', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // The shape the OLD release writes during a zero-downtime upgrade: it does
    // not know the column exists, so a conversation it opens after the
    // migration's sweep has passed carries a null -- and a null grants nothing,
    // so the visitor who just started it would be told it does not exist.
    $left = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-OLD']);
    expect($left->owner_session_id)->toBeNull();

    // And a row that legitimately has no widget session, created by the NEW
    // release alongside it. The sweep must tell these apart: claiming this one
    // as legacy would hand it to the time rule, reachable by any earlier session
    // of that visitor, when the answer is nobody.
    $none = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-API']);
    $none->forceFill(['owner_session_id' => Conversation::NO_OWNER_SESSION])->save();

    $this->artisan('wayfindr:claim-legacy-conversation-sessions')
        ->expectsOutputToContain('Claimed 1 conversation')
        ->assertSuccessful();

    expect($left->refresh()->owner_session_id)->toBe(Conversation::LEGACY_OWNER_SESSION)
        ->and($none->refresh()->owner_session_id)->toBe(Conversation::NO_OWNER_SESSION);

    // Idempotent, which is what lets it be scheduled daily.
    $this->artisan('wayfindr:claim-legacy-conversation-sessions')
        ->expectsOutputToContain('No conversation needed')
        ->assertSuccessful();
});

test('a conversation opened outside any widget session is reachable by nobody', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // Email intake and the public API both record this. A session that began
    // before the row would pass the legacy time rule, so the distinction between
    // "predates the control" and "no widget session" has to be a real one.
    $token = ownershipToken($this, $site);

    $this->travel(5)->minutes();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-MAIL']);
    $conversation->forceFill(['owner_session_id' => Conversation::NO_OWNER_SESSION])->save();

    ownershipPost($this, $site, $token, 'WF-OWN-MAIL')->assertNotFound();
    ownershipRead($this, $site, $token, 'WF-OWN-MAIL')->assertNotFound();
});

test('an already-open pre-upgrade widget keeps the conversation it was in', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // A panel open across the upgrade holds a token minted before sessions were
    // identified and does not re-bootstrap on its own. An integration built on
    // `createClient()` never does at all -- it is handed a token and has no
    // refresh timer -- so refusing it here would lock it out until the host page
    // reloaded.
    $sessionBegan = CarbonImmutable::now();
    $old = ownershipPreSessionToken($site, $visitor, $sessionBegan);

    $this->travel(2)->minutes();
    $legacy = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-OPEN']);
    $legacy->forceFill(['owner_session_id' => Conversation::LEGACY_OWNER_SESSION])->save();

    ownershipPost($this, $site, $old, 'WF-OWN-OPEN', 'Still here?')->assertCreated();

    // It concedes nothing the sentinel does not. A row owned by a real session
    // still requires that session, and a token naming none can never match one.
    $owned = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-HELD']);
    conversationOwnedBySession($owned, ownershipToken($this, $site));

    ownershipPost($this, $site, $old, 'WF-OWN-HELD')->assertNotFound();
});

test('a conversation stored with no session is reachable by nobody', function (): void {
    [$site, $visitor] = sessionOwnershipWorld();

    // The shape a write path produces when it fails to record a session. It is
    // a bug rather than a state to tolerate, so it grants nothing -- including
    // to a session that began before it, which the sentinel would allow.
    $token = ownershipToken($this, $site);

    $this->travel(5)->minutes();
    Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OWN-NUL']);

    expect(Conversation::query()->where('support_code', 'WF-OWN-NUL')->sole()->owner_session_id)->toBeNull();

    ownershipPost($this, $site, $token, 'WF-OWN-NUL')->assertNotFound();
});
