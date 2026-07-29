<?php

// The development-identity fallback (ADR 0012 slice 2). A deploy that builds
// from source ON THE HOST (Forge) has no baked /etc/wayfindr, so it used to
// report no identity at all — which surfaced as "unknown" and made version
// checks unverifiable. It now falls back to the repository's VERSION file.

use App\Support\ReleaseIdentity;

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
