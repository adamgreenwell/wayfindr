<?php

// A visitor session token had no lifetime: one minted 400 days earlier still
// verified. `WAYFINDR_VISITOR_SESSION_TTL_MINUTES` advertised an expiry to the
// widget without the server refusing anything. These pin the enforcement, and
// one of them pins the shape of it that is easy to get catastrophically wrong.

use App\Models\Account;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function lifetimeFixture(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_life']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-life']);

    return compact('account', 'site', 'visitor');
}

function lifetimeToken(array $f): string
{
    return app(VisitorSessionToken::class)->issue($f['site'], $f['visitor'], 'anon-life');
}

function lifetimeGet(string $token): TestResponse
{
    return test()->getJson('/api/widget/appearance?'.http_build_query([
        'site_public_key' => 'site_public_life',
    ]), ['Authorization' => 'Bearer '.$token]);
}

test('with no lifetime configured an old token still verifies, so nothing changes for an existing install', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 0]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    test()->travel(400)->days();

    expect(app(VisitorSessionToken::class)->expiresAt($token))->toBeNull();
})->group('lifetime');

test('a token past the configured lifetime is refused', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    test()->travel(31)->minutes();

    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    try {
        $method->invoke($svc, $request, $f['site'], 'anon-life');
        $status = 200;
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(401, 'A token past its configured lifetime must be refused, and with 401 -- the widget treats 403 as terminal and would not try to recover.');
})->group('lifetime');

test('a token inside the configured lifetime still verifies', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);

    test()->travel(29)->minutes();

    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    expect($method->invoke($svc, $request, $f['site'], 'anon-life')->id)->toBe($f['visitor']->id);
})->group('lifetime');

// The one that matters most. An absolute cap measured from the session start
// looks equivalent and is not: `continuingSessionStartedAt()` carries a
// presented token's session start forward with no age check, so a recovering
// bootstrap would mint a replacement that is ALREADY expired, forever.
test('bootstrapping after an expired session yields a token that actually works', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    $f = lifetimeFixture();
    $expired = lifetimeToken($f);

    test()->travel(31)->minutes();

    // What the widget does on a rejected token: bootstrap, presenting the token
    // it still holds.
    $response = test()->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_life',
        'anonymous_id' => 'anon-life',
        'visitor_token' => $expired,
    ])->assertOk();

    $fresh = $response->json('data.visitor.token');

    expect($fresh)->toBeString()->not->toBeEmpty();

    // Assert it is ACCEPTED, not that it looks unexpired. `expiresAt()` reads
    // `issued_at`, so it cannot see a lifetime measured from the session start
    // -- a replacement token would look fine here and still be refused on use,
    // which is exactly the bootstrap loop being guarded against.
    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$fresh,
    ]);

    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    try {
        $resolved = $method->invoke($svc, $request, $f['site'], 'anon-life');
    } catch (HttpException $e) {
        $resolved = null;
    }

    expect($resolved?->id)
        ->toBe($f['visitor']->id, 'The token minted to recover an expired session is refused too. That is what measuring the lifetime from the session start rather than the issue time does: bootstrap carries the old session start forward, so every replacement is born expired and the visitor loops forever -- through page reloads, because the widget presents the same stored token again.');
})->group('lifetime');

test('a token minted before issue times were recorded is not refused', function (): void {
    // Expiring every one of these the moment an operator sets the value would
    // log out every open session at once -- the stranding the rollout ordering
    // exists to avoid. They age out as soon as the widget next rotates.
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    $f = lifetimeFixture();

    // Built the way `encode()` does, minus the field that did not exist yet.
    $legacy = Crypt::encryptString(json_encode([
        'site_id' => $f['site']->id,
        'visitor_id' => $f['visitor']->id,
        'anonymous_id' => 'anon-life',
        // no issued_at, as tokens predating the field have
    ], JSON_THROW_ON_ERROR));

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$legacy,
    ]);

    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    expect($method->invoke($svc, $request, $f['site'], 'anon-life')->id)
        ->toBe($f['visitor']->id, 'A token with no recorded issue time was treated as infinitely old, which would log out every session an install had open the moment a lifetime was configured.');
})->group('lifetime');

// The lifetime is a property of the TOKEN, not of the config at the moment it is
// checked. Reading the config at verification time applies a change
// retroactively, which breaks the promise the server itself made to the widget.
test('lowering the lifetime does not retroactively expire a token issued under a longer one', function (): void {
    config(['wayfindr.visitor_session_ttl_minutes' => 60]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    $svc = app(VisitorSessionToken::class);

    // What the widget was told, and scheduled its refresh against.
    expect($svc->expiresInSeconds($token))->toBeGreaterThan(3000);

    // The operator tightens the policy.
    config(['wayfindr.visitor_session_ttl_minutes' => 5]);
    test()->travel(10)->minutes();

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    try {
        $resolved = $method->invoke($svc, $request, $f['site'], 'anon-life');
    } catch (HttpException $e) {
        $resolved = null;
    }

    expect($resolved?->id)->toBe(
        $f['visitor']->id,
        'A token advertised as good for an hour was refused ten minutes in because the setting changed. The widget scheduled its refresh from the number the server gave it, so this fails requests before that refresh is due.',
    );
})->group('lifetime');

test('enabling a lifetime does not expire tokens minted before it', function (): void {
    // The whole rollout hazard, removed rather than documented: a token minted
    // while the setting was 0 was never promised a lifetime, so it does not get
    // held to one. It ages out as the widget rotates.
    config(['wayfindr.visitor_session_ttl_minutes' => 0]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    test()->travel(90)->minutes();

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    try {
        $resolved = $method->invoke($svc, $request, $f['site'], 'anon-life');
    } catch (HttpException $e) {
        $resolved = null;
    }

    expect($resolved?->id)->toBe(
        $f['visitor']->id,
        'Enabling a lifetime retroactively expired every token an install already had out, which logs out every open session at once.',
    );
})->group('lifetime');

test('a token minted under a lifetime is still refused past it', function (): void {
    // The control: stamping the lifetime must not stop it being enforced.
    config(['wayfindr.visitor_session_ttl_minutes' => 30]);
    $f = lifetimeFixture();
    $token = lifetimeToken($f);

    test()->travel(31)->minutes();

    $request = Request::create('/api/x', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    ]);
    $svc = app(VisitorSessionToken::class);
    $method = (new ReflectionClass($svc))->getMethod('visitorFromRequest');
    $method->setAccessible(true);

    $status = 200;

    try {
        $method->invoke($svc, $request, $f['site'], 'anon-life');
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(401, 'The lifetime recorded in the token is not being enforced at all.');
})->group('lifetime');
