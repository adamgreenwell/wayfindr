<?php

namespace App\Support\Backup;

use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentStorage;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restores a Wayfindr backup archive (ADR 0009): unpacks the tarball, replaces
 * the database with its dump, and puts the LOCAL attachment binaries back on
 * the disks their rows expect. It is the AUTHORITATIVE attachment-integrity
 * check — once the dump is loaded, the attachment rows are ground truth, so it
 * verifies each locally-homed row's binary was carried in the archive and
 * reports any that are missing (dangling). Remote (bucket) binaries are served
 * from their object store, not the archive, and are named as such.
 *
 * Restore is itself a data-loss event if pointed at the wrong database, so it
 * refuses to overwrite a populated database unless forced.
 */
class RestoreService
{
    public function __construct(private readonly DatabaseRestorer $restorer) {}

    /**
     * @return array{
     *     manifest: array<string, mixed>,
     *     archive_version: string,
     *     running_version: string,
     *     version_skew: bool,
     *     restored_disks: list<string>,
     *     unconfigured_disks: list<string>,
     *     integrity: array{verified: int, dangling: list<array{id: int, disk: string, key: string}>, external: array<string, int>},
     * }
     */
    public function restore(string $archivePath, bool $force = false): array
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException("Backup archive not found: {$archivePath}");
        }

        // Unpack on the archive's own volume (not /tmp): a large archive could
        // overflow the container's small /tmp, and the mounted backup path that
        // holds the archive has room.
        $work = $this->makeWorkDir($archivePath);

        try {
            $this->extract($archivePath, $work);

            $manifest = $this->readManifest($work);
            $dump = $work.'/database.sql';

            if (! is_file($dump)) {
                throw new RuntimeException('Archive has no database.sql — not a Wayfindr backup.');
            }

            // GUARD (before anything destructive): refuse to overwrite a
            // database that already holds data unless explicitly forced.
            if (! $force && $this->databaseIsPopulated()) {
                throw new RuntimeException(
                    'The target database already contains data; restoring would REPLACE it. '
                    .'Re-run with --force to confirm you intend to overwrite this database.'
                );
            }

            $runningVersion = (string) (config('wayfindr.release.version') ?? 'unknown');
            $archiveVersion = (string) ($manifest['wayfindr_version'] ?? 'unknown');

            $localDisks = $this->localDisksFrom($manifest);

            // Replace the database with the dump (atomic — see the restorer).
            $this->restorer->restore($dump);

            // Put local attachment binaries back where their rows expect them.
            $attachments = $this->restoreAttachments($work, $localDisks);

            // The dump's rows are now ground truth: verify the archive carried
            // each locally-homed row's binary, and report the dangling ones.
            $integrity = $this->verifyAttachmentIntegrity($work, $localDisks);

            return [
                'manifest' => $manifest,
                'archive_version' => $archiveVersion,
                'running_version' => $runningVersion,
                'version_skew' => $archiveVersion !== $runningVersion,
                'restored_disks' => $attachments['restored'],
                'unconfigured_disks' => $attachments['unconfigured'],
                'integrity' => $integrity,
            ];
        } finally {
            $this->removeDir($work);
        }
    }

    /**
     * Is there any application data in the current database? Checks a handful
     * of top-level content tables; a missing table (a fresh, un-migrated
     * database) is simply not populated by that table. This is the signal the
     * --force guard trips on.
     */
    private function databaseIsPopulated(): bool
    {
        foreach (['accounts', 'users', 'sites', 'conversations', 'tickets'] as $table) {
            try {
                if (DB::table($table)->exists()) {
                    return true;
                }
            } catch (Throwable) {
                // Table does not exist yet (nothing to overwrite for it).
            }
        }

        return false;
    }

    /**
     * Restores the archived binaries for each local disk the manifest names,
     * onto the disk the row's storage_disk expects. A disk the archive carried
     * but this install has not configured as a safe local disk cannot receive
     * its files — it is reported (unconfigured) rather than written blindly to
     * a shared disk, and the integrity check will flag the affected rows.
     *
     * @param  list<string>  $diskNames
     * @return array{restored: list<string>, unconfigured: list<string>}
     */
    private function restoreAttachments(string $work, array $diskNames): array
    {
        $restored = [];
        $unconfigured = [];

        foreach ($diskNames as $diskName) {
            $sourceRoot = $work.'/attachments/'.$diskName;

            if (! is_dir($sourceRoot)) {
                // Named in the manifest but no files in the archive (nothing to
                // restore for it).
                continue;
            }

            if (! $this->isRestorableLocalDisk($diskName)) {
                $unconfigured[] = $diskName;

                continue;
            }

            $storage = Storage::disk($diskName);

            foreach ($this->filesUnder($sourceRoot) as $absolute) {
                $key = ltrim(substr($absolute, strlen($sourceRoot)), '/');
                $this->assertSafeKey($key);

                $bytes = file_get_contents($absolute);

                if ($bytes === false) {
                    throw new RuntimeException("Could not read archived attachment [{$diskName}:{$key}] during restore.");
                }

                // Fail loud on a partial write rather than leave a truncated
                // binary behind a "successful" restore.
                if ($storage->put($key, $bytes) === false || $storage->size($key) !== strlen($bytes)) {
                    throw new RuntimeException("Could not fully restore attachment [{$diskName}:{$key}].");
                }
            }

            $restored[] = $diskName;
        }

        return ['restored' => $restored, 'unconfigured' => $unconfigured];
    }

    /**
     * The authoritative attachment check: for every attachment row the dump
     * restored, confirm the archive carried its binary. A row homed on a disk
     * the archive carried but whose file is absent is DANGLING (its binary is
     * gone). A row homed on a disk NOT in the archive is served from an
     * external object store (or a disk this install lacks) — counted separately
     * so a bucket-backed install is not misreported as broken.
     *
     * @param  list<string>  $localDisks
     * @return array{verified: int, dangling: list<array{id: int, disk: string, key: string}>, external: array<string, int>}
     */
    private function verifyAttachmentIntegrity(string $work, array $localDisks): array
    {
        $localSet = array_flip($localDisks);
        $verified = 0;
        $dangling = [];
        $external = [];

        ConversationMessageAttachment::query()
            ->select(['id', 'storage_disk', 'storage_key'])
            ->chunkById(500, function ($rows) use (&$verified, &$dangling, &$external, $work, $localSet): void {
                foreach ($rows as $row) {
                    $disk = (string) $row->storage_disk;
                    $key = (string) $row->storage_key;

                    if (! isset($localSet[$disk])) {
                        // Binary lives in a bucket (or on a disk not in this
                        // archive) — not something restore can verify locally.
                        $external[$disk] = ($external[$disk] ?? 0) + 1;

                        continue;
                    }

                    if ($key !== '' && $this->keyIsSafe($key) && is_file($work.'/attachments/'.$disk.'/'.$key)) {
                        $verified++;

                        continue;
                    }

                    $dangling[] = ['id' => (int) $row->id, 'disk' => $disk, 'key' => $key];
                }
            });

        return ['verified' => $verified, 'dangling' => $dangling, 'external' => $external];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function localDisksFrom(array $manifest): array
    {
        $disks = $manifest['local_attachment_disks'] ?? [];

        if (! is_array($disks)) {
            return [];
        }

        return collect($disks)
            ->map(fn ($disk): string => (string) $disk)
            ->filter(fn (string $disk): bool => $disk !== '')
            ->values()
            ->all();
    }

    private function isRestorableLocalDisk(string $name): bool
    {
        return config("filesystems.disks.{$name}.driver") === 'local'
            && $this->isSafeAttachmentDisk($name);
    }

    private function isSafeAttachmentDisk(string $name): bool
    {
        try {
            AttachmentStorage::assertSafeDisk($name);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function extract(string $archive, string $work): void
    {
        $process = new Process(['tar', '-xzf', $archive, '-C', $work], timeout: null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Could not unpack the backup archive: '.trim($process->getErrorOutput()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $work): array
    {
        $path = $work.'/manifest.json';

        if (! is_file($path)) {
            throw new RuntimeException('Archive has no manifest.json — not a Wayfindr backup.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! array_key_exists('wayfindr_version', $decoded)) {
            throw new RuntimeException('The backup manifest is unreadable or not a Wayfindr backup.');
        }

        return $decoded;
    }

    private function assertSafeKey(string $key): void
    {
        if (! $this->keyIsSafe($key)) {
            throw new RuntimeException("Refusing to restore attachment with an unsafe path [{$key}].");
        }
    }

    private function keyIsSafe(string $key): bool
    {
        return $key !== ''
            && ! str_starts_with($key, '/')
            && preg_match('#(^|/)\.\.(/|$)#', $key) !== 1;
    }

    /**
     * @return iterable<string> absolute paths of every file under $dir
     */
    private function filesUnder(string $dir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                yield $item->getPathname();
            }
        }
    }

    private function makeWorkDir(string $archivePath): string
    {
        $work = rtrim(dirname($archivePath), '/').'/.wayfindr-restore-work-'.bin2hex(random_bytes(6));

        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException("Could not create a restore working directory next to the archive: {$work}");
        }

        return $work;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        // No timeout: cleanup of a large extracted tree must not turn a
        // finished restore into a reported failure.
        (new Process(['rm', '-rf', $dir], timeout: null))->run();
    }
}
