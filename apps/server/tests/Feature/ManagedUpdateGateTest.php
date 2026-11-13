<?php

use App\Jobs\RunRestoreJob;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupService;
use App\Support\Backup\RestoreService;
use App\Support\Updates\ManagedUpdateGate;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->managedOriginalStorage = app()->storagePath();
    $this->managedFixtureStorage = sys_get_temp_dir().'/wayfindr-managed-gate-'.bin2hex(random_bytes(8));
    mkdir($this->managedFixtureStorage.'/framework', 0700, true);
    app()->useStoragePath($this->managedFixtureStorage);
    $this->gate = app(ManagedUpdateGate::class);
    $this->operation = '33333333-3333-4333-8333-333333333333';
    $this->otherOperation = '44444444-4444-4444-8444-444444444444';
});

afterEach(function (): void {
    app()->useStoragePath($this->managedOriginalStorage);
    (new Filesystem)->deleteDirectory($this->managedFixtureStorage);
});

test('managed ownership is durable and same-operation admission is idempotent', function (): void {
    expect($this->gate->status())->toMatchArray(['schema' => 1, 'held' => false, 'operation_id' => null]);

    $accepted = $this->gate->enter($this->operation);
    $bytes = file_get_contents($this->gate->markerPath());
    $fresh = new ManagedUpdateGate;

    expect($accepted)->toMatchArray(['held' => true, 'operation_id' => $this->operation])
        ->and($fresh->enter($this->operation))->toBe($accepted)
        ->and(file_get_contents($fresh->markerPath()))->toBe($bytes)
        ->and(fn () => $fresh->enter($this->otherOperation))->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and(fn () => $fresh->acquireNormal())->toThrow(RuntimeException::class, 'managed_update_busy');
});

test('wrong-owner release cannot lift another operation or ordinary maintenance', function (): void {
    file_put_contents(storage_path('framework/down'), '{"retry":60}');
    file_put_contents(storage_path('framework/maintenance.php'), '<?php echo "maintenance";');
    $this->gate->enter($this->operation);

    expect(fn () => $this->gate->release($this->otherOperation))->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and($this->gate->active())->toBeTrue();

    expect($this->gate->release($this->operation))->toMatchArray(['held' => false, 'operation_id' => null])
        ->and(file_get_contents(storage_path('framework/down')))->toBe('{"retry":60}')
        ->and(file_get_contents(storage_path('framework/maintenance.php')))->toBe('<?php echo "maintenance";');
});

test('an ordinary lifetime lease excludes gate transitions across processes', function (): void {
    $lease = $this->gate->acquireNormal();
    $path = storage_path('framework/managed-upgrade.lock');
    $child = new Process([PHP_BINARY, '-r', '$h=fopen($argv[1], "c+b"); exit(flock($h, LOCK_EX|LOCK_NB) ? 0 : 73);', $path]);
    $child->run();

    expect($child->getExitCode())->toBe(73)
        ->and(fn () => $this->gate->enter($this->operation))->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and($this->gate->active())->toBeFalse();

    $lease->release();
    expect(fn () => $lease->assertNormal())->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and($this->gate->enter($this->operation)['held'])->toBeTrue();
});

test('protective work requires exact durable owner and keeps its lease until finished', function (): void {
    expect(fn () => $this->gate->acquireProtective($this->operation))->toThrow(RuntimeException::class, 'managed_update_busy');
    $this->gate->enter($this->operation);
    expect(fn () => $this->gate->acquireProtective($this->otherOperation))->toThrow(RuntimeException::class, 'managed_update_busy');

    $lease = $this->gate->acquireProtective($this->operation);
    $lease->assertProtective($this->operation);
    expect(fn () => $lease->assertNormal())->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and(fn () => $this->gate->release($this->operation))->toThrow(RuntimeException::class, 'managed_update_busy');
    $lease->release();

    expect($this->gate->status()['held'])->toBeTrue();
    $this->gate->release($this->operation);
});

