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

        $this->line('Backup complete: '.$result['path']);
        $this->line('  Size: '.$this->humanBytes($result['size']));
        $this->line('  Wayfindr version: '.$manifest['wayfindr_version']);
        $this->line('  Attachment storage: '.$manifest['attachment_storage_disk']
            .($manifest['includes_local_attachment_binaries']
                ? ' (local binaries included)'
                : ' (remote — binaries stay in the bucket)'));

        if (! $manifest['includes_local_attachment_binaries']) {
            $this->warn('  Attachment binaries are in your object store; this archive restores metadata that relies on that bucket.');
        }

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
