<?php

// wayfindr:backup (ADR 0009, slice 1): assembles a restorable archive — a
// Postgres dump plus the LOCAL attachment binaries — with a self-describing
// manifest. The pg_dump shell-out is faked (tests run on SQLite); the archive
// assembly, manifest, and the local/remote attachment split are the point.

use App\Models\ConversationMessageAttachment;
use App\Support\Backup\BackupService;
use App\Support\Backup\DatabaseDumper;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A dumper that writes a sentinel file instead of shelling out to pg_dump.
 */
function fakeDumper(string $contents = "-- fake dump\n"): DatabaseDumper
{
    return new class($contents) implements DatabaseDumper
    {
        public function __construct(private string $contents) {}

        public function dump(string $destination): string
        {
            file_put_contents($destination, $this->contents);

            return 'pg_dump (fake) 17.0';
        }
    };
}

/**
 * Extract a tar.gz into a temp dir and return that dir.
 */
function extractArchive(string $archive): string
{
    $dir = sys_get_temp_dir().'/wayfindr-backup-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    exec('tar -xzf '.escapeshellarg($archive).' -C '.escapeshellarg($dir));

    return $dir;
}

test('the backup archive contains the dump, manifest, and local attachment binaries', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper("-- WAYFINDR DUMP MARKER\n"));
    config()->set('wayfindr.attachments.storage_disk', 'attachments');

    // A real file on the local attachments disk must land in the archive.
    Storage::fake('attachments');
    Storage::disk('attachments')->put('ab/cd/secret-binary', 'the-visitor-file-bytes');

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    expect($result['path'])->toEndWith('.tar.gz')
        ->and(is_file($result['path']))->toBeTrue()
        ->and($result['size'])->toBeGreaterThan(0);

    $extracted = extractArchive($result['path']);

    expect(file_get_contents($extracted.'/database.sql'))->toContain('WAYFINDR DUMP MARKER')
        ->and(file_get_contents($extracted.'/attachments/attachments/ab/cd/secret-binary'))->toBe('the-visitor-file-bytes');

    $manifest = json_decode(file_get_contents($extracted.'/manifest.json'), true);

    expect($manifest['attachment_storage_disk'])->toBe('attachments')
        ->and($manifest['includes_local_attachment_binaries'])->toBeTrue()
        ->and($manifest['database_dump'])->toContain('pg_dump');

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('an always-remote install with no local binaries omits the attachments tree', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments-s3');
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3']);
    Storage::fake('attachments'); // empty local disk

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);
    $extracted = extractArchive($result['path']);

    expect(is_dir($extracted.'/attachments'))->toBeFalse();

    $manifest = json_decode(file_get_contents($extracted.'/manifest.json'), true);

    expect($manifest['attachment_storage_disk'])->toBe('attachments-s3')
        ->and($manifest['includes_local_attachment_binaries'])->toBeFalse();

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('local binaries are backed up even after switching new uploads to S3', function (): void {
    // Per-row storage_disk: an install that started local and switched to S3
    // still has local binaries its rows point at. The active disk must not
    // gate whether they are captured, or the archive restores dangling rows.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments-s3'); // new uploads go remote
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3']);

    Storage::fake('attachments');
    Storage::disk('attachments')->put('legacy/local-file.bin', 'PRE-SWITCH-LOCAL-BYTES');

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);
    $extracted = extractArchive($result['path']);

    expect(file_get_contents($extracted.'/attachments/attachments/legacy/local-file.bin'))->toBe('PRE-SWITCH-LOCAL-BYTES');

    $manifest = json_decode(file_get_contents($extracted.'/manifest.json'), true);
    expect($manifest['includes_local_attachment_binaries'])->toBeTrue();

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('a custom local attachments disk is captured, not just the built-in one', function (): void {
    // assertSafeDisk allows custom local disks named attachments-*. Binaries
    // on the operator's custom disk must be backed up, namespaced by disk.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('filesystems.disks.attachments-custom', ['driver' => 'local', 'root' => sys_get_temp_dir().'/wf-custom-'.bin2hex(random_bytes(4))]);
    config()->set('wayfindr.attachments.storage_disk', 'attachments-custom');

    Storage::fake('attachments');          // built-in, empty
    Storage::fake('attachments-custom');   // operator's active local disk
    Storage::disk('attachments-custom')->put('deep/key.bin', 'CUSTOM-DISK-BYTES');

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);
    $extracted = extractArchive($result['path']);

    expect(file_get_contents($extracted.'/attachments/attachments-custom/deep/key.bin'))->toBe('CUSTOM-DISK-BYTES');

    $manifest = json_decode(file_get_contents($extracted.'/manifest.json'), true);
    expect($manifest['local_attachment_disks'])->toContain('attachments-custom');

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('a row on a shared disk is never packaged and is flagged external', function (): void {
    // A manually/historically homed row with storage_disk='local' must not
    // make the backup archive that shared disk's unrelated app files — the
    // same safety judgment uploads and the sweep use. It is flagged external.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    Storage::fake('local');
    Storage::disk('local')->put('unrelated/app-file.txt', 'DO-NOT-BACK-ME-UP');

    ConversationMessageAttachment::factory()->create(['storage_disk' => 'local']);

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);
    $extracted = extractArchive($result['path']);

    expect(is_dir($extracted.'/attachments/local'))->toBeFalse()
        ->and($result['manifest']['local_attachment_disks'])->not->toContain('local')
        ->and($result['manifest']['external_attachment_disks'])->toContain('local');

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('rows homed on a remote disk are named as external dependencies in the manifest', function (): void {
    // An install that stored on S3 then switched new uploads back to local:
    // the dump has rows pointing at the bucket, which the archive does not
    // carry — the manifest must name that dependency so a restore keeps the
    // bucket reachable.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments'); // new uploads local now
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3', 'bucket' => 'b']);
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create(['storage_disk' => 'attachments-s3']);

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);
    $extracted = extractArchive($result['path']);

    $manifest = json_decode(file_get_contents($extracted.'/manifest.json'), true);

    expect($manifest['external_attachment_disks'])->toContain('attachments-s3')
        ->and($manifest['local_attachment_disks'])->not->toContain('attachments-s3');

    exec('rm -rf '.escapeshellarg($extracted).' '.escapeshellarg($dest));
});

test('the archive is created owner-only', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');

    $dest = sys_get_temp_dir().'/wayfindr-backup-perm-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    // The archive holds the DB dump and private attachment bytes.
    expect(substr(sprintf('%o', fileperms($result['path'])), -4))->toBe('0600');

    exec('rm -rf '.escapeshellarg($dest));
});

test('a completed backup leaves no .partial residue', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');

    $dest = sys_get_temp_dir().'/wayfindr-backup-partial-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    // The final archive exists; no .partial build file is left behind.
    expect(is_file($result['path']))->toBeTrue()
        ->and(glob($dest.'/*.partial') ?: [])->toBeEmpty();

    exec('rm -rf '.escapeshellarg($dest));
});

