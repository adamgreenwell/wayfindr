<?php

// The widget runs on a customer's page and talks to the install cross-origin,
// so `config/cors.php` is load-bearing for every visitor. It is published (the
// framework default has no `env()` behind `max_age`), and publishing froze two
// values that are dangerous to change without noticing. These pin them.

use Illuminate\Support\Facades\Route;

test('a widget preflight is cacheable, so a five-second poll does not preflight every time', function (): void {
    expect(config('cors.max_age'))
        ->toBeGreaterThan(0, 'A max_age of 0 tells the browser not to cache the preflight, so every widget poll pays an extra round trip. With an active cobrowse flushing every 50ms that is ~1,200 needless requests a minute.');

    $response = $this->call('OPTIONS', '/api/widget/bootstrap', [], [], [], [
        'HTTP_ORIGIN' => 'https://customer.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Max-Age'))
        ->toBe((string) config('cors.max_age'), 'The preflight response must advertise the configured lifetime.');
});

test('a widget preflight allows the Authorization header the widget sends its token in', function (): void {
    $response = $this->call('OPTIONS', '/api/widget/bootstrap', [], [], [], [
        'HTTP_ORIGIN' => 'https://customer.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
    ]);

    // The CORS layer answers by ECHOING the requested header names. The Fetch
    // spec's `*` wildcard does NOT cover Authorization, so an explicit
    // allowed_headers list that forgot it would break every widget read with
    // nothing an operator could see.
    // NOT `toContain($needle, $message)`: that matcher is variadic, so a message
    // passed there is read as a second needle and the assertion silently asks
    // something else.
    $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

    expect(str_contains($allowed, 'authorization'))
        ->toBeTrue('The widget sends its visitor token in the Authorization header; a preflight that does not allow it breaks every read. Got: '.$allowed);
});

test('the widget API never reflects an origin with credentials', function (): void {
    // supports_credentials + allowed_origins '*' makes the CORS layer reflect
    // the caller's origin instead of returning '*'. That is
    // reflect-any-origin-with-credentials. The widget API has no cookie or
    // session to send, so it needs nothing from this.
    expect(config('cors.supports_credentials'))
        ->toBeFalse('With allowed_origins at "*", enabling credentials makes the response reflect the caller origin.');

    $response = $this->call('OPTIONS', '/api/widget/bootstrap', [], [], [], [
        'HTTP_ORIGIN' => 'https://attacker.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Credentials'))
        ->toBeNull('The widget API must never tell a browser it may send credentials cross-origin.')
        ->and($response->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://attacker.example', 'The caller origin was reflected instead of "*", which is what credentials support would turn on.');
});

test('the CORS policy covers the widget API and nothing beyond it', function (): void {
    expect(config('cors.paths'))
        ->toBe(['api/*'], 'Widening these paths hands cross-origin access to surfaces that were never designed for it.');

    // Sanctum is not installed here; the framework default listed its route.
    expect(Route::has('sanctum.csrf-cookie'))->toBeFalse();
});
