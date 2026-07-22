<?php

namespace App\Support\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
    public const ATTACHMENTS_DISK = 'attachments';

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
            $dumpLabel = $this->dumper->dump($work.'/database.sql');

            $includesLocalBinaries = $this->copyLocalAttachments($work.'/attachments');

            $manifest = $this->manifest($timestamp, $includesLocalBinaries, $dumpLabel);
            file_put_contents(
                $work.'/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );

            $archive = rtrim($destinationDir, '/').'/wayfindr-backup-'.$timestamp->format('Ymd-His').'.tar.gz';
            $this->tarWorkDir($work, $archive);

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
     * The archive is self-describing so a restore can warn on version skew and
     * name what the metadata still expects for its attachment binaries.
     *
     * @return array<string, mixed>
     */
    public function manifest(Carbon $createdAt, bool $includesLocalBinaries, string $dumpLabel): array
    {
        return [
            'wayfindr_version' => config('wayfindr.release.version') ?? 'unknown',
            'wayfindr_commit' => config('wayfindr.release.commit'),
            'created_at' => $createdAt->toISOString(),
            'attachment_storage_disk' => (string) config('wayfindr.attachments.storage_disk', 'attachments'),
            'includes_local_attachment_binaries' => $includesLocalBinaries,
            'database_dump' => $dumpLabel,
        ];
    }

    /**
     * Copies the local attachment binaries into the archive tree. Returns
     * false (and copies nothing) when the active attachment disk is not a
     * local one — those binaries live in the object store, not here.
     */
    private function copyLocalAttachments(string $targetDir): bool
    {
        $disk = (string) config('wayfindr.attachments.storage_disk', 'attachments');

        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return false;
        }

        $storage = Storage::disk(self::ATTACHMENTS_DISK);
        $files = $storage->allFiles();

        if ($files === []) {
            // Nothing to copy yet, but the install IS local-storage — the flag
            // reflects intent so a restore into a remote install can warn.
            return true;
        }

        if (! is_dir($targetDir) && ! mkdir($targetDir, 0700, true) && ! is_dir($targetDir)) {
            throw new RuntimeException("Could not create attachment staging dir: {$targetDir}");
        }

        foreach ($files as $file) {
            $destination = $targetDir.'/'.$file;
            $parent = dirname($destination);

            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException("Could not create attachment dir: {$parent}");
            }

            $stream = $storage->readStream($file);

            if ($stream === null) {
                continue;
            }

            file_put_contents($destination, stream_get_contents($stream));
            fclose($stream);
        }

        return true;
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
