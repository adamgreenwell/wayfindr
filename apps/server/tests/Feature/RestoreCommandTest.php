<?php

// wayfindr:restore (ADR 0009, slice 2): unpacks a backup archive, replaces the
// database with its dump, and puts local attachment binaries back on the disks
// their rows expect. The psql restore is faked (tests run on SQLite); the
// guard, the per-disk binary restore, and the authoritative attachment-
// integrity check are the point. Attachment ROWS are seeded directly to stand
// in for what the dump would restore, since the fake restorer is a no-op.

use App\Models\Account;
use App\Models\ConversationMessageAttachment;
use App\Support\Backup\DatabaseRestorer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A restorer that records the dump it was handed instead of running psql.
 */
class RecordingRestorer implements DatabaseRestorer
{
    /** @var list<string> */
    public array $restored = [];

    public function restore(string $sqlFile): void
    {
        $this->restored[] = $sqlFile;
    }
}

function fakeRestorer(): RecordingRestorer
{
    $restorer = new RecordingRestorer;
    app()->instance(DatabaseRestorer::class, $restorer);

    return $restorer;
}

/**
 * Build a .tar.gz shaped like a real wayfindr:backup archive.
 *
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $files  keyed "{disk}/{key}" => bytes, placed under attachments/
 */
function makeBackupArchive(array $manifest, string $dumpSql = "-- dump\n", array $files = [], bool $withManifest = true): string
{
    $src = sys_get_temp_dir().'/wf-restore-src-'.bin2hex(random_bytes(6));
    mkdir($src, 0700, true);

    file_put_contents($src.'/database.sql', $dumpSql);

    if ($withManifest) {
        file_put_contents($src.'/manifest.json', json_encode($manifest));
    }

    foreach ($files as $relative => $bytes) {
        $dest = $src.'/attachments/'.$relative;
        @mkdir(dirname($dest), 0700, true);
        file_put_contents($dest, $bytes);
    }

    $dir = sys_get_temp_dir().'/wf-restore-arc-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $archive = $dir.'/wayfindr-backup-test.tar.gz';

    exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($src).' .');
    exec('rm -rf '.escapeshellarg($src));

    return $archive;
}

test('a missing archive fails with a clear message', function (): void {
    fakeRestorer();

    $this->artisan('wayfindr:restore', ['archive' => '/no/such/backup.tar.gz'])
        ->assertFailed()
        ->expectsOutputToContain('not found');
});

test('an archive without a manifest is rejected as not-a-Wayfindr-backup', function (): void {
    fakeRestorer();

    $archive = makeBackupArchive([], withManifest: false);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('manifest.json');
});

test('restore into an empty database needs no --force and runs the dump', function (): void {
    $restorer = fakeRestorer();
    Storage::fake('attachments'); // empty local disk → not "existing data"

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('Database restored.');

    expect($restorer->restored)->toHaveCount(1);
});

test('restore refuses to overwrite a populated database without --force', function (): void {
    $restorer = fakeRestorer();
    Account::factory()->create();

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('--force');

    // The guard trips BEFORE anything destructive: the DB was never touched.
    expect($restorer->restored)->toBeEmpty();
});

test('any populated table blocks an unforced restore, not just the core content tables', function (): void {
    // The footgun: DB_DATABASE/DB_HOST aimed at another populated Postgres. A
    // stray row in a table outside the core content set must still count as
    // populated, or DROP SCHEMA would wipe that database as if it were empty.
    fakeRestorer();
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'notifiable_type' => 'App\\Models\\User',
        'notifiable_id' => 1,
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('--force');
});

test('a non-empty local attachment disk blocks an unforced restore even with an empty database', function (): void {
    // A reused/mis-mounted storage volume: the database is empty but attachment
    // files are present. The wholesale purge would destroy them, so the guard
    // must require --force here too.
    fakeRestorer();
    Storage::fake('attachments');
    Storage::disk('attachments')->put('existing/file.bin', 'PRECIOUS');

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('--force');

    // The guard tripped before restoreAttachments, so nothing was purged.
    expect(Storage::disk('attachments')->get('existing/file.bin'))->toBe('PRECIOUS');
});

test('--force overwrites a populated database', function (): void {
    $restorer = fakeRestorer();
    Account::factory()->create();

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful();

    expect($restorer->restored)->toHaveCount(1);
});

