<?php

// The development-identity fallback (ADR 0012 slice 2). A deploy that builds
// from source ON THE HOST (Forge) has no baked /etc/wayfindr, so it used to
// report no identity at all — which surfaced as "unknown" and made version
// checks unverifiable. It now falls back to the repository's VERSION file.

use App\Support\ReleaseIdentity;
use Illuminate\Support\Env;

test('version() resolves a development identity from the repository VERSION file', function (): void {
    // No env override and no /etc/wayfindr here, so this exercises the real
    // fallback rather than hand-passed candidates.
    $declared = trim((string) file_get_contents(dirname(base_path(), 2).'/VERSION'));

    expect(ReleaseIdentity::version())->toBe($declared.'-dev');
});

test('the running install reports an identity rather than nothing', function (): void {
    expect(config('wayfindr.release.version'))->not->toBeNull()
        ->and(config('wayfindr.release.version'))->not->toBe('unknown')
        ->and(config('wayfindr.release.version'))->not->toBe('source');
});

test('the retired source sentinel in the environment never shadows the derived identity', function (): void {
    // The Dockerfile's ENV can only re-export the raw ARG, so a stale build
    // overlay passing WAYFINDR_VERSION=source exports 'source' even though the
    // baked file holds a correctly derived identity. A non-blank env normally
    // outranks everything, so without the sentinel filter /operator, backup
    // manifests, and restore checks would all still report 'source' — exactly
    // the value ADR 0012 slice 2 set out to retire. Also covers an operator's
    // .env copied from older docs.
    Env::getRepository()->set('WAYFINDR_VERSION', 'source');

    try {
        $declared = trim((string) file_get_contents(dirname(base_path(), 2).'/VERSION'));

        expect(ReleaseIdentity::version())->toBe($declared.'-dev');
    } finally {
        Env::getRepository()->clear('WAYFINDR_VERSION');
    }
});

test('a real environment version still wins over the derived identity', function (): void {
    Env::getRepository()->set('WAYFINDR_VERSION', 'v0.1.0-alpha.3');

    try {
        expect(ReleaseIdentity::version())->toBe('v0.1.0-alpha.3');
    } finally {
        Env::getRepository()->clear('WAYFINDR_VERSION');
    }
});