test('two backups in the same run do not overwrite each other', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');

    $dest = sys_get_temp_dir().'/wayfindr-backup-uniq-'.bin2hex(random_bytes(6));

    $a = app(BackupService::class)->create($dest);
    $b = app(BackupService::class)->create($dest);

    expect($a['path'])->not->toBe($b['path'])
        ->and(glob($dest.'/wayfindr-backup-*.tar.gz'))->toHaveCount(2);

    exec('rm -rf '.escapeshellarg($dest));
});

test('a concurrently deleted attachment is skipped, not fatal', function (): void {
    // On a LIVE backup a file listed a moment ago can vanish before it is
    // read (its row is being deleted too). That must not abort the whole run.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->andReturn(['gone/deleted.bin']);
    $disk->shouldReceive('readStream')->with('gone/deleted.bin')->andReturn(null);
    $disk->shouldReceive('exists')->with('gone/deleted.bin')->andReturn(false); // vanished
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);

    $dest = sys_get_temp_dir().'/wayfindr-backup-race-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    // The archive is still produced — a concurrent delete does not abort the
    // run. Whether the dump carries a now-dangling row is verified at restore.
    expect(is_file($result['path']))->toBeTrue();

    exec('rm -rf '.escapeshellarg($dest));
});

test('an unreadable attachment fails the backup rather than shipping a gap', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');

    // A disk that lists a file but cannot stream it (transient/permission).
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->andReturn(['ab/cd/unreadable.bin']);
    $disk->shouldReceive('readStream')->with('ab/cd/unreadable.bin')->andReturn(null);
    $disk->shouldReceive('exists')->with('ab/cd/unreadable.bin')->andReturn(true); // present but unreadable
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);

    $dest = sys_get_temp_dir().'/wayfindr-backup-dest-'.bin2hex(random_bytes(6));

    expect(fn () => app(BackupService::class)->create($dest))
        ->toThrow(RuntimeException::class);

    expect(glob($dest.'/wayfindr-backup-*.tar.gz') ?: [])->toBeEmpty();

    exec('rm -rf '.escapeshellarg($dest));
});

