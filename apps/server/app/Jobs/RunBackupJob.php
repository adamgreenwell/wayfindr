<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Support\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a backup off the request lifecycle (ADR 0011 slice 3): the dump +
 * archive + optional offsite upload + retention prune can be slow, so the
 * operator's "run a backup now" queues this. It records progress and the
 * outcome to backup_runs so the GUI can show it without opening a terminal.
 *
 * Mirrors BackupCommand: a configured-but-failed offsite upload is a failure
 * (the operator asked for offsite), and retention runs only after full success.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A backup is a one-shot operation — retrying a killed run would create a
     * duplicate archive, which is worse than a missed run the operator can
     * simply re-trigger.
     */
    public int $tries = 1;

    /**
     * The job's own timeout, well beyond the default 90s worker timeout, so a
     * slow dump/archive/upload is not SIGALRM-killed mid-run (a job's timeout
     * overrides the worker's --timeout for that job).
     */
    public int $timeout;

    public function __construct(private readonly ?int $triggeredById = null)
    {
        $this->timeout = (int) config('wayfindr.backup.job_timeout', 3600);
    }

    /**
     * Only one backup runs at a time instance-wide, and — combined with the
     * generous timeout — a queue retry_after re-release cannot start a duplicate
     * backup: an overlapping attempt hits the held lock and is discarded.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('wayfindr:backup'))
                ->dontRelease()
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(BackupService $backups): void
    {
        $run = BackupRun::query()->create([
            'status' => BackupRun::STATUS_RUNNING,
            'triggered_by_id' => $this->triggeredById,
            'started_at' => now(),
        ]);

        $destination = (string) config('wayfindr.backup.path');

        try {
            $result = $backups->create($destination);
            $remote = $result['remote'] ?? null;

            // A configured-but-failed offsite upload is a backup failure — the
            // operator asked for offsite — but the local archive is intact.
            if (is_array($remote) && isset($remote['error'])) {
                $run->update([
                    'status' => BackupRun::STATUS_FAILED,
                    'archive_path' => $result['path'],
                    'size_bytes' => $result['size'],
                    'offsite_disk' => $remote['disk'] ?? null,
                    'message' => 'Offsite upload to ['.($remote['disk'] ?? '?').'] failed: '.$remote['error'].'. The local archive is intact at '.$result['path'].'.',
                    'finished_at' => now(),
                ]);

                return;
            }

            // Retention runs only after a fully successful backup, and never
            // prunes the just-written archive.
            $pruned = $backups->pruneExpired($destination, basename($result['path']));

            $run->update([
                'status' => BackupRun::STATUS_SUCCEEDED,
                'archive_path' => $result['path'],
                'size_bytes' => $result['size'],
                'offsite_disk' => is_array($remote) ? ($remote['disk'] ?? null) : null,
                'offsite_key' => is_array($remote) ? ($remote['key'] ?? null) : null,
                'pruned_local' => (int) ($pruned['local'] ?? 0),
                'pruned_remote' => (int) ($pruned['remote'] ?? 0),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception; // let the queue record/retry the failure
        }
    }
}