test('local attachment binaries are restored to each disk the manifest names', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    // Storage::fake does not register a filesystems.disks config entry, and the
    // restore's local-driver/safety check reads config — so a custom disk needs
    // an explicit config entry (a real install has one).
    config()->set('filesystems.disks.attachments-custom', ['driver' => 'local', 'root' => sys_get_temp_dir().'/wf-custom-'.bin2hex(random_bytes(4))]);
    Storage::fake('attachments-custom');

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments', 'attachments-custom']],
        files: [
            'attachments/ab/cd/one.bin' => 'ONE',
            'attachments-custom/xy/two.bin' => 'TWO',
        ],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('Local attachment binaries restored to: attachments, attachments-custom');

    expect(Storage::disk('attachments')->get('ab/cd/one.bin'))->toBe('ONE')
        ->and(Storage::disk('attachments-custom')->get('xy/two.bin'))->toBe('TWO');
});

test('a locally-homed row whose binary is in the archive is verified present', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments',
        'storage_key' => 'good/here.bin',
    ]);

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments']],
        files: ['attachments/good/here.bin' => 'BYTES'],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Attachments verified present: 1');

    expect(Storage::disk('attachments')->get('good/here.bin'))->toBe('BYTES');
});

test('a row whose binary is missing from the archive is reported dangling', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments',
        'storage_key' => 'missing/gone.bin',
    ]);

    // The archive declares the disk local but carries a DIFFERENT file.
    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments']],
        files: ['attachments/other/present.bin' => 'X'],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('dangling')
        ->expectsOutputToContain('missing/gone.bin');
});

test('a local-disk row the archive never captured is dangling, not hidden as external', function (): void {
    // A LOCAL disk with rows but no captured binaries (its files were already
    // gone at backup) is absent from local_attachment_disks — backup only lists
    // disks that HAD files. Restore must still call this local data loss, not
    // report it as bucket-resident (external).
    fakeRestorer();
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments',
        'storage_key' => 'lost/binary.bin',
    ]);

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => [], 'external_attachment_disks' => []],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('dangling')
        ->expectsOutputToContain('lost/binary.bin');
});

test('rows homed on a remote disk are reported external, not dangling', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('filesystems.disks.attachments-s3', ['driver' => 's3']);

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments-s3',
        'storage_key' => 'k/in/bucket.bin',
    ]);

    $archive = makeBackupArchive(
        [
            'wayfindr_version' => 'v1',
            'local_attachment_disks' => ['attachments'],
            'external_attachment_disks' => ['attachments-s3'],
        ],
        files: ['attachments/x/y.bin' => 'X'],
    );

    // Both facts are on one output line — assert them as a single substring:
    // chained expectsOutputToContain each consume a distinct write.
    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('external object stores, not this archive: attachments-s3 (1)');
});

test('a row with an unsafe storage key is reported dangling, never read outside the archive', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments',
        'storage_key' => '../../../../etc/passwd',
    ]);

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments']],
        files: ['attachments/ok/file.bin' => 'X'],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('dangling');
});

test('--force restore replaces the disk wholesale, purging stale local files', function (): void {
    // The database is replaced by DROP SCHEMA; the attachment disk must follow,
    // or a stale binary from the replaced database lingers as an orphan (and
    // rides into the next backup, which archives every file on the disk).
    fakeRestorer();
    Account::factory()->create(); // populate → --force path
    Storage::fake('attachments');
    Storage::disk('attachments')->put('stale/orphan.bin', 'FROM-THE-REPLACED-DB');

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments']],
        files: ['attachments/fresh/keep.bin' => 'FRESH'],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful();

    expect(Storage::disk('attachments')->exists('stale/orphan.bin'))->toBeFalse()
        ->and(Storage::disk('attachments')->get('fresh/keep.bin'))->toBe('FRESH');
});

test('a remote-only backup still purges stale local files on --force', function (): void {
    // The archive carried NO local disks (remote-only), but the install has
    // local files whose rows the restore drops. Those must be purged too, or
    // they linger as orphans and ride into a later backup.
    fakeRestorer();
    Account::factory()->create(); // populate → --force path
    Storage::fake('attachments');
    Storage::disk('attachments')->put('stale/old.bin', 'FROM-THE-REPLACED-DB');

    $archive = makeBackupArchive([
        'wayfindr_version' => 'v1',
        'local_attachment_disks' => [],
        'external_attachment_disks' => ['attachments-s3'],
    ]);

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful();

    expect(Storage::disk('attachments')->exists('stale/old.bin'))->toBeFalse();
});

test('a row on an archived disk this install cannot host is not counted verified', function (): void {
    // The archive carries the binary, but the disk is not configured here, so it
    // is not placed on any usable Storage disk. The summary must NOT call it
    // verified while downloads stay broken.
    fakeRestorer();
    Storage::fake('attachments');

    ConversationMessageAttachment::factory()->create([
        'storage_disk' => 'attachments-orphan',
        'storage_key' => 'x/y.bin',
    ]);

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments-orphan']],
        files: ['attachments-orphan/x/y.bin' => 'BYTES'],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Attachments verified present: 0')
        ->expectsOutputToContain('not configured here ([attachments-orphan])');
});

