<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Support\Backup\BackupRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    /**
     * The run row is created by the dispatcher and its id passed here, so it is
     * part of the serialized payload — the failed() callback runs on a fresh
     * instance rebuilt from that payload (mutations made during handle() would
     * be lost), so it must not depend on state set inside handle().
     */
    public function __construct(private readonly int $backupRunId)
    {
        $this->timeout = (int) config('wayfindr.backup.job_timeout', 3600);

        // A dedicated connection whose retry/visibility window exceeds this
        // job's timeout (see config/queue.php), so a slow backup is never
        // re-released to a second worker mid-run. Without this, the default 90s
        // retry_after would hand a >90s backup to another worker and — under
        // tries=1 — fail it before handle() while it is actually succeeding.
        // (Set here, not as a typed property: the Queueable trait declares
        // $connection untyped, so a typed redeclaration would fatal.)
        $this->onConnection('backups');
    }

    public function handle(BackupRunner $runner): void
    {
        $run = BackupRun::query()->find($this->backupRunId);

        // Deleted, or already finalized (e.g. by an earlier attempt). The runner
        // owns the instance-wide lock, so an overlapping backup is finalized as
        // skipped there — this path just delegates.
        if ($run === null || $run->status !== BackupRun::STATUS_RUNNING) {
            return;
        }

        $runner->run($run, (string) config('wayfindr.backup.path'));
    }

    /**
     * Called when the job fails OUTSIDE handle()'s normal path — notably a
     * worker timeout/kill, which interrupts the run before BackupRunner can
     * record the outcome. Mark a run left in 'running' as failed so the GUI
     * never shows it stuck. (A run BackupRunner already failed is left as-is.)
     */
    public function failed(?Throwable $exception): void
    {
        $run = BackupRun::query()->find($this->backupRunId);

        if ($run && $run->status === BackupRun::STATUS_RUNNING) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'message' => $exception?->getMessage() ?: 'The backup job was terminated (it may have exceeded the timeout). Check the queue worker and retry.',
                'finished_at' => now(),
            ]);
        }
    }
}
