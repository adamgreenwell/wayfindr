<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/**
 * Issue #955. `/widget.js` sat in `routes/web.php` from the first commit that
 * served the widget from Laravel, so it inherited the `web` group: session,
 * CSRF, queued cookies. Every visitor of every page of every install fetched it
 * before interacting with anything, which wrote a `sessions` row per page view
 * and set `wayfindr-session` and `XSRF-TOKEN` on people who never opened the
 * widget.
 *
 * The suite could not have caught it: the existing asset test asserts status,
 * content type and body, which are identical either way. What separates the two
 * worlds is the response HEADERS and the route's middleware stack, so that is
 * what these assert.
 */
test('the widget script sets no cookies', function (): void {
    $response = $this->get('/widget.js');

    $response->assertOk();

    // A visitor who only loaded a page has consented to nothing. ADR 0019 made
    // presence collection opt-in with an explicit decline path; a cookie set
    // before the widget is even opened goes behind that decision.
    expect($response->headers->getCookies())->toBe([]);
    expect($response->headers->has('Set-Cookie'))->toBeFalse();
});

test('the widget script route carries no session, CSRF or cookie middleware', function (): void {
    $route = Route::getRoutes()->getByName('widget.script');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    // in_array rather than expect()->not->toContain(): Pest's toContain() is
    // variadic, so a negated call with more than one needle passes whatever the
    // stack holds. One explicit boolean per class cannot degrade that way.
    expect(in_array(StartSession::class, $middleware, true))->toBeFalse();
    expect(in_array(VerifyCsrfToken::class, $middleware, true))->toBeFalse();
    expect(in_array(AddQueuedCookiesToResponse::class, $middleware, true))->toBeFalse();
    expect(in_array(EncryptCookies::class, $middleware, true))->toBeFalse();

    // The `web` group is the specific thing that reintroduces all four. Naming
    // it as well means the failure says WHY rather than only what.
    expect(in_array('web', $middleware, true))->toBeFalse();
});

test('a revalidating client gets a bare 304 instead of the whole bundle', function (): void {
    $first = $this->get('/widget.js')->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull();

    // Now that Set-Cookie is gone the cache headers are load-bearing rather
    // than decorative, so both halves are pinned: the freshness window, and
    // the revalidation that makes a miss cheap.
    expect($first->headers->get('Cache-Control'))->toContain('max-age=300');
    expect($first->headers->get('Cache-Control'))->toContain('public');

    $second = $this->withHeaders(['If-None-Match' => $etag])->get('/widget.js');

    $second->assertStatus(304);
    expect($second->getContent())->toBe('');
});

test('the etag changes when the served bytes change', function (): void {
    $withoutRealtime = $this->get('/widget.js')->assertOk();

    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'public-reverb-key');
    Config::set('broadcasting.connections.reverb.options.host', 'support.example.test');
    Config::set('broadcasting.connections.reverb.options.port', '443');
    Config::set('broadcasting.connections.reverb.options.scheme', 'https');

    $withRealtime = $this->get('/widget.js')->assertOk();

    // Configuring realtime prepends the bundled client, so the body genuinely
    // differs. An etag that did not move here would serve a stale widget to
    // every visitor holding the old one -- the exact failure caching is
    // supposed to avoid, dressed as a performance win.
    expect($withRealtime->headers->get('ETag'))
        ->not->toBe($withoutRealtime->headers->get('ETag'));
});

// Dropping the `web` group must not also drop the ADR 0013 serving gate. That is
// asserted behaviourally in UpgradeGuardTest — 'the serving refusal reaches
// public assets registered outside the web group' — because the gate is global
// middleware and so never appears in a route's own gathered stack. Asserting it
// here by introspection would have passed while the gate was absent.