test('a failed stale-file purge fails the restore rather than leaving stale binaries', function (): void {
    // The local disk is throw => false, so a failed delete returns false without
    // raising. Restore must not report success with stale binaries surviving.
    fakeRestorer();

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->andReturn(['stale/old.bin']);
    $disk->shouldReceive('delete')->with(['stale/old.bin'])->andReturn(false); // purge fails silently
    Storage::shouldReceive('disk')->with('attachments')->andReturn($disk);

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    // --force: the disk is non-empty, so skip the guard and exercise the purge.
    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertFailed()
        ->expectsOutputToContain('purge');
});

test('an archive stored inside a purged attachment disk is refused', function (): void {
    // A mistaken WAYFINDR_BACKUP_PATH inside the attachment disk: the restore
    // would purge the disk and delete the archive + payload mid-restore.
    fakeRestorer();

    $root = sys_get_temp_dir().'/wf-attach-root-'.bin2hex(random_bytes(6));
    mkdir($root.'/backups', 0700, true);
    config()->set('filesystems.disks.attachments', ['driver' => 'local', 'root' => $root]);

    $src = sys_get_temp_dir().'/wf-src-'.bin2hex(random_bytes(6));
    mkdir($src, 0700, true);
    file_put_contents($src.'/database.sql', "-- dump\n");
    file_put_contents($src.'/manifest.json', json_encode(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]));
    $archive = $root.'/backups/inside.tar.gz';
    exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($src).' .');
    exec('rm -rf '.escapeshellarg($src));

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('inside the attachment disk');

    // The archive was not touched.
    expect(is_file($archive))->toBeTrue();

    exec('rm -rf '.escapeshellarg($root));
});

test('a tampered archive containing a symlink is rejected before any file is copied', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // Hand-build an archive whose attachments tree holds a symlink escaping it.
    $secret = sys_get_temp_dir().'/wf-secret-'.bin2hex(random_bytes(4)).'.txt';
    file_put_contents($secret, 'TOP-SECRET');

    $src = sys_get_temp_dir().'/wf-evil-'.bin2hex(random_bytes(6));
    mkdir($src.'/attachments/attachments', 0700, true);
    file_put_contents($src.'/database.sql', "-- dump\n");
    file_put_contents($src.'/manifest.json', json_encode(['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments']]));
    symlink($secret, $src.'/attachments/attachments/leak.bin');

    $dir = sys_get_temp_dir().'/wf-evil-arc-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $archive = $dir.'/evil.tar.gz';
    exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($src).' .');
    exec('rm -rf '.escapeshellarg($src));

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertFailed()
        ->expectsOutputToContain('symlink');

    // The secret was never copied onto the attachment disk.
    expect(Storage::disk('attachments')->exists('leak.bin'))->toBeFalse();

    @unlink($secret);
});

test('a version skew between archive and install is warned', function (): void {
    fakeRestorer();
    Storage::fake('attachments'); // empty local disk → no --force needed
    config()->set('wayfindr.release.version', 'v2.0.0');

    $archive = makeBackupArchive(['wayfindr_version' => 'v1.0.0', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('Version skew');
});

test('the attachment integrity check is skipped when the restored schema lacks the attachments table', function (): void {
    // A dump from before the attachments table existed: the row query would
    // crash after the DB is already replaced. Restore must defer the check and
    // still succeed (the operator migrates afterward).
    fakeRestorer();
    Storage::fake('attachments');
    Schema::dropIfExists('conversation_message_attachments');

    $archive = makeBackupArchive(['wayfindr_version' => 'v1', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('integrity check skipped');
});

test('a cross-version restore surfaces the skew in preflight, even with --force', function (): void {
    // The warning must reach the operator BEFORE the destructive restore, not
    // after the database has already been replaced.
    fakeRestorer();
    Account::factory()->create(); // populated → --force path
    config()->set('wayfindr.release.version', 'v2.0.0');

    $archive = makeBackupArchive(['wayfindr_version' => 'v1.0.0', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive, '--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Version skew');
});

test('an archive naming a disk this install has not configured warns instead of writing blindly', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    // Note: no 'attachments-orphan' disk configured on this install.

    $archive = makeBackupArchive(
        ['wayfindr_version' => 'v1', 'local_attachment_disks' => ['attachments', 'attachments-orphan']],
        files: [
            'attachments/a/b.bin' => 'OK',
            'attachments-orphan/c/d.bin' => 'NOWHERE-TO-PUT',
        ],
    );

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('not configured here ([attachments-orphan])');

    expect(Storage::disk('attachments')->get('a/b.bin'))->toBe('OK');
});
