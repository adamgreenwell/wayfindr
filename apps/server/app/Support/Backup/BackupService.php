<?php

namespace App\Support\Backup;

use App\Models\ConversationMessageAttachment;
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

            $localDisks = $this->copyLocalAttachments($work.'/attachments');

            $manifest = $this->manifest($timestamp, $localDisks, $dumpLabel);
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
     * The archive is self-describing so a restore can warn on version skew,
     * put each binary back on the disk it came from, and name what the
     * metadata still expects for remote binaries.
     *
     * @param  list<string>  $localDisks  the local attachment disks captured
     * @return array<string, mixed>
     */
    public function manifest(Carbon $createdAt, array $localDisks, string $dumpLabel): array
    {
        return [
            'wayfindr_version' => config('wayfindr.release.version') ?? 'unknown',
            'wayfindr_commit' => config('wayfindr.release.commit'),
            'created_at' => $createdAt->toISOString(),
            'attachment_storage_disk' => (string) config('wayfindr.attachments.storage_disk', 'attachments'),
            'includes_local_attachment_binaries' => $localDisks !== [],
            'local_attachment_disks' => $localDisks,
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
     * @return list<string> the local disks that had binaries captured
     */
    private function copyLocalAttachments(string $targetDir): array
    {
        $captured = [];

        foreach ($this->localAttachmentDisks() as $diskName) {
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

                // A listed file that cannot be read is a real gap: the dump
                // still carries its metadata, so a silent skip would ship an
                // archive that restores a dangling row. Fail the whole backup.
                $stream = $storage->readStream($file);

                if ($stream === null) {
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
     * Every configured `attachments*` disk with a local driver, plus any local
     * disk a row is homed on — the same disk universe the retention sweep
     * reconciles, filtered to the local ones a backup can read from.
     *
     * @return list<string>
     */
    private function localAttachmentDisks(): array
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
                && config("filesystems.disks.{$name}.driver") === 'local')
            ->values()
            ->all();
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
