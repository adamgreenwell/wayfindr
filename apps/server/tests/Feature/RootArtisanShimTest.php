<?php

// The monorepo root artisan shim (Forge-first deploy). Forge's Commands panel,
// its managed scheduler cron, and its queue worker all invoke `php artisan ...`
// from the repository root, while the Laravel app lives under apps/server. The
// root shim must boot the real console from there — and must NOT leak its
// shebang line to stdout, which would corrupt every command's output.

use Symfony\Component\Process\Process;

test('the repo-root artisan shim boots the console without leaking a shebang', function (): void {
    $rootArtisan = realpath(base_path('../../artisan'));

    expect($rootArtisan)->not->toBeFalse('Expected a root-level artisan shim at the repository root.');

    $process = new Process([PHP_BINARY, $rootArtisan, '--version', '--no-ansi']);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    // Booting from the repo root (CWD = root, not apps/server) still resolves
    // the framework, and the shebang line never reaches stdout.
    expect($process->getOutput())
        ->toContain('Laravel Framework')
        ->not->toContain('#!/usr/bin/env');
});
