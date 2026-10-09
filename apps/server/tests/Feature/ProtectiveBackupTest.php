<?php

use App\Models\BackupRun;
use App\Models\ConversationMessageAttachment;
use App\Support\Backup\BackupArchiveVerifier;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupService;
use App\Support\Backup\DatabaseDumper;
use App\Support\Updates\ManagedUpdateGate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->protectiveRoot = sys_get_temp_dir().'/wayfindr-protective-'.bin2hex(random_bytes(8));
    mkdir($this->protectiveRoot.'/framework', 0700, true);
    app()->useStoragePath($this->protectiveRoot);
    config()->set('wayfindr.release.version', '1.1.1');
    config()->set('wayfindr.release.commit', str_repeat('a', 40));
    config()->set('wayfindr.release.installation_profile', 'image');
    config()->set('wayfindr.backup.disk', null);
    config()->set('wayfindr.backup.prefix', 'protective-unit-install');
    config()->set('wayfindr.backup.retention_days', 1);
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.erasure.ledger_path', storage_path('app/erasure-ledger'));
    Storage::fake('attachments');
    app()->instance(DatabaseDumper::class, new class implements DatabaseDumper
    {
        public function dump(string $destination): string
        {
            file_put_contents($destination, "-- protective database fixture --\n");

            return 'pg_dump (fake) 17.0';
        }
    });
    $this->operation = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
});

afterEach(function (): void {
    File::deleteDirectory($this->protectiveRoot);
});

function protectiveRun(): BackupRun
{
    return BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
}

/** Mutate only synthetic archive bytes, then recompute the tar header checksum. */
function changeProtectiveTarHeader(string $archive, string $member, callable $change): void
{
    $raw = gzdecode(file_get_contents($archive));

    for ($offset = 0; $offset < strlen($raw);) {
        $header = substr($raw, $offset, 512);
        $name = rtrim(substr($header, 0, 100), "\0");
        $size = intval(trim(substr($header, 124, 12), "\0 "), 8);

        if ($name === './'.$member || $name === $member) {
            $header = $change($header);
            $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
            $checksum = array_sum(array_map(ord(...), str_split($header)));
            $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
            $raw = substr_replace($raw, $header, $offset, 512);
            file_put_contents($archive, gzencode($raw));

            return;
        }

        $offset += 512 + (int) (ceil($size / 512) * 512);
    }

    throw new RuntimeException('Synthetic fixture member not found.');
}

test('protective archives verify every member and bind source and all decryption keys', function (): void {
    config()->set('app.previous_keys', ['previous-key-that-never-leaves-custody']);
    Storage::disk('attachments')->put('private/object.bin', 'private bytes');
    $result = app(BackupService::class)->createProtective($this->protectiveRoot.'/archives', $this->operation);
    $receipt = app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $this->operation);

    expect($receipt['archive_sha256'])->toBe(hash_file('sha256', $result['path']))
        ->and($receipt['archive_bytes'])->toBe(filesize($result['path']))
        ->and($receipt['source'])->toBe(['version' => '1.1.1', 'commit' => str_repeat('a', 40), 'profile' => 'image'])
        ->and($result['manifest']['app_key_fingerprints'])->toHaveCount(2)
        ->and($result['manifest']['archive_integrity']['members']['attachments/attachments/private/object.bin']['sha256'])->toBe(hash('sha256', 'private bytes'));

    $raw = gzdecode(file_get_contents($result['path']));
    expect($raw)->not->toContain('previous-key-that-never-leaves-custody')
        ->and($raw)->not->toContain('erasure-ledger/');

    config()->set('app.previous_keys', []);
    expect(fn () => app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_verification_failed');
});

test('the protective runner owns each lock once and never calls retention', function (): void {
    app(ManagedUpdateGate::class)->enter($this->operation);
    $mock = Mockery::mock(BackupService::class, [app(DatabaseDumper::class)])->makePartial();
    $mock->shouldNotReceive('pruneExpired');
    app()->instance(BackupService::class, $mock);
    $run = protectiveRun();
    $result = app(BackupRunner::class)->runProtective($run, $this->protectiveRoot.'/archive', $this->operation);

    expect($result['path'])->toBe($this->protectiveRoot.'/archive/archive.tar.gz')
        ->and(is_file($result['path']))->toBeTrue()
        ->and($run->refresh()->status)->toBe(BackupRun::STATUS_SUCCEEDED)
        ->and($run->pruned_local)->toBe(0)
        ->and($run->pruned_remote)->toBe(0)
        ->and(app(ManagedUpdateGate::class)->status()['operation_id'])->toBe($this->operation);
});

