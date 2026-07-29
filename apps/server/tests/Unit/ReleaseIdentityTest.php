<?php

// Release identity resolution (the fix the alpha.1 -> alpha.2 upgrade drill
// forced): a blank env override must NOT shadow the identity baked into the
// image, because a pre-identity install's .env carries empty
// WAYFINDR_VERSION= lines that Compose env_file passes through.

use App\Support\ReleaseIdentity;

test('a non-empty env override wins over the baked value', function (): void {
    expect(ReleaseIdentity::resolve('v9.9.9-custom', 'v0.1.0-alpha.2'))
        ->toBe('v9.9.9-custom');
});

test('an empty env falls back to the baked file value', function (): void {
    // This is the upgrade case: env_file sets WAYFINDR_VERSION= (empty).
    expect(ReleaseIdentity::resolve('', 'v0.1.0-alpha.2'))
        ->toBe('v0.1.0-alpha.2');
});

test('a whitespace-only env falls back too, and results are trimmed', function (): void {
    expect(ReleaseIdentity::resolve('   ', "v0.1.0-alpha.2\n"))
        ->toBe('v0.1.0-alpha.2');
});

test('an unset env falls back to the baked value', function (): void {
    expect(ReleaseIdentity::resolve(null, 'v0.1.0-alpha.2'))
        ->toBe('v0.1.0-alpha.2');
});

test('nothing set anywhere resolves to null', function (): void {
    expect(ReleaseIdentity::resolve(null, null))->toBeNull()
        ->and(ReleaseIdentity::resolve('', ''))->toBeNull()
        ->and(ReleaseIdentity::resolve('  ', "\n"))->toBeNull();
});

// ---- Development identity (ADR 0012 slice 2) ------------------------------

test('the baked image value still wins over the development fallback', function (): void {
    // Precedence is env → baked → dev: a published image must never report -dev.
    expect(ReleaseIdentity::resolve(null, 'v0.1.0-alpha.3', '0.1.0-dev'))
        ->toBe('v0.1.0-alpha.3');
});

test('a host build with no baked identity falls back to the development version', function (): void {
    // The Forge/source-on-host case that previously resolved to null → "unknown".
    expect(ReleaseIdentity::resolve(null, null, '0.1.0-dev'))->toBe('0.1.0-dev');
});
