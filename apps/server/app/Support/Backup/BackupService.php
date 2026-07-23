<?php

namespace App\Support\Backup;

use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Assembles a Wayfindr backup archive (ADR 0009): a Postgres dump plus the
 * LOCAL attachment binaries, bundled with a self-describing manifest. Remote
 * (S3/R2) attachment binaries are intentionally excluded — they are already
 * durable in the bucket, and the manifest records the storage disk so a
 * restore knows what the metadata still expects.
 */
class BackupService
{
    public function __construct(private readonly DatabaseDumper $dumper) {}

    /**
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}
     */
    public function create(string $destinationDir): array
    {
        // Owner-only for everything this produces — the working dump and the
        // final archive both hold the database and private attachment bytes.
        // Setting the umask (rather than a post-hoc chmod) means the archive
        // is never briefly world-readable while tar is still writing it.
        $previousUmask = umask(0077);

        try {
            return $this->assemble($destinationDir);
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}
     */
    private function assemble(string $destinationDir): array
    {
        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0700, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException("Backup destination is not writable: {$destinationDir}");
        }

        // Validate the backup prefix up front so a misconfigured one fails
        // before the (potentially long) dump, not after.
        $this->backupPrefix();

        $timestamp = Carbon::now();
        // Assemble on the DESTINATION volume, not /tmp: a large dump plus
        // attachment copies could overflow the container's small /tmp while the
        // mounted backup path has room, and same-filesystem makes the final
        // .partial -> archive rename atomic.
        $work = $this->makeWorkDir($destinationDir);

        try {
            // Dump the database FIRST, then copy binaries: on a live install
            // the only skew this ordering leaves is an attachment deleted
            // between the two steps (rarer than an upload, which the reverse
            // order would strand). A fully consistent snapshot means quiescing
            // writes — the documented maintenance posture (ADR 0009).
            $dumpLabel = $this->dumper->dump($work.'/database.sql');

            $archivable = $this->archivableLocalDiskNames();
            $localDisks = $this->copyLocalAttachments($work.'/attachments', $archivable);

            $manifest = $this->manifest($timestamp, $localDisks, $this->externalRowDisks($archivable), $dumpLabel);
            file_put_contents(
                $work.'/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );

            // Archives land under a per-install prefix on the local path too —
            // not just the remote disk — so two installs that share one host
            // backup directory keep separate namespaces and retention in one
            // never prunes the other's archives (ADR 0010).
            $archiveDir = rtrim($destinationDir, '/').'/'.$this->backupPrefix();

            if (! is_dir($archiveDir) && ! mkdir($archiveDir, 0700, true) && ! is_dir($archiveDir)) {
                throw new RuntimeException("Could not create backup archive directory: {$archiveDir}");
            }

            // A random suffix keeps two runs in the same second (retries,
            // overlapping schedules) from choosing the same name and silently
            // overwriting each other.
            $archive = $archiveDir
                .'/wayfindr-backup-'.$timestamp->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.tar.gz';

            // Build under a .partial name and rename only on success: a tar
            // that fails mid-stream (e.g. the backup filesystem fills up) must
            // never leave a truncated file under the final name, where it would
            // be indistinguishable from a good backup.
            $partial = $archive.'.partial';
            $this->tarWorkDir($work, $partial);

            if (! rename($partial, $archive)) {
                @unlink($partial);
                throw new RuntimeException("Could not finalize backup archive: {$archive}");
            }

            $size = (int) (@filesize($archive) ?: 0);

            return [
                'path' => $archive,
                'size' => $size,
                'manifest' => $manifest,
                // Mirror the finished archive offsite if a backup disk is
                // configured. Reports its own failure (rather than throwing) so
                // the command can say the local archive is intact AND that the
                // offsite push failed — never "success" when offsite did not
                // land (ADR 0010).
                'remote' => $this->uploadToRemote($archive, $size),
            ];
        } finally {
            $this->removeDir($work);
        }
    }

    /**
     * Age-based retention (ADR 0010): after a successful backup, prune archives
     * older than WAYFINDR_BACKUP_RETENTION_DAYS on both the local path and the
     * remote disk. Best-effort — a prune failure never fails the backup that
     * already succeeded. Only ever removes files that match the exact archive
     * naming, dated by the timestamp IN THE NAME (not mtime, which an upload or
     * copy resets), and only when the age is confidently known.
     *
     * @param  string|null  $keep  basename of the just-written archive, never pruned
     * @return array{days: int, local: int, remote: int}
     */
    public function pruneExpired(string $localDir, ?string $keep = null): array
    {
        $days = (int) config('wayfindr.backup.retention_days', 0);

        if ($days <= 0) {
            return ['days' => 0, 'local' => 0, 'remote' => 0];
        }

        $cutoff = Carbon::now()->subDays($days);

        return [
            'days' => $days,
            'local' => $this->pruneLocalArchives($localDir, $cutoff, $keep),
            'remote' => $this->pruneRemoteArchives($cutoff, $keep),
        ];
    }

    private function pruneLocalArchives(string $dir, Carbon $cutoff, ?string $keep): int
    {
        $removed = 0;

        try {
            // Only this install's prefix subdirectory — never the shared parent
            // — so a shorter window here cannot delete another install's
            // archives.
            $scoped = rtrim($dir, '/').'/'.$this->backupPrefix();

            if (! is_dir($scoped)) {
                return 0;
            }

            // Scan the directory literally (not via glob): a backup PATH or
            // PREFIX containing glob metacharacters ([ ] ? *) would make glob
            // treat the real directory as a pattern and scan the wrong place,
            // silently skipping retention. The filename is matched by
            // archiveTimestamp's strict regex instead.
            foreach (scandir($scoped) ?: [] as $name) {
                if ($name === $keep) {
                    continue;
                }

                $when = $this->archiveTimestamp($name);
                $path = $scoped.'/'.$name;

                if ($when !== null && $when->lt($cutoff) && is_file($path) && @unlink($path)) {
                    $removed++;
                }
            }
        } catch (Throwable) {
            // Best-effort, like the remote prune: a list/delete failure (e.g. an
            // unreadable mounted directory) must not fail a backup whose archive
            // and offsite upload already succeeded.
        }

        return $removed;
    }

    private function pruneRemoteArchives(Carbon $cutoff, ?string $keep): int
    {
        $diskName = trim((string) config('wayfindr.backup.disk'));

        // No remote, an unknown disk, or an attachment disk (never a backup
        // target): nothing to prune. Attachment disks are guarded so retention
        // can never reach into attachment storage.
        if ($diskName === ''
            || config("filesystems.disks.{$diskName}") === null
            || str_starts_with($diskName, 'attachments')) {
            return 0;
        }

        try {
            $disk = Storage::disk($diskName);
            $removed = 0;

            // List ONLY this install's prefix — never the whole bucket — so a
            // shorter window here cannot erase another install's archives that
            // happen to share the disk.
            foreach ($disk->files($this->backupPrefix()) as $path) {
                if ($keep !== null && basename($path) === $keep) {
                    continue;
                }

                $when = $this->archiveTimestamp(basename($path));

                if ($when !== null && $when->lt($cutoff) && $disk->delete($path)) {
                    $removed++;
                }
            }

            return $removed;
        } catch (Throwable) {
            // A list/delete error on the remote must not fail a backup that
            // already succeeded; the next run reconciles.
            return 0;
        }
    }

    /**
     * The per-install key prefix offsite archives are stored under and the ONLY
     * prefix retention prunes within. Explicit WAYFINDR_BACKUP_PREFIX wins;
     * otherwise a stable prefix derived from APP_KEY (unique per install,
     * hashed so nothing secret leaks into the path) keeps two installs that
     * share a backup disk from pruning each other's archives (ADR 0010).
     */
    private function backupPrefix(): string
    {
        $prefix = trim((string) config('wayfindr.backup.prefix'), '/');

        if ($prefix === '') {
            return 'wayfindr-backups/'.substr(hash('sha256', (string) config('app.key')), 0, 16);
        }

        // A prefix is a relative namespace UNDER the backup path/bucket, never
        // an escape from it. Reject traversal so a stray `..` cannot write
        // archives outside the destination or point retention at a sibling
        // install's directory (which would break the isolation guarantee).
        if (preg_match('#(^|/)\.\.(/|$)#', $prefix) === 1) {
            throw new RuntimeException("WAYFINDR_BACKUP_PREFIX must not contain '..' path segments; got [{$prefix}].");
        }

        return $prefix;
    }

    /**
     * The instant an archive was taken, parsed from its filename — the only
     * files retention will ever act on. Returns null for anything that is not
     * an exact wayfindr-backup-YYYYMMDD-HHMMSS-xxxxxx.tar.gz, so a foreign file
     * on a shared destination is never dated and never pruned.
     */
    private function archiveTimestamp(string $filename): ?Carbon
    {
        if (! preg_match('/^wayfindr-backup-(\d{8})-(\d{6})-[0-9a-f]{6}\.tar\.gz$/', $filename, $matches)) {
            return null;
        }

        $stamp = $matches[1].' '.$matches[2];

        try {
            // Matches the format the archive is written with (Carbon::now() in
            // the app timezone), so ages compare correctly.
            $when = Carbon::createFromFormat('Ymd His', $stamp, config('app.timezone') ?: 'UTC');
        } catch (Throwable) {
            return null;
        }

        // createFromFormat NORMALIZES an impossible date (e.g. 20250231 -> early
        // March) instead of failing, so a foreign file whose name only looks
        // like a timestamp could be assigned an old date and pruned. Require an
        // exact round-trip: only a genuine timestamp is ever dated.
        if (! $when instanceof Carbon || $when->format('Ymd His') !== $stamp) {
            return null;
        }

        return $when;
    }

    /**
     * Upload the finished archive to the configured backup disk, verifying the
     * object exists and its size matches. Returns null when no disk is
     * configured, {disk, key} on success, or {disk, error} on failure — a
     * configured-but-failed upload is a backup failure the command must surface,
     * but it never deletes the intact local archive (ADR 0010).
     *
     * @return array<string, string>|null
     */
    private function uploadToRemote(string $archivePath, int $localSize): ?array
    {
        $diskName = trim((string) config('wayfindr.backup.disk'));

        if ($diskName === '') {
            return null;
        }

        try {
            if (config("filesystems.disks.{$diskName}") === null) {
                throw new RuntimeException("[{$diskName}] is not a configured filesystem disk");
            }

            // Never mirror onto an attachment disk: wayfindr:sweep-orphaned-
            // attachments reconciles every `attachments*` disk and deletes any
            // object with no matching attachment row, so it would treat these
            // archives as orphans and delete the operator's offsite backups.
            if (str_starts_with($diskName, 'attachments')) {
                throw new RuntimeException("[{$diskName}] is an attachment disk; the orphaned-attachment sweep would delete backups written there — use a separate disk for WAYFINDR_BACKUP_DISK");
            }

            $disk = Storage::disk($diskName);
            // Namespace the object per-install so a shared bucket is safe: the
            // retention prune only ever reaches within this same prefix.
            $key = $this->backupPrefix().'/'.basename($archivePath);

            $stream = fopen($archivePath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('could not open the local archive to upload');
            }

            try {
                $written = $disk->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($written === false || ! $disk->exists($key)) {
                throw new RuntimeException('the upload did not complete');
            }

            // Verify the whole archive landed — a short/partial upload must not
            // be reported as a durable offsite copy.
            $remoteSize = $disk->size($key);

            if ($remoteSize !== $localSize) {
                throw new RuntimeException("uploaded {$remoteSize} of {$localSize} bytes");
            }

            return ['disk' => $diskName, 'key' => $key];
        } catch (Throwable $exception) {
            return ['disk' => $diskName, 'error' => $exception->getMessage()];
        }
    }

    /**
     * The archive is self-describing so a restore can warn on version skew,
     * put each binary back on the disk it came from, and name what the
     * metadata still expects for remote binaries.
     *
     * @param  list<string>  $localDisks  the local attachment disks captured in the archive
     * @param  list<string>  $remoteDisks  disks that rows depend on but are NOT in the archive (their binaries are in a bucket, or the disk is retired/unknown)
     * @return array<string, mixed>
     */
    public function manifest(Carbon $createdAt, array $localDisks, array $remoteDisks, string $dumpLabel): array
    {
        return [
            'wayfindr_version' => config('wayfindr.release.version') ?? 'unknown',
            'wayfindr_commit' => config('wayfindr.release.commit'),
            'created_at' => $createdAt->toISOString(),
            'attachment_storage_disk' => (string) config('wayfindr.attachments.storage_disk', 'attachments'),
            'includes_local_attachment_binaries' => $localDisks !== [],
            'local_attachment_disks' => $localDisks,
            // Rows homed on these disks have binaries the archive does NOT
            // carry — a restore must keep those buckets reachable (or accept
            // that a retired/unknown disk's binaries are gone).
            'external_attachment_disks' => $remoteDisks,
            'database_dump' => $dumpLabel,
        ];
    }

    /**
     * Copies EVERY binary on EVERY local attachment disk into the archive,
     * namespaced by disk name (`attachments/{disk}/{key}`) so a restore puts
     * each file back where its row's `storage_disk` expects it. This spans the
     * built-in `attachments` disk, any custom `attachments-*` local disk an
     * operator configured, and any local disk a row is homed on — because
     * per-row `storage_disk` (ADR 0007) means one install can hold binaries on
     * several disks. Gating on the *active* disk would silently drop the rest.
     * Remote (s3) disks are skipped; their binaries live in the bucket.
     *
     * @param  list<string>  $diskNames  the vetted local disks to capture
     * @return list<string> the local disks that had binaries captured
     */
    private function copyLocalAttachments(string $targetDir, array $diskNames): array
    {
        $captured = [];

        foreach ($diskNames as $diskName) {
            $storage = Storage::disk($diskName);
            $files = $storage->allFiles();

            if ($files === []) {
                continue;
            }

            foreach ($files as $file) {
                $destination = $targetDir.'/'.$diskName.'/'.$file;
                $parent = dirname($destination);

                if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                    throw new RuntimeException("Could not create attachment dir: {$parent}");
                }

                $stream = $storage->readStream($file);

                if ($stream === null) {
                    // A file listed a moment ago but gone now was concurrently
                    // deleted during a live backup — its row is (being) removed
                    // too, so do not abort the whole run. Whether the pre-delete
                    // dump still carries the row (a dangling row) is verified at
                    // RESTORE, which has the dump's actual rows as ground truth;
                    // backup cannot know the dump's exact row set without
                    // sharing pg_dump's snapshot, and a maintenance-posture
                    // backup has no window at all. A file that STILL exists but
                    // will not read is a real failure: fail loudly.
                    if (! $storage->exists($file)) {
                        continue;
                    }

                    throw new RuntimeException("Could not read attachment binary [{$diskName}:{$file}]; backup aborted so it is not silently incomplete.");
                }

                $bytes = stream_get_contents($stream);
                fclose($stream);

                if ($bytes === false) {
                    throw new RuntimeException("Could not read attachment binary [{$diskName}:{$file}]; backup aborted so it is not silently incomplete.");
                }

                // A short or failed write (quota, I/O, path length on the backup
                // volume) must abort, not ship a truncated binary in a
                // "successful" archive.
                $written = file_put_contents($destination, $bytes);

                if ($written === false || $written !== strlen($bytes)) {
                    throw new RuntimeException("Could not fully stage attachment binary [{$diskName}:{$file}] into the backup ({$written} of ".strlen($bytes).' bytes); backup aborted.');
                }
            }

            $captured[] = $diskName;
        }

        return $captured;
    }

    /**
     * The local disks a backup may read from and archive: every configured
     * `attachments*` disk plus any disk a row is homed on, filtered to local
     * drivers AND passed through the SAME safety judgment as uploads and the
     * retention sweep (dedicated `attachments*` name, configured, no exposure
     * markers). Without that gate, a row manually homed on a shared disk like
     * `local` would make the backup package that disk's unrelated files.
     *
     * @return list<string>
     */
    private function archivableLocalDiskNames(): array
    {
        $configured = collect(config('filesystems.disks', []))
            ->filter(fn ($disk, string $name): bool => str_starts_with($name, 'attachments'))
            ->keys();

        $rowHomed = ConversationMessageAttachment::query()
            ->distinct()
            ->pluck('storage_disk')
            ->filter();

        return $configured
            ->merge($rowHomed)
            ->unique()
            ->filter(fn (?string $name): bool => $name !== null
                && config("filesystems.disks.{$name}.driver") === 'local'
                && $this->isSafeAttachmentDisk($name))
            ->values()
            ->all();
    }

    /**
     * Disks that attachment ROWS point at but the archive does not carry —
     * everything row-homed except the vetted local disks we packaged. Covers
     * remote buckets, the retired/unknown, AND a shared disk the safety gate
     * refused: the restore needs all of them named so nothing is silently
     * assumed present.
     *
     * @param  list<string>  $archivable
     * @return list<string>
     */
    private function externalRowDisks(array $archivable): array
    {
        return ConversationMessageAttachment::query()
            ->distinct()
            ->pluck('storage_disk')
            ->filter()
            ->reject(fn (string $name): bool => in_array($name, $archivable, true))
            ->values()
            ->all();
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

    private function tarWorkDir(string $work, string $archive): void
    {
        $process = new Process(['tar', '-czf', $archive, '-C', $work, '.'], timeout: null);
        $process->run();

        if (! $process->isSuccessful()) {
            // A failed tar may have written a truncated file; remove it so no
            // partial (even under the .partial name) is left behind.
            @unlink($archive);

            throw new RuntimeException('Archiving failed: '.trim($process->getErrorOutput()));
        }
    }

    private function makeWorkDir(string $base): string
    {
        // A hidden dir under the destination so tar -C excludes it from the
        // archive it writes into the same parent, and it shares the volume.
        $work = rtrim($base, '/').'/.wayfindr-backup-work-'.bin2hex(random_bytes(6));

        if (! mkdir($work, 0700, true) && ! is_dir($work)) {
            throw new RuntimeException("Could not create working directory: {$work}");
        }

        return $work;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        // No timeout: this runs in the finally AFTER the archive is finalized,
        // and a large working tree could exceed Process's default 60s — a slow
        // cleanup must not turn a successful backup into a reported failure.
        $process = new Process(['rm', '-rf', $dir], timeout: null);
        $process->run();
    }
}
