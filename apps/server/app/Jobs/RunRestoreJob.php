<?php

namespace App\Jobs;

use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupService;
use App\Support\Backup\RestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs a RESTORE off the request lifecycle (ADR 0011 slice 3b). A restore DROPs
 * and reloads the whole database, so — unlike a backup — its status CANNOT live
 * in a DB row: the restore would wipe it. It is written to the cache (Redis in
 * the shipped stack), which the database restore does not touch, and the
 * operator reads it after logging back in (the restore also clears the sessions
 * table, so everyone is logged out). The controller only offers the in-GUI
 * restore when the queue AND cache are durable across the restore — i.e. not
 * database-backed, or the job/status would delete itself mid-restore.
 *
 * Mirrors RunBackupJob: one attempt (a half-done restore must not silently
 * retry), a generous own timeout with fail-on-timeout, the dedicated redis
 * `backups` connection whose retry window exceeds the timeout, and the same
 * instance-wide lock so a restore and a backup never overlap.
 */
class RunRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The single cache key holding the latest restore's status (one at a time). */
    public const STATUS_KEY = 'wayfindr:restore:status';

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        private readonly string $archiveFilename,
        private readonly ?int $triggeredById = null,
        private readonly ?string $triggeredByName = null,
    ) {
        $this->timeout = (int) config('wayfindr.backup.job_timeout', 3600);

        // Redis-backed (config/queue.php) so the reservation survives the
        // database DROP SCHEMA/reload; it shares the single backup worker, which
        // also serialises a restore against a backup. (Set via onConnection, not
        // a typed property: the Queueable trait declares $connection untyped.)
        $this->onConnection('backups');
    }

    public function handle(RestoreService $restores, BackupService $backups): void
    {
        // Re-resolve the filename to a real local archive path — never trust a
        // raw path from the request; an unknown filename yields null.
        $path = $backups->resolveLocalArchivePath($this->archiveFilename);

        if ($path === null) {
            $this->record('failed', 'The selected archive is no longer available on the local disk.');

            return;
        }

        // Mutually exclusive with a running backup (and any other restore).
        $lock = Cache::lock(BackupRunner::LOCK_KEY, (int) config('wayfindr.backup.lock_ttl', 3900));

        try {
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            $this->record('failed', 'Could not acquire the backup/restore lock: '.$exception->getMessage());

            throw $exception;
        }

        if (! $acquired) {
            $this->record('failed', 'Skipped: a backup or restore was already running. Wait for it to finish, then try again.');

            return;
        }

        $this->record('running', 'Restore in progress…');

        try {
            // Quiesce writes for the duration of the restore, the way the CLI
            // procedure does manually. RestoreService commits the database
            // replacement BEFORE purging and re-copying attachment files, so a
            // concurrent upload committing in that window would leave a row whose
            // file the purge then deletes. Maintenance mode 503s HTTP and pauses
            // other queue workers (their daemon loop honours it), while this
            // already-running job continues; the down marker is on disk, so it
            // survives the database reload. Abort if we cannot quiesce — a live
            // restore is exactly the corruption risk we are guarding against.
            if (Artisan::call('down') !== 0) {
                $this->record('failed', 'Could not enter maintenance mode to quiesce writes before the restore; aborted to avoid corrupting data.');

                return;
            }

            // force: the operator has already confirmed in the GUI; the guard
            // that refuses to overwrite a populated install is exactly what they
            // acknowledged.
            $result = $restores->restore($path, force: true);

            $this->record('succeeded', $this->successMessage($result), $result);
        } catch (Throwable $exception) {
            $this->record('failed', $exception->getMessage());

            throw $exception;
        } finally {
            // Bring the app back up before releasing the lock. Best-effort, and
            // separately guarded so a failure of one still attempts the other.
            $this->bringUp();

            // A release failure must never mask the restore outcome already
            // recorded above; the lock's TTL expires it.
            try {
                $lock->release();
            } catch (Throwable $releaseException) {
                report($releaseException);
            }
        }
    }

    /**
     * Runs on a fresh instance (notably a timeout kill, which interrupts before
     * the catch can record). Only overwrite a status still 'running' — a
     * terminal outcome recorded in handle() stays.
     */
    public function failed(?Throwable $exception): void
    {
        // A timeout kill interrupts handle() before its finally can bring the app
        // back up, so do it here too (idempotent — up when not down is harmless).
        $this->bringUp();

        $status = Cache::get(self::STATUS_KEY);

        if (is_array($status) && ($status['status'] ?? null) === 'running') {
            $this->record(
                'failed',
                $exception?->getMessage() ?: 'The restore job was terminated (it may have exceeded the timeout). Check the queue worker.',
            );
        }
    }

    /** Best-effort exit from maintenance mode; a failure here must not throw. */
    private function bringUp(): void
    {
        try {
            Artisan::call('up');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The single source of truth for the restore status shape, static so the
     * controller can write the immediate "queued" status (and a dispatch-failure
     * status) before a worker exists to run the job.
     *
     * @param  array<string, mixed>|null  $result
     */
    public static function putStatus(
        string $status,
        string $message,
        string $archive,
        ?int $triggeredById = null,
        ?string $triggeredByName = null,
        ?array $result = null,
    ): void {
        $dangling = $result['integrity']['dangling'] ?? null;

        Cache::put(self::STATUS_KEY, [
            'status' => $status,
            'message' => $message,
            'archive' => $archive,
            'triggered_by_id' => $triggeredById,
            'triggered_by_name' => $triggeredByName,
            'version_skew' => (bool) ($result['version_skew'] ?? false),
            'archive_version' => $result['archive_version'] ?? null,
            'running_version' => $result['running_version'] ?? null,
            'dangling' => is_array($dangling) ? count($dangling) : 0,
            'finished_at' => $status === 'running' ? null : now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], now()->addDay());
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function record(string $status, string $message, ?array $result = null): void
    {
        self::putStatus($status, $message, $this->archiveFilename, $this->triggeredById, $this->triggeredByName, $result);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function successMessage(array $result): string
    {
        $parts = ['Restore complete.'];

        if ($result['version_skew'] ?? false) {
            $parts[] = 'The backup was taken on version '.($result['archive_version'] ?? '?')
                .' but this install runs '.($result['running_version'] ?? '?').' — run database migrations.';
        }

        $dangling = $result['integrity']['dangling'] ?? null;

        if (is_array($dangling) && $dangling !== []) {
            $parts[] = count($dangling).' attachment(s) referenced by the database are missing their files.';
        }

        return implode(' ', $parts);
    }
}
