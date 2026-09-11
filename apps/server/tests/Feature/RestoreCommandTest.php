<?php

// wayfindr:restore (ADR 0009, slice 2): unpacks a backup archive, replaces the
// database with its dump, and puts local attachment binaries back on the disks
// their rows expect. The psql restore is faked (tests run on SQLite); the
// guard, the per-disk binary restore, and the authoritative attachment-
// integrity check are the point. Attachment ROWS are seeded directly to stand
// in for what the dump would restore, since the fake restorer is a no-op.

use App\Models\Account;
use App\Models\ConversationMessageAttachment;
use App\Support\Backup\BackupService;
use App\Support\Backup\DatabaseRestorer;
use App\Support\Backup\RestoreService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
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

test('an APP_KEY mismatch is warned before the restore runs', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // An archive taken on an install with a different key. Eight columns use
    // Laravel's `encrypted` cast, and that cast throws on read when the key
    // differs -- so without this warning the restore succeeds and the install
    // breaks the first time anything reads an OIDC secret or a webhook URL.
    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [hash('sha256', 'base64:someone-elses-application-key')],
    ]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('shares no APP_KEY with the key set');
});

test('a matching APP_KEY is not warned about', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // The control. Without it this pair would pass against a warning that
    // fires unconditionally.
    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => BackupService::appKeyFingerprints(),
    ]);

    $output = $this->artisan('wayfindr:restore', ['archive' => $archive])->assertSuccessful();

    expect($output)->not->toBeNull();

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_skew'])->toBeFalse()
        ->and($preflight['app_key_indeterminate'])->toBeFalse();
});

test('a rotated source needs its previous keys on the target too', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // The case a single current-key fingerprint could not express. A source
    // that rotated holds ciphertext under BOTH keys, and Laravel falls back
    // through app.previous_keys to read the older rows. A target sharing only
    // the current key decrypts some rows and throws on the rest -- so a match
    // has to mean "this install holds every key the archive needs", not "the
    // current keys agree".
    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [
            ...BackupService::appKeyFingerprints(),
            hash('sha256', 'base64:a-key-this-install-has-retired'),
        ],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_skew'])->toBeTrue()
        ->and($preflight['app_key_indeterminate'])->toBeFalse();
});

test('a partial key overlap is skew but not total loss', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // Source rotated K1 -> K2; this target holds K2 but never carried K1. Rows
    // written under K2 still decrypt perfectly and must not be cleared, so the
    // two flags have to disagree: skew yes, no-overlap no.
    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [
            ...BackupService::appKeyFingerprints(),
            hash('sha256', 'a-historical-key-this-target-never-had'),
        ],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_skew'])->toBeTrue()
        ->and($preflight['app_key_no_overlap'])->toBeFalse();
});

test('no shared key at all is total loss', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [hash('sha256', 'an-entirely-unrelated-key')],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_skew'])->toBeTrue()
        ->and($preflight['app_key_no_overlap'])->toBeTrue();
});

test('the command separates a partial key overlap from total loss', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // Partial: the archive names a key this install never had, alongside one it
    // does. Rows written under the shared key still decrypt, so the total-loss
    // warning would be wrong twice over -- it would read as "those values are
    // gone" when they are not, and the recovery it points at clears columns the
    // operator can still read.
    $partial = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [
            ...BackupService::appKeyFingerprints(),
            hash('sha256', 'a-historical-key-this-target-never-had'),
        ],
    ]);

    $this->artisan('wayfindr:restore', ['archive' => $partial, '--force' => true])
        ->expectsOutputToContain('do NOT clear the encrypted columns')
        ->doesntExpectOutputToContain('EVERY encrypted value in the archive becomes unreadable')
        ->assertSuccessful();

    $total = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [hash('sha256', 'an-entirely-unrelated-key')],
    ]);

    $this->artisan('wayfindr:restore', ['archive' => $total, '--force' => true])
        ->expectsOutputToContain('EVERY encrypted value in the archive becomes unreadable')
        ->doesntExpectOutputToContain('do NOT clear the encrypted columns')
        ->assertSuccessful();
});

test('a target carrying extra keys is not skew', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // The other direction, and it must NOT warn: this install can decrypt
    // everything the archive holds, it simply also remembers a key the archive
    // never used. Subset, not equality.
    config()->set('app.previous_keys', ['base64:an-extra-key-this-install-still-remembers']);

    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
        'app_key_fingerprints' => [hash('sha256', (string) config('app.key'))],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_skew'])->toBeFalse()
        ->and($preflight['app_key_indeterminate'])->toBeFalse();
});

