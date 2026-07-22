<?php

namespace App\Support\Backup;

use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

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
     * @return array{path: string, size: int, manifest: array<string, mixed>}
     */
    public function create(string $destinationDir): array
    {
        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0700, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException("Backup destination is not writable: {$destinationDir}");
        }

        $timestamp = Carbon::now();
        $work = $this->makeWorkDir();

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

            $archive = rtrim($destinationDir, '/').'/wayfindr-backup-'.$timestamp->format('Ymd-His').'.tar.gz';
            $this->tarWorkDir($work, $archive);

            // The archive holds the full database dump and private attachment
            // bytes — owner-only, so a shared backup directory does not leak it
            // to other local users.
            @chmod($archive, 0600);

            return [
                'path' => $archive,
                'size' => (int) (@filesize($archive) ?: 0),
                'manifest' => $manifest,
            ];
        } finally {
            $this->removeDir($work);
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
                    // deleted during a live backup — its row is being removed
                    // too, so skip it rather than abort the whole run. A file
                    // that STILL exists but will not read is a real failure
                    // (permission/corruption): fail loudly, since the dump
                    // carries its metadata and a silent skip would strand it.
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

                file_put_contents($destination, $bytes);
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
            throw new RuntimeException('Archiving failed: '.trim($process->getErrorOutput()));
        }
    }

    private function makeWorkDir(): string
    {
        $work = sys_get_temp_dir().'/wayfindr-backup-'.bin2hex(random_bytes(6));

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

        $process = new Process(['rm', '-rf', $dir]);
        $process->run();
    }
}
