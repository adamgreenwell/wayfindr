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

        // Neutral phrasing until the offsite mirror (if any) is confirmed — the
        // "Backup complete" success marker prints only on full success below, so
        // log-based alerting never sees success on a failed offsite upload.
        $this->line('Backup archive written: '.$result['path']);
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

        // Offsite mirror (ADR 0010). A configured-but-failed upload is a backup
        // failure — the operator asked for offsite, so do not report success —
        // but the local archive is intact.
        $remote = $result['remote'] ?? null;

        if (is_array($remote) && isset($remote['error'])) {
            $this->error('  Offsite upload to ['.$remote['disk'].'] FAILED: '.$remote['error'].'.');
            $this->line('  The local archive is intact at '.$result['path'].'; fix the disk config and retry.');

            return self::FAILURE;
        }

        if (is_array($remote) && isset($remote['key'])) {
            $this->line('  Offsite copy uploaded to ['.$remote['disk'].']: '.$remote['key']);
        }

        // Retention runs only after a fully successful backup (reached only past
        // the offsite-failure return above), so a bad run can never prune the
        // last good history (ADR 0010).
        // Pass the just-written archive so retention can never delete it, even
        // if a slow backup ran past a small window (ADR 0010).
        $pruned = $backups->pruneExpired($destination, basename($result['path']));

        if ($pruned['days'] > 0 && ($pruned['local'] + $pruned['remote']) > 0) {
            $this->line(sprintf(
                '  Retention: pruned %d local and %d offsite archive(s) older than %d day(s).',
                $pruned['local'],
                $pruned['remote'],
                $pruned['days'],
            ));
        }

        $this->info('Backup complete.');

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