test('protective admission requires the exact operation and rejects a held backup lock', function (): void {
    $runner = app(BackupRunner::class);
    expect(fn () => $runner->runProtective(protectiveRun(), $this->protectiveRoot.'/missing', $this->operation))
        ->toThrow(RuntimeException::class);
    app(ManagedUpdateGate::class)->enter($this->operation);

    expect(fn () => $runner->runProtective(protectiveRun(), $this->protectiveRoot.'/other', '11111111-2222-3333-4444-555555555555'))
        ->toThrow(RuntimeException::class);

    $lock = Cache::lock(BackupRunner::LOCK_KEY, 3900);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $runner->runProtective(protectiveRun(), $this->protectiveRoot.'/busy', $this->operation))
            ->toThrow(RuntimeException::class, 'protective_backup_busy');
    } finally {
        $lock->release();
    }
});

test('normal backups refuse an owned update and retain old pruning behavior afterward', function (): void {
    $gate = app(ManagedUpdateGate::class);
    $gate->enter($this->operation);
    expect(fn () => app(BackupRunner::class)->run(protectiveRun(), $this->protectiveRoot.'/normal'))
        ->toThrow(RuntimeException::class, 'managed_update_busy');
    $gate->release($this->operation);
    $mock = Mockery::mock(BackupService::class, [app(DatabaseDumper::class)])->makePartial();
    $mock->shouldReceive('pruneExpired')->once()->andReturn(['local' => 0, 'remote' => 0]);
    app()->instance(BackupService::class, $mock);

    expect(app(BackupRunner::class)->run(protectiveRun(), $this->protectiveRoot.'/normal'))->toBeArray();
});

test('protective points use a remote namespace ordinary retention cannot prune', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    Storage::fake('backups');
    $result = app(BackupService::class)->createProtective($this->protectiveRoot.'/archives', $this->operation);
    $key = $result['remote']['key'];
    expect($key)->toStartWith('protective-unit-install/protective/'.$this->operation.'/');

    $this->travel(365)->days();
    app(BackupService::class)->pruneExpired($this->protectiveRoot.'/archives');
    expect(Storage::disk('backups')->exists($key))->toBeTrue();
    $this->travelBack();
});

test('ordinary retention explicitly preserves protective points from recursive adapter listings', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    config()->set('filesystems.disks.backups', ['driver' => 'local']);
    $name = 'wayfindr-backup-20010101-000000-aabbcc.tar.gz';
    $ordinary = 'protective-unit-install/'.$name;
    $protected = 'protective-unit-install/protective/'.$this->operation.'/'.$name;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('files')->once()->with('protective-unit-install')->andReturn([$protected, $ordinary]);
    $disk->shouldReceive('delete')->once()->with($ordinary)->andReturn(true);
    Storage::shouldReceive('disk')->once()->with('backups')->andReturn($disk);

    expect(app(BackupService::class)->pruneExpired($this->protectiveRoot.'/archives'))
        ->toBe(['days' => 1, 'local' => 0, 'remote' => 1]);
});

