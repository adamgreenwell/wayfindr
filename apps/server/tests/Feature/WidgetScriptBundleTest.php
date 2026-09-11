<?php

use Illuminate\Support\Facades\Config;

function configureRealtime(): void
{
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.key', 'public-reverb-key');
    Config::set('broadcasting.connections.reverb.options.host', 'support.example.test');
    Config::set('broadcasting.connections.reverb.options.port', '443');
    Config::set('broadcasting.connections.reverb.options.scheme', 'https');
}

test('widget.js carries the realtime library instead of pointing at a CDN', function (): void {
    configureRealtime();

    $body = $this->get('/widget.js')->assertOk()->getContent();

    // The whole point of issue #714: a self-hosted install serves every byte
    // it runs, so an air-gapped deployment keeps realtime and a strict-CSP
    // host page is not broken by a third-party origin.
    expect($body)->toContain('Pusher JavaScript Library v8.3.0');

    // NOT asserted: that the bytes never mention pusher.com. The library's own
    // defaults name `cdn_https` (where it lazily fetches HTTP-fallback
    // dependencies) and `stats_host` (telemetry), and always will. What
    // matters is that neither can be reached from our configuration, which the
    // next assertions pin. The install snippet and tester page are covered
    // separately -- those are where a CDN <script> tag would actually appear.
    expect($body)->toContain('enabledTransports');
    expect($body)->toContain('enableStats: false');
});

test('the bundled library leaves the host page Pusher untouched', function (): void {
    configureRealtime();

    $body = $this->get('/widget.js')->assertOk()->getContent();

    // It publishes itself as window.Pusher; clobbering a host page's own copy
    // would be a regression the page owner could not see coming.
    expect($body)->toContain('__wayfindrPusher');
    expect($body)->toContain('previousPusher');
    expect($body)->toContain('delete window.Pusher');
});

test('the bundled library cannot be captured by a module loader on the host page', function (): void {
    configureRealtime();

    $body = $this->get('/widget.js')->assertOk()->getContent();

    // It is a UMD bundle: on a page using RequireJS it would call an anonymous
    // define() and never set a global, so the loaders are hidden from it and
    // the browser branch is taken deterministically.
    expect($body)->toContain('previousDefine');
    expect($body)->toContain('window.define = undefined');
});

test('an install without realtime is not made to carry the library', function (): void {
    Config::set('broadcasting.default', 'log');

    $body = $this->get('/widget.js')->assertOk()->getContent();

    expect($body)->not->toContain('Pusher JavaScript Library');
    expect($body)->toContain('Wayfindr');
});

test('the served widget payload stays within its size budget', function (): void {
    configureRealtime();

    $body = (string) $this->get('/widget.js')->assertOk()->getContent();

    // Measured on the CONTROLLER'S OWN OUTPUT rather than on the source files.
    // scripts/test-widget-bundle.sh budgets the widget source, which it can
    // read directly -- but the served response is not the vendored client
    // concatenated with the source: bundledRealtime() wraps the client in a
    // globals-restoring IIFE and joins with a newline. Budgeting the inputs
    // under-reports the real body and makes every future byte of that wrapper
    // invisible, which near a ceiling is the difference between a guard and a
    // decoration.
    //
    // gzip level 9 so this number and the shell script's are the same
    // yardstick rather than merely similar.
    $gzipped = strlen((string) gzencode($body, 9));

    expect($gzipped)->toBeLessThanOrEqual(105_000);

    // The wrapper is the part the shell script cannot see, so pin that it is
    // actually present in what was just measured. Without this the test would
    // still pass if the realtime branch silently stopped bundling -- measuring
    // a smaller payload and calling it a win.
    expect($body)->toContain('__wayfindrPusher');
});
