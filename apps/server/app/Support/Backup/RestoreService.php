<?php

namespace App\Support\Backup;

use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentStorage;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        // The work dir is created next to the archive, and the restore purges
        // every local attachment disk — so an archive stored INSIDE an
        // attachment disk (a mistaken WAYFINDR_BACKUP_PATH) would have itself
        // and its extracted payload deleted mid-restore. Refuse it.
        $this->assertArchiveOutsideAttachmentDisks($archivePath);

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

            // The archive path is operator-supplied, so a tampered tarball is in
            // the threat model: reject any symlink or out-of-tree member before
            // reading or copying, so a crafted link cannot pull container files
            // onto an attachment disk. Fail fast, before anything destructive.
            $this->assertSafeArchiveTree($work);

            // GUARD (before anything destructive): refuse to overwrite an
            // install that already holds data unless explicitly forced. This
            // covers BOTH the database and the local attachment disks — the
            // restore wipes both (DROP SCHEMA, and the wholesale attachment
            // purge), so a reused/mis-mounted storage volume with files but an
            // empty database must still require the --force confirmation.
            if (! $force && $this->targetHasExistingData()) {
                throw new RuntimeException(
                    'The target database or a local attachment disk already contains data; '
                    .'restoring would REPLACE it. Re-run with --force to confirm you intend to '
                    .'overwrite this install.'
                );
            }

            $runningVersion = (string) (config('wayfindr.release.version') ?? 'unknown');
            $archiveVersion = (string) ($manifest['wayfindr_version'] ?? 'unknown');

            $localDisks = $this->localDisksFrom($manifest);

            // Replace the database with the dump (atomic — see the restorer).
            $this->restorer->restore($dump);

            // Put local attachment binaries back where their rows expect them.
            $attachments = $this->restoreAttachments($work, $localDisks);

            // The dump's rows are now ground truth: verify each locally-homed
            // row's binary actually landed on a usable disk, and report the rest.
            $integrity = $this->verifyAttachmentIntegrity(
                $work,
                $attachments['restored'],
                $attachments['unconfigured'],
            );

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
     * Read-only inspection before the destructive restore: extract only the
     * manifest (cheap — no attachment payload) so the caller can surface a
     * version skew while the operator can still abort, rather than discovering
     * it after the database has already been replaced (ADR 0009).
     *
     * @return array{archive_version: string, running_version: string, version_skew: bool}
     */
    public function preflight(string $archivePath): array
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException("Backup archive not found: {$archivePath}");
        }

        $this->assertArchiveOutsideAttachmentDisks($archivePath);

        $work = $this->makeWorkDir($archivePath);

        try {
            // Only the manifest member; tolerate its absence here — readManifest
            // gives the clean "not a Wayfindr backup" error.
            (new Process(['tar', '-xzf', $archivePath, '-C', $work, './manifest.json'], timeout: null))->run();

            $manifest = $this->readManifest($work);
            $archiveVersion = (string) ($manifest['wayfindr_version'] ?? 'unknown');
            $runningVersion = (string) (config('wayfindr.release.version') ?? 'unknown');

            return [
                'archive_version' => $archiveVersion,
                'running_version' => $runningVersion,
                'version_skew' => $archiveVersion !== $runningVersion,
            ];
        } finally {
            $this->removeDir($work);
        }
    }

    /**
     * Does the restore target already hold data the restore would destroy —
     * either in the database or on a local attachment disk it would purge? Both
     * are wiped by a restore, so both gate the --force confirmation.
     */
    private function targetHasExistingData(): bool
    {
        if ($this->databaseIsPopulated()) {
            return true;
        }

        foreach ($this->configuredLocalAttachmentDisks() as $diskName) {
            if (Storage::disk($diskName)->allFiles() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is there any real data in the current database? Checks EVERY table except
     * framework bookkeeping (the migration ledger and the ephemeral tables the
     * backup itself excludes), because the footgun this guard exists to catch is
     * a misconfigured DB_DATABASE/DB_HOST aimed at another populated Postgres,
     * or a partial install whose data is only in "other" tables — a five-table
     * check would wave those through and DROP SCHEMA them as if empty. A
     * freshly-migrated Wayfindr install has rows only in the ignored tables, so
     * it still restores without --force.
     */
    private function databaseIsPopulated(): bool
    {
        $ignore = $this->bookkeepingTables();

        // Bare table names (schemaQualified: false) so the comparison and
        // DB::table() work the same on Postgres and the SQLite test driver.
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (str_starts_with($table, 'sqlite_') || in_array($table, $ignore, true)) {
                continue;
            }

            try {
                if (DB::table($table)->exists()) {
                    return true;
                }
            } catch (Throwable) {
                // Unreadable table — treat as not populated by it.
            }
        }

        return false;
    }

    /**
     * Tables whose rows a fresh, unused install legitimately has: the migration
     * ledger, plus the ephemeral tables the backup already excludes from its
     * dump (sessions, cache, queue, password-reset). Their presence must not
     * read as "populated". Names come from config (with ?: defaults) so a
     * renamed table is still ignored.
     *
     * @return list<string>
     */
    private function bookkeepingTables(): array
    {
        $passwordBroker = config('auth.defaults.passwords') ?: 'users';

        return [
            config('database.migrations.table') ?: 'migrations',
            config('session.table') ?: 'sessions',
            config("auth.passwords.{$passwordBroker}.table") ?: 'password_reset_tokens',
            config('cache.stores.database.table') ?: 'cache',
            config('cache.stores.database.lock_table') ?: 'cache_locks',
            config('queue.connections.database.table') ?: 'jobs',
            config('queue.batching.table') ?: 'job_batches',
            config('queue.failed.table') ?: 'failed_jobs',
        ];
    }

    /**
     * Reconcile the local attachment disks to the archive's point in time,
     * mirroring the database (which the restore drops and reloads):
     *
     *  - PURGE every safe local attachment disk configured HERE first — not
     *    just the ones the archive carried. A remote-only backup, or one that
     *    used a different disk, restored over an install with local files must
     *    still clear those stale binaries, or they linger as orphans and ride
     *    into a later backup (backup packages every file on a local disk).
     *  - COPY the archive's binaries back onto the disks it carried. A disk the
     *    archive named but this install cannot host safely is reported
     *    (unconfigured) rather than written blindly to a shared disk; the
     *    integrity check then flags its rows as unavailable.
     *
     * @param  list<string>  $manifestLocalDisks
     * @return array{restored: list<string>, unconfigured: list<string>}
     */
    private function restoreAttachments(string $work, array $manifestLocalDisks): array
    {
        foreach ($this->configuredLocalAttachmentDisks() as $diskName) {
            $storage = Storage::disk($diskName);
            $existing = $storage->allFiles();

            // The local disk is configured throw => false, so a failed delete
            // (permissions, transient I/O) returns false rather than raising.
            // Ignoring it would let restore "succeed" with stale binaries still
            // on disk — the opposite of the wholesale replace — so fail loud.
            if ($existing !== [] && $storage->delete($existing) !== true) {
                throw new RuntimeException("Could not purge stale files on attachment disk [{$diskName}] during restore.");
            }
        }

        $restored = [];
        $unconfigured = [];

        foreach ($manifestLocalDisks as $diskName) {
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
     * Every safe local attachment disk configured on THIS install — the disks
     * a restore may clear and write. Mirrors the backup's configured-disk
     * enumeration: `attachments*` names, local driver, passed through the same
     * safety gate, so a shared disk is never in scope.
     *
     * @return list<string>
     */
    private function configuredLocalAttachmentDisks(): array
    {
        return collect(config('filesystems.disks', []))
            ->keys()
            ->filter(fn (string $name): bool => str_starts_with($name, 'attachments')
                && $this->isRestorableLocalDisk($name))
            ->values()
            ->all();
    }

    /**
     * The authoritative attachment check: for every attachment row the dump
     * restored, decide whether its binary is actually usable now.
     *
     *  1. The disk was RESTORED (cleared and rewritten from the archive) — the
     *     archive is ground truth: file present => verified, absent => DANGLING.
     *  2. The disk was in the archive but this install could not host it
     *     (unconfigured), OR it is a local attachment disk the archive did not
     *     carry at all: either way the binary is not on a usable disk —
     *     DANGLING, not verified and not "in a bucket". Reporting an unplaceable
     *     or missing local binary as verified/external would hide exactly the
     *     broken downloads this check exists to surface.
     *  3. Otherwise it is served from an external object store (or a disk this
     *     install lacks) — restore cannot verify it from the box.
     *
     * @param  list<string>  $restoredDisks
     * @param  list<string>  $unconfiguredDisks
     * @return array{skipped: bool, verified: int, dangling: list<array{id: int, disk: string, key: string}>, external: array<string, int>}
     */
    private function verifyAttachmentIntegrity(string $work, array $restoredDisks, array $unconfiguredDisks): array
    {
        // A dump from an older schema may not have the attachments table or its
        // columns yet — the version-skew case the operator fixes with a
        // post-restore migrate. Querying rows here would crash AFTER the
        // database is already replaced, so defer the check gracefully instead.
        if (! Schema::hasTable('conversation_message_attachments')
            || ! Schema::hasColumn('conversation_message_attachments', 'storage_disk')
            || ! Schema::hasColumn('conversation_message_attachments', 'storage_key')) {
            return ['skipped' => true, 'verified' => 0, 'dangling' => [], 'external' => []];
        }

        $restoredSet = array_flip($restoredDisks);
        $unconfiguredSet = array_flip($unconfiguredDisks);
        $verified = 0;
        $dangling = [];
        $external = [];

        ConversationMessageAttachment::query()
            ->select(['id', 'storage_disk', 'storage_key'])
            ->chunkById(500, function ($rows) use (&$verified, &$dangling, &$external, $work, $restoredSet, $unconfiguredSet): void {
                foreach ($rows as $row) {
                    $disk = (string) $row->storage_disk;
                    $key = (string) $row->storage_key;

                    if (isset($restoredSet[$disk])) {
                        if ($key !== '' && $this->keyIsSafe($key) && is_file($work.'/attachments/'.$disk.'/'.$key)) {
                            $verified++;
                        } else {
                            $dangling[] = ['id' => (int) $row->id, 'disk' => $disk, 'key' => $key];
                        }

                        continue;
                    }

                    // Unplaceable (disk not configured here) or a local disk the
                    // archive never carried: the binary is not usable — data loss,
                    // not bucket-resident.
                    if (isset($unconfiguredSet[$disk]) || $this->isRestorableLocalDisk($disk)) {
                        $dangling[] = ['id' => (int) $row->id, 'disk' => $disk, 'key' => $key];

                        continue;
                    }

                    $external[$disk] = ($external[$disk] ?? 0) + 1;
                }
            });

        return ['skipped' => false, 'verified' => $verified, 'dangling' => $dangling, 'external' => $external];
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

    /**
     * Refuse an archive that lives inside a local attachment disk root. The
     * restore purges those disks wholesale, and the work dir is created next to
     * the archive, so an archive stored there (a mistaken WAYFINDR_BACKUP_PATH
     * pointing into storage/app/private/attachments) would delete itself and its
     * extracted payload during the restore. Actionable: move the archive out.
     */
    private function assertArchiveOutsideAttachmentDisks(string $archivePath): void
    {
        $archiveReal = realpath($archivePath);

        if ($archiveReal === false) {
            return;
        }

        foreach ($this->configuredLocalAttachmentDisks() as $diskName) {
            $root = config("filesystems.disks.{$diskName}.root");

            if (! is_string($root) || $root === '') {
                continue;
            }

            $rootReal = realpath($root);

            if ($rootReal !== false && str_starts_with($archiveReal, rtrim($rootReal, '/').DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(
                    "The backup archive is inside the attachment disk [{$diskName}], which restore purges. "
                    .'Move the archive outside attachment storage (for example set WAYFINDR_BACKUP_PATH elsewhere) and retry.'
                );
            }
        }
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

    /**
     * Reject a tampered archive before we read from it: no symlink anywhere in
     * the extracted attachment tree, and every member's real path stays inside
     * that tree. This is what stops a crafted link (e.g. leak.bin -> /etc/passwd
     * or a symlinked directory) from making the copy read outside the archive.
     */
    private function assertSafeArchiveTree(string $work): void
    {
        $root = $work.'/attachments';

        if (is_link($root)) {
            throw new RuntimeException('Refusing to restore: the archive attachments path is a symlink.');
        }

        if (! is_dir($root)) {
            return;
        }

        $rootReal = realpath($root);

        if ($rootReal === false) {
            throw new RuntimeException('Could not resolve the archive attachments path.');
        }

        // SELF_FIRST yields each directory entry BEFORE its children, so a
        // symlinked directory is rejected here before the iterator would descend
        // into it.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Refusing to restore: the archive contains a symlink ['.$item->getPathname().'].');
            }

            $real = realpath($item->getPathname());

            if ($real === false || ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Refusing to restore: an archive member escapes the archive tree.');
            }
        }
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