test('legacy backup ownership refuses managed entry before creating a marker', function (): void {
    $lock = Cache::lock(BackupRunner::LOCK_KEY, 3900);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $this->gate->enter($this->operation))->toThrow(RuntimeException::class, 'managed_update_busy')
            ->and($this->gate->active())->toBeFalse();
    } finally {
        $lock->release();
    }

    expect($this->gate->enter($this->operation)['held'])->toBeTrue();
});

test('corrupt marker nodes hold admission and cannot be released', function (string $kind): void {
    $path = $this->gate->markerPath();

    match ($kind) {
        'directory' => mkdir($path),
        'link' => symlink($path.'-missing', $path),
        'partial' => file_put_contents($path, '{"schema":'),
        'unknown-field' => file_put_contents($path, json_encode(['schema' => 1, 'operation_id' => $this->operation, 'created_at' => time(), 'command' => 'anything'])."\n"),
        'duplicate-field' => file_put_contents($path, '{"schema":1,"schema":1,"operation_id":"'.$this->operation.'","created_at":1}'."\n"),
    };

    expect($this->gate->active())->toBeTrue()
        ->and(fn () => $this->gate->acquireNormal())->toThrow(RuntimeException::class, 'managed_update_busy')
        ->and(fn () => $this->gate->status())->toThrow(RuntimeException::class, 'managed_update_state_invalid')
        ->and(fn () => $this->gate->release($this->operation))->toThrow(RuntimeException::class, 'managed_update_state_invalid')
        ->and($this->gate->active())->toBeTrue();
})->with(['directory', 'link', 'partial', 'unknown-field', 'duplicate-field']);

test('a symlink lock file is refused without touching the linked file', function (): void {
    $other = $this->managedFixtureStorage.'/preserved';
    file_put_contents($other, 'preserve');
    symlink($other, storage_path('framework/managed-upgrade.lock'));

    expect(fn () => $this->gate->acquireNormal())->toThrow(RuntimeException::class, 'managed_update_state_unavailable')
        ->and(file_get_contents($other))->toBe('preserve');
});

test('a replaced file invalidates a lease before any destructive subordinate work', function (): void {
    $lease = $this->gate->acquireNormal();
    $path = storage_path('framework/managed-upgrade.lock');
    rename($path, $path.'-old');
    file_put_contents($path, '');

    expect(fn () => $lease->assertNormal())->toThrow(RuntimeException::class, 'managed_update_state_unavailable');
    $lease->release();
});

test('CLI and direct restores refuse managed ownership before reading an archive', function (): void {
    $this->gate->enter($this->operation);

    expect(fn () => app(RestoreService::class)->restore('/archive-that-must-not-be-read', true))
        ->toThrow(RuntimeException::class, 'managed_update_busy');

    $this->artisan('wayfindr:restore', ['archive' => '/archive-that-must-not-be-read', '--force' => true])
        ->expectsOutputToContain('A managed update owns maintenance')
        ->assertFailed();

    expect($this->gate->status()['operation_id'])->toBe($this->operation);
});

test('a queued restore cannot run or lift a managed maintenance window', function (): void {
    $this->gate->enter($this->operation);
    file_put_contents(storage_path('framework/down'), '{"retry":60}');
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->once()->andReturn('/no-archive');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldNotReceive('restore');

    (new RunRestoreJob('archive.tar.gz'))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
        ->and(file_get_contents(storage_path('framework/down')))->toBe('{"retry":60}')
        ->and($this->gate->status()['operation_id'])->toBe($this->operation);
});

test('malformed operation identifiers cannot create or release ownership', function (string $operation): void {
    expect(fn () => $this->gate->enter($operation))->toThrow(RuntimeException::class, 'managed_update_request_invalid')
        ->and(fn () => $this->gate->release($operation))->toThrow(RuntimeException::class, 'managed_update_request_invalid')
        ->and($this->gate->active())->toBeFalse();
})->with(['../an-install', 'latest', '33333333-3333-4333-8333-33333333333Z']);
