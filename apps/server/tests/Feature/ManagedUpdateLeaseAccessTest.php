<?php

use App\Support\Updates\ManagedUpdateGate;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->leaseAccessOriginalStorage = app()->storagePath();
    $this->leaseAccessStorage = sys_get_temp_dir().'/wayfindr-lease-unit-'.bin2hex(random_bytes(16));
    mkdir($this->leaseAccessStorage.'/framework', 0770, true);
    app()->useStoragePath($this->leaseAccessStorage);
});

afterEach(function (): void {
    app()->useStoragePath($this->leaseAccessOriginalStorage);
    (new Filesystem)->deleteDirectory($this->leaseAccessStorage);
});

test('a new empty lease authorizes the storage group without changing process umask', function (): void {
    $before = umask();
    $lease = app(ManagedUpdateGate::class)->acquireNormal();
    $facts = lstat(storage_path('framework/managed-upgrade.lock'));
    $lease->release();

    expect($facts['mode'] & 0777)->toBe(0640)
        ->and($facts['gid'])->toBe(filegroup(storage_path('framework')))
        ->and($facts['size'])->toBe(0)
        ->and(umask())->toBe($before)
        ->and(glob(storage_path('framework/.managed-upgrade-lock-*')))->toBe([]);
});

test('an existing read-only inode needs no write or chmod permission', function (): void {
    $path = storage_path('framework/managed-upgrade.lock');
    file_put_contents($path, '');
    chmod($path, 0440);
    clearstatcache(true, $path);
    $before = lstat($path);
    $lease = app(ManagedUpdateGate::class)->acquireNormal();
    $lease->assertNormal();
    $lease->release();
    clearstatcache(true, $path);

    expect(lstat($path))->toBe($before);
});

test('Linux authorized storage readers share one real exclusive lease across UIDs', function (): void {
    $process = new Process([
        PHP_BINARY, base_path('tests/Fixtures/managed-lease-access.php'),
        app_path('Support/Updates/ManagedUpdateLease.php'),
        app_path('Support/Updates/ManagedUpdateGate.php'),
    ], timeout: 45);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and(json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR))->toMatchArray([
            'status' => 'passed',
            'checks' => [
                'root_creator', 'cross_uid_readonly_admission', 'exclusive_lifetime_and_same_inode',
                'outside_group_refused', 'preexisting_readonly_mode', 'managed_marker_refusal',
                'concurrent_publication_keeps_same_inode', 'private_staging_owner_verified_before_chmod',
                'nofollow_initialization_race',
            ],
        ]);
})->skip(
    PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0,
    'Requires an isolated Linux root test runtime to exercise real distinct UIDs.',
);
