<?php

declare(strict_types=1);

use App\Support\Release\CheckRegistry;

test('a passing CLI runtime still requires cross-process attestation', function (): void {
    // This test environment satisfies the declared PHP, extension, and libcurl
    // floors. A true result would overclaim that the same is known about
    // Composer, PHP-FPM, or already-running workers.
    expect((new CheckRegistry)->evaluate('php-runtime-extensions'))->toBeNull();
});