test('an archive predating the fingerprint is indeterminate, not a match', function (): void {
    fakeRestorer();
    Storage::fake('attachments');

    // Same distinction the version check draws: "cannot verify" must never be
    // reported as "they agree", because the remedy differs.
    $archive = makeBackupArchive([
        'wayfindr_version' => (string) config('wayfindr.release.version'),
        'local_attachment_disks' => [],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['app_key_indeterminate'])->toBeTrue()
        ->and($preflight['app_key_skew'])->toBeFalse();
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

// ---- Version comparability (the skew check must not fail open) -------------

test('two unknown versions are indeterminate, NOT a silent match', function (): void {
    // The failure this guards: on a source/untagged deploy both sides resolve to
    // 'unknown', and a plain !== compared them EQUAL — reporting "no skew" and
    // letting the site come straight back up despite possible schema drift.
    config()->set('wayfindr.release.version', null); // no release identity
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_indeterminate'])->toBeTrue()
        ->and($preflight['version_skew'])->toBeFalse(); // not "skew" — simply unprovable
});

test('a source build version is indeterminate', function (): void {
    // The Dockerfile stamps 'source' when built without a release tag.
    config()->set('wayfindr.release.version', 'source');
    $archive = makeBackupArchive(['wayfindr_version' => 'source', 'local_attachment_disks' => []]);

    expect(app(RestoreService::class)->preflight($archive)['version_indeterminate'])
        ->toBeTrue();
});

test('one known and one unknown version is still indeterminate', function (): void {
    config()->set('wayfindr.release.version', 'v0.2.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    expect(app(RestoreService::class)->preflight($archive)['version_indeterminate'])
        ->toBeTrue();
});

test('two known differing versions are a real skew, not indeterminate', function (): void {
    config()->set('wayfindr.release.version', 'v0.3.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'v0.2.0', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeTrue()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('two known matching versions are neither skewed nor indeterminate', function (): void {
    config()->set('wayfindr.release.version', 'v0.3.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'v0.3.0', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeFalse()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('the CLI warns that versions could not be verified', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('wayfindr.release.version', null);
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('could NOT be verified');
});

test('preflight reports WHICH side lacks a release identity', function (): void {
    config()->set('wayfindr.release.version', 'v0.3.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    // The archive is the unidentified side; the install is known.
    expect($preflight['archive_version_known'])->toBeFalse()
        ->and($preflight['running_version_known'])->toBeTrue();
});

test('the CLI does not suggest migrations when the ARCHIVE is the unidentified side', function (): void {
    // The trap: an unidentified archive may come from NEWER code, where this
    // install has no migrations to run — "migrate then up" would be a no-op that
    // leaves an incompatible schema live.
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('wayfindr.release.version', 'v0.3.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('the ARCHIVE carries no release identity');
});

test('the CLI points at WAYFINDR_VERSION when THIS INSTALL is the unidentified side', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('wayfindr.release.version', null);
    $archive = makeBackupArchive(['wayfindr_version' => 'v0.2.0', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('THIS INSTALL carries no release identity');
});

test('an indeterminate restore does not print a version summary that contradicts the warning', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('wayfindr.release.version', null);
    $archive = makeBackupArchive(['wayfindr_version' => 'unknown', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('could NOT be verified')
        // ...and must NOT then present 'unknown' as an established common version.
        ->doesntExpectOutputToContain('Wayfindr version:');
});

test('a verified matching restore still prints the established version', function (): void {
    fakeRestorer();
    Storage::fake('attachments');
    config()->set('wayfindr.release.version', 'v0.3.0');
    $archive = makeBackupArchive(['wayfindr_version' => 'v0.3.0', 'local_attachment_disks' => []]);

    $this->artisan('wayfindr:restore', ['archive' => $archive])
        ->assertSuccessful()
        ->expectsOutputToContain('Wayfindr version: v0.3.0');
});

test('a bare development version is indeterminate — it names a lineage, not a build', function (): void {
    // The regression this guards: slice 2 replaced 'source'/null with
    // '0.1.0-dev', and two installs can BOTH report that while sitting many
    // commits (and migrations) apart. Comparing them as equal would recreate the
    // exact fail-open the sentinels caused.
    config()->set('wayfindr.release.version', '0.1.0-dev');
    $archive = makeBackupArchive(['wayfindr_version' => '0.1.0-dev', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_indeterminate'])->toBeTrue()
        ->and($preflight['version_skew'])->toBeFalse();
});

test('build metadata pins the build, making a development version comparable', function (): void {
    config()->set('wayfindr.release.version', '0.1.0-dev+abc1234');
    $archive = makeBackupArchive(['wayfindr_version' => '0.1.0-dev+abc1234', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_indeterminate'])->toBeFalse()
        ->and($preflight['version_skew'])->toBeFalse(); // same build → genuinely verified
});

test('two different development builds are a real skew', function (): void {
    config()->set('wayfindr.release.version', '0.1.0-dev+abc1234');
    $archive = makeBackupArchive(['wayfindr_version' => '0.1.0-dev+9999999', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeTrue()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('a differing commit defeats equality even when the versions match', function (): void {
    // The gap this closes: the manifest has always recorded wayfindr_commit and
    // the restore never read it, so a hand-pinned WAYFINDR_VERSION left in place
    // across deploys reported a match between genuinely different code. Version
    // equality is necessary, not sufficient (ADR 0012).
    config()->set('wayfindr.release.version', '1.2.3');
    config()->set('wayfindr.release.commit', 'bbbbbbbbbbbb');
    $archive = makeBackupArchive([
        'wayfindr_version' => '1.2.3',
        'wayfindr_commit' => 'aaaaaaaaaaaa',
        'local_attachment_disks' => [],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeTrue()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('a matching commit leaves matching versions a match', function (): void {
    config()->set('wayfindr.release.version', '1.2.3');
    config()->set('wayfindr.release.commit', 'aaaaaaaaaaaa');
    $archive = makeBackupArchive([
        'wayfindr_version' => '1.2.3',
        'wayfindr_commit' => 'aaaaaaaaaaaa',
        'local_attachment_disks' => [],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeFalse()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('a differing commit is decisive even when neither version is identified', function (): void {
    // An archive written before ADR 0012 carries 'unknown' but may still record a
    // commit. That is enough to know the code differs, which beats "cannot tell".
    config()->set('wayfindr.release.version', 'unknown');
    config()->set('wayfindr.release.commit', 'bbbbbbbbbbbb');
    $archive = makeBackupArchive([
        'wayfindr_version' => 'unknown',
        'wayfindr_commit' => 'aaaaaaaaaaaa',
        'local_attachment_disks' => [],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeTrue()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('the two spellings of one release are not a skew', function (): void {
    // Official images bake the tag verbatim (v-prefixed) while a derived install
    // records the canonical form. Same release; reporting skew would be false.
    config()->set('wayfindr.release.version', '0.1.0-alpha.3');
    $archive = makeBackupArchive([
        'wayfindr_version' => 'v0.1.0-alpha.3',
        'local_attachment_disks' => [],
    ]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_skew'])->toBeFalse()
        ->and($preflight['version_indeterminate'])->toBeFalse();
});

test('a tagged prerelease that merely contains "dev" is a real identity', function (): void {
    // Only the generated bare `<VERSION>-dev` form is ambiguous. A deliberately
    // tagged prerelease is a real release identifier, and treating it as
    // unverifiable would keep a site in maintenance for a pair that matches.
    config()->set('wayfindr.release.version', 'v0.2.0-dev.1');
    $archive = makeBackupArchive(['wayfindr_version' => 'v0.2.0-dev.1', 'local_attachment_disks' => []]);

    $preflight = app(RestoreService::class)->preflight($archive);

    expect($preflight['version_indeterminate'])->toBeFalse()
        ->and($preflight['version_skew'])->toBeFalse();
});

test('the key-loss runbook still matches the schema it tells operators to edit', function (): void {
    // This list drifted twice inside one pull request: first it named two of
    // the nine encrypted columns and read as exhaustive, then it named all nine
    // but told operators to NULL five that are NOT NULL -- which fails the
    // constraint, and an empty string does not help because the cast still
    // tries to decrypt it. Both versions stranded an operator mid-recovery on
    // an install whose sign-in was already broken.
    //
    // So the runbook is checked against the models and the live schema rather
    // than against a copy of itself. Adding a tenth encrypted column, or making
    // an existing one nullable, now fails here instead of in production.
    $runbook = file_get_contents(base_path('../../docs/self-hosting/backup-restore.md'));

    expect($runbook)->toBeString();

    $section = str($runbook)->after('#### If the keys are genuinely gone')->before('###')->toString();

    $encrypted = [];

    foreach ((new DirectoryIterator(app_path('Models'))) as $entry) {
        if ($entry->isDot() || $entry->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\Models\\'.$entry->getBasename('.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $model = new $class;

        foreach ($model->getCasts() as $column => $cast) {
            if (! str_starts_with((string) $cast, 'encrypted')) {
                continue;
            }

            $encrypted[$model->getTable()][] = $column;
        }
    }

    // The count is asserted so that a model added without a thought here fails
    // loudly rather than slipping past a per-column loop that never ran for it.
    expect(array_sum(array_map('count', $encrypted)))->toBe(9)
        ->and($encrypted)->toHaveCount(7);

    $nullableBlock = str($section)->between('Nullable — clear the column', '`NOT NULL` — delete the rows')->toString();
    $deleteBlock = str($section)->after('`NOT NULL` — delete the rows')->toString();

    $misplaced = [];

    foreach ($encrypted as $table => $columns) {
        $columnIsNullable = collect(Schema::getColumns($table))->keyBy('name');

        foreach ($columns as $column) {
            $nullable = (bool) ($columnIsNullable[$column]['nullable'] ?? false);

            // A nullable column may be cleared in place. A NOT NULL one cannot
            // be, so its TABLE has to appear in the delete block -- and a
            // cascade counts: deleting outbound_webhook_endpoints takes
            // outbound_webhook_deliveries.response_body with it.
            $named = $nullable
                ? str_contains($nullableBlock, $column) || str_contains($deleteBlock, $table)
                : str_contains($deleteBlock, $table);

            if (! $named) {
                $misplaced[] = $table.'.'.$column.($nullable ? ' (nullable)' : ' (NOT NULL)');
            }
        }
    }

    expect($misplaced)->toBe([], 'The runbook does not give a working reset for: '.implode(', ', $misplaced));
});