test('the manifest is self-describing (version, storage disk, dump label)', function (): void {
    config()->set('wayfindr.release.version', 'v0.1.0-alpha.9');
    config()->set('wayfindr.attachments.storage_disk', 'attachments');

    $manifest = app(BackupService::class)->manifest(
        Carbon::parse('2026-07-22T12:00:00Z'),
        localDisks: ['attachments'],
        remoteDisks: ['attachments-s3'],
        dumpLabel: 'pg_dump 17.0',
    );

    expect($manifest['wayfindr_version'])->toBe('v0.1.0-alpha.9')
        ->and($manifest['created_at'])->toBe('2026-07-22T12:00:00.000000Z')
        ->and($manifest['attachment_storage_disk'])->toBe('attachments')
        ->and($manifest['includes_local_attachment_binaries'])->toBeTrue()
        ->and($manifest['local_attachment_disks'])->toBe(['attachments'])
        ->and($manifest['external_attachment_disks'])->toBe(['attachments-s3'])
        ->and($manifest['database_dump'])->toBe('pg_dump 17.0');
});

test('the command writes an archive and reports the storage posture', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    Storage::disk('attachments')->put('x/y/z.bin', 'bytes');

    $dest = sys_get_temp_dir().'/wayfindr-backup-cmd-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertSuccessful()
        ->expectsOutputToContain('Backup complete')
        ->expectsOutputToContain('New uploads → attachments (local)')
        ->expectsOutputToContain('Local attachment binaries: included in archive');

    expect(glob($dest.'/wayfindr-backup-*.tar.gz'))->toHaveCount(1);

    exec('rm -rf '.escapeshellarg($dest));
});

test('the finished archive is mirrored to the configured backup disk', function (): void {
    // ADR 0010: with WAYFINDR_BACKUP_DISK set, the archive is uploaded offsite
    // after the local write, and the local copy is retained.
    app()->instance(DatabaseDumper::class, fakeDumper("-- DUMP\n"));
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    // Storage::fake does not register a filesystems.disks config entry; a real
    // backup disk has one, and the command validates against it.
    config()->set('filesystems.disks.backups', ['driver' => 'local', 'root' => sys_get_temp_dir().'/wf-backups-'.bin2hex(random_bytes(4))]);
    Storage::fake('backups');
    config()->set('wayfindr.backup.disk', 'backups');

    $dest = sys_get_temp_dir().'/wayfindr-backup-remote-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    $key = basename($result['path']);

    expect($result['remote'])->toBe(['disk' => 'backups', 'key' => $key])
        ->and(is_file($result['path']))->toBeTrue()               // local retained
        ->and(Storage::disk('backups')->exists($key))->toBeTrue() // offsite present
        ->and(Storage::disk('backups')->size($key))->toBe($result['size']);

    exec('rm -rf '.escapeshellarg($dest));
});