test('a failed configured mirror blocks the protective runner without pruning', function (): void {
    app(ManagedUpdateGate::class)->enter($this->operation);
    config()->set('wayfindr.backup.disk', 'not-configured');
    $mock = Mockery::mock(BackupService::class, [app(DatabaseDumper::class)])->makePartial();
    $mock->shouldNotReceive('pruneExpired');
    app()->instance(BackupService::class, $mock);

    $run = protectiveRun();
    expect(fn () => app(BackupRunner::class)->runProtective($run, $this->protectiveRoot.'/failed', $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_offsite_failed');
    expect($run->refresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and(is_file($this->protectiveRoot.'/failed/archive.tar.gz'))->toBeFalse();
});

test('missing or corrupt row-homed local binaries block protective coverage', function (bool $present): void {
    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments', 'storage_key' => 'missing.bin',
        'size_bytes' => 4, 'checksum' => hash('sha256', 'good'),
    ]);

    if ($present) {
        Storage::disk('attachments')->put('missing.bin', 'bad!');
    }

    expect(fn () => app(BackupService::class)->createProtective($this->protectiveRoot.'/coverage', $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_local_coverage_failed');
})->with([false, true]);

test('archive verification rejects corrupted payload, source skew, or missing inventory', function (string $case): void {
    $result = app(BackupService::class)->createProtective($this->protectiveRoot.'/archives', $this->operation);

    if ($case === 'payload') {
        $raw = gzdecode(file_get_contents($result['path']));
        file_put_contents($result['path'], gzencode(str_replace('protective database fixture', 'corrupted! database fixture', $raw)));
    } elseif ($case === 'source') {
        config()->set('wayfindr.release.commit', str_repeat('b', 40));
    } else {
        unset($result['manifest']['archive_integrity']);
    }

    expect(fn () => app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_verification_failed');
})->with(['payload', 'source', 'inventory']);

test('archive verification rejects links, traversal, unknown members, and invalid headers', function (string $case): void {
    $result = app(BackupService::class)->createProtective($this->protectiveRoot.'/archives', $this->operation);
    changeProtectiveTarHeader($result['path'], 'database.sql', function (string $header) use ($case): string {
        return match ($case) {
            'symlink' => substr_replace($header, '2', 156, 1),
            'hardlink' => substr_replace($header, '1', 156, 1),
            'traversal' => substr_replace($header, str_pad('../database.sql', 100, "\0"), 0, 100),
            'unknown' => substr_replace($header, str_pad('./secret.env', 100, "\0"), 0, 100),
            'format' => substr_replace($header, 'xxxxx', 257, 5),
        };
    });

    expect(fn () => app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_verification_failed');
})->with(['symlink', 'hardlink', 'traversal', 'unknown', 'format']);

test('protective command emits only a redacted receipt and accurate external coverage', function (): void {
    app(ManagedUpdateGate::class)->enter($this->operation);
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3']);
    ConversationMessageAttachment::factory()->create(['storage_disk' => 'attachments-s3']);
    Storage::disk('attachments')->put('customer-name-must-stay-in-archive', 'customer-content');

    expect(Artisan::call('wayfindr:protective-backup', ['operation' => $this->operation, '--json' => true]))->toBe(0);
    $raw = Artisan::output();
    $receipt = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

    expect($receipt['pruning_suppressed'])->toBeTrue()
        ->and($receipt['coverage'])->toBe([
            'database' => true, 'local_attachments' => true, 'external_attachment_disks' => 1,
            'external_attachments_included' => false, 'external_attachments_verified' => false,
            'erasure_ledger_present' => false,
            'offsite_configured' => false, 'offsite_uploaded' => false, 'offsite_verification' => 'not-configured',
        ])
        ->and(is_file(storage_path('app/managed-updates/'.$this->operation.'/archive.tar.gz')))->toBeTrue()
        ->and($raw)->not->toContain('customer-', 'attachments-s3', 'app_key_fingerprints', $this->protectiveRoot);

    expect(Artisan::call('wayfindr:protective-backup', ['operation' => $this->operation, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['reason'])->toBe('protective_backup_destination_unavailable');
});

test('protective command rejects unsafe operation IDs and emits no exception details', function (): void {
    expect(Artisan::call('wayfindr:protective-backup', ['operation' => '../../secret', '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true))->toBe([
        'schema' => 1, 'operation_id' => null, 'status' => 'failed', 'reason' => 'protective_backup_invalid_operation',
    ]);
});

test('normal backup admission stops before creating history during a managed update', function (): void {
    app(ManagedUpdateGate::class)->enter($this->operation);
    expect(Artisan::call('wayfindr:backup'))->toBe(1)
        ->and(BackupRun::query()->count())->toBe(0);
});

test('ordinary file-lease contention remains a skipped success for the CLI and runner', function (): void {
    $gate = app(ManagedUpdateGate::class);
    $lease = $gate->acquireNormal();

    try {
        expect(Artisan::call('wayfindr:backup'))->toBe(0)
            ->and(Artisan::output())->toContain('already running', 'skipped')
            ->and(BackupRun::query()->count())->toBe(0);
        $run = protectiveRun();
        expect(app(BackupRunner::class)->run($run, $this->protectiveRoot.'/ordinary-busy'))->toBeNull()
            ->and($run->refresh()->status)->toBe(BackupRun::STATUS_FAILED)
            ->and($run->message)->toStartWith('Skipped:')
            ->and(is_dir($this->protectiveRoot.'/ordinary-busy'))->toBeFalse();
    } finally {
        $lease->release();
    }
});

test('file-lease contention never hides a managed or corrupt hold as ordinary skipped work', function (string $kind): void {
    $gate = app(ManagedUpdateGate::class);

    if ($kind === 'managed') {
        $gate->enter($this->operation);
        $lease = $gate->acquireProtective($this->operation);
    } else {
        $lease = $gate->acquireNormal();
        file_put_contents($gate->markerPath(), 'corrupt marker');
    }

    try {
        expect(Artisan::call('wayfindr:backup'))->toBe(1)
            ->and(BackupRun::query()->count())->toBe(0);
        $run = protectiveRun();
        expect(fn () => app(BackupRunner::class)->run($run, $this->protectiveRoot.'/managed-busy'))
            ->toThrow(RuntimeException::class, 'managed_update_busy');
        expect($run->refresh()->status)->toBe(BackupRun::STATUS_FAILED)
            ->and($run->message)->toBe('managed_update_busy');
    } finally {
        $lease->release();
    }
})->with(['managed', 'corrupt']);

test('protective coverage reports separate erasure custody without archiving ledger bytes', function (): void {
    app(ManagedUpdateGate::class)->enter($this->operation);
    mkdir(storage_path('app/erasure-ledger'), 0700, true);
    file_put_contents(storage_path('app/erasure-ledger/receipt.json'), 'separate-ledger-fixture');
    expect(Artisan::call('wayfindr:protective-backup', ['operation' => $this->operation, '--json' => true]))->toBe(0);
    $receipt = json_decode(Artisan::output(), true);
    $raw = gzdecode(file_get_contents(storage_path('app/managed-updates/'.$this->operation.'/archive.tar.gz')));

    expect($receipt['coverage']['erasure_ledger_present'])->toBeTrue()
        ->and($raw)->not->toContain('separate-ledger-fixture', 'erasure-ledger/');
});

test('custom or unsafe erasure custody blocks the protective backup before recording a run', function (string $kind): void {
    app(ManagedUpdateGate::class)->enter($this->operation);

    if ($kind === 'custom') {
        config()->set('wayfindr.erasure.ledger_path', $this->protectiveRoot.'/custom-ledger');
    } elseif ($kind === 'link') {
        mkdir(storage_path('app'), 0700, true);
        symlink($this->protectiveRoot, storage_path('app/erasure-ledger'));
    } else {
        mkdir(storage_path('app'), 0700, true);
        file_put_contents(storage_path('app/erasure-ledger'), 'not-a-directory');
    }

    expect(Artisan::call('wayfindr:protective-backup', ['operation' => $this->operation, '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['reason'])->toBe('protective_backup_erasure_custody_unavailable')
        ->and(BackupRun::query()->count())->toBe(0);
})->with(['custom', 'link', 'file']);

test('archive verification refuses duplicate regular members even when their checksums agree', function (): void {
    $result = app(BackupService::class)->createProtective($this->protectiveRoot.'/archives', $this->operation);
    $raw = gzdecode(file_get_contents($result['path']));

    for ($offset = 0; $offset < strlen($raw);) {
        $header = substr($raw, $offset, 512);
        $name = rtrim(substr($header, 0, 100), "\0");
        $size = intval(trim(substr($header, 124, 12), "\0 "), 8);
        $bytes = 512 + (int) (ceil($size / 512) * 512);

        if ($name === './database.sql' || $name === 'database.sql') {
            $raw = substr_replace($raw, substr($raw, $offset, $bytes), $offset, 0);
            break;
        }

        $offset += $bytes;
    }

    file_put_contents($result['path'], gzencode($raw));
    expect(fn () => app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $this->operation))
        ->toThrow(RuntimeException::class, 'protective_backup_verification_failed');
});

test('published v-tagged releases produce canonical protective receipts and preserve the raw manifest', function (): void {
    config()->set('wayfindr.release.version', 'v1.1.1');
    app(ManagedUpdateGate::class)->enter($this->operation);

    expect(Artisan::call('wayfindr:protective-backup', ['operation' => $this->operation, '--json' => true]))->toBe(0);
    $receipt = json_decode(Artisan::output(), true);
    $archive = storage_path('app/managed-updates/'.$this->operation.'/archive.tar.gz');
    expect($receipt['source'])->toBe(['version' => '1.1.1', 'commit' => str_repeat('a', 40), 'profile' => 'image'])
        ->and(gzdecode(file_get_contents($archive)))->toContain('"wayfindr_version": "v1.1.1"');
});
