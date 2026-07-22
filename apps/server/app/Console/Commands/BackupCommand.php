<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'wayfindr:backup
        {--path= : Directory to write the archive to (defaults to wayfindr.backup.path)}';

    protected $description = 'Write a restorable backup archive (Postgres dump + local attachment binaries).';

    public function handle(BackupService $backups): int
    {
        $destination = trim((string) $this->option('path')) ?: (string) config('wayfindr.backup.path');

        $this->info('Writing Wayfindr backup to '.$destination);

        try {
            $result = $backups->create($destination);
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];

        $disk = $manifest['attachment_storage_disk'];
        $newUploadsAreLocal = config("filesystems.disks.{$disk}.driver") === 'local';

        $this->line('Backup complete: '.$result['path']);
        $this->line('  Size: '.$this->humanBytes($result['size']));
        $this->line('  Wayfindr version: '.$manifest['wayfindr_version']);
        $this->line('  New uploads → '.$disk.($newUploadsAreLocal ? ' (local)' : ' (remote object store)'));
        $this->line('  Local attachment binaries: '.($manifest['includes_local_attachment_binaries']
            ? 'included in archive'
            : 'none on the local disk'));

        $external = $manifest['external_attachment_disks'] ?? [];

        if ($external !== []) {
            $this->warn('  Some rows depend on binaries NOT in this archive (['.implode(', ', $external).']); keep those object stores reachable to restore fully.');
        } elseif (! $newUploadsAreLocal) {
            $this->warn('  New uploads go to the object store; those binaries stay in the bucket, not this archive.');
        }

        // A live backup can capture a row moments after its binary was deleted;
        // restore verifies attachment integrity against the archive, and a
        // maintenance-posture backup avoids the window entirely (ADR 0009).
        $this->line('  For a guaranteed-consistent snapshot, back up with writes quiesced; restore verifies attachment integrity either way.');

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