test('with no backup disk configured, the result has no remote copy', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.backup.disk', null);
    Storage::fake('attachments');

    $dest = sys_get_temp_dir().'/wayfindr-backup-local-'.bin2hex(random_bytes(6));

    $result = app(BackupService::class)->create($dest);

    expect($result['remote'])->toBeNull();

    exec('rm -rf '.escapeshellarg($dest));
});

test('an unconfigured backup disk fails the command but keeps the local archive', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    config()->set('wayfindr.backup.disk', 'ghost-disk'); // not in filesystems.disks

    $dest = sys_get_temp_dir().'/wayfindr-backup-ghost-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertFailed()
        ->expectsOutputToContain('Offsite upload to [ghost-disk] FAILED');

    // The local archive is intact — an offsite failure never discards it.
    expect(glob($dest.'/wayfindr-backup-*.tar.gz'))->toHaveCount(1);

    exec('rm -rf '.escapeshellarg($dest));
});

test('an attachment disk is refused as a backup mirror', function (): void {
    // wayfindr:sweep-orphaned-attachments reconciles every attachments* disk and
    // would delete backup archives written there as orphans. Refuse it.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3', 'bucket' => 'b']);
    config()->set('wayfindr.backup.disk', 'attachments-s3');

    $dest = sys_get_temp_dir().'/wayfindr-backup-attachdisk-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertFailed()
        ->expectsOutputToContain('attachment disk');

    // The local archive is intact.
    expect(glob($dest.'/wayfindr-backup-*.tar.gz'))->toHaveCount(1);

    exec('rm -rf '.escapeshellarg($dest));
});

test('an incomplete offsite upload fails the backup', function (): void {
    // A short/partial upload must not be reported as a durable offsite copy.
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    config()->set('filesystems.disks.backups', ['driver' => 'local']);
    config()->set('wayfindr.backup.disk', 'backups');

    // Swap only the backups disk for one that accepts the write but reports a
    // truncated size (attachments keeps its real fake disk).
    $backups = Mockery::mock(Filesystem::class);
    $backups->shouldReceive('writeStream')->once()->andReturn(true);
    $backups->shouldReceive('exists')->andReturn(true);
    $backups->shouldReceive('size')->andReturn(1); // wrong size
    Storage::set('backups', $backups);

    $dest = sys_get_temp_dir().'/wayfindr-backup-short-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertFailed()
        ->expectsOutputToContain('Offsite upload to [backups] FAILED');

    expect(glob($dest.'/wayfindr-backup-*.tar.gz'))->toHaveCount(1);

    exec('rm -rf '.escapeshellarg($dest));
});

test('the command reports a successful offsite copy', function (): void {
    app()->instance(DatabaseDumper::class, fakeDumper());
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    Storage::fake('attachments');
    config()->set('filesystems.disks.backups', ['driver' => 'local', 'root' => sys_get_temp_dir().'/wf-backups-'.bin2hex(random_bytes(4))]);
    Storage::fake('backups');
    config()->set('wayfindr.backup.disk', 'backups');

    $dest = sys_get_temp_dir().'/wayfindr-backup-offsite-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertSuccessful()
        ->expectsOutputToContain('Offsite copy uploaded to [backups]');

    exec('rm -rf '.escapeshellarg($dest));
});

test('a dump failure surfaces as a command failure, not a half-written archive', function (): void {
    app()->instance(DatabaseDumper::class, new class implements DatabaseDumper
    {
        public function dump(string $destination): string
        {
            throw new RuntimeException('pg_dump failed: connection refused');
        }
    });

    $dest = sys_get_temp_dir().'/wayfindr-backup-fail-'.bin2hex(random_bytes(6));

    $this->artisan('wayfindr:backup', ['--path' => $dest])
        ->assertFailed()
        ->expectsOutputToContain('Backup failed');

    expect(glob($dest.'/wayfindr-backup-*.tar.gz') ?: [])->toBeEmpty();

    exec('rm -rf '.escapeshellarg($dest));
});
