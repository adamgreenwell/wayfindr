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

    /** Holds the token of the one confirmed restore that may be queued/running. */
    public const PENDING_KEY = 'wayfindr:restore:pending';

    /** Set while THIS restore holds maintenance mode, so we only lift our own. */
    private const MAINTENANCE_OWNED_KEY = 'wayfindr:restore:maintenance-owned';

    /** True once THIS instance transitioned the app into maintenance mode, so the
     *  finally can lift it without a cache read (which could throw). */
    private bool $enteredMaintenance = false;

    /** True when the restore succeeded but the archive's schema predates the
     *  running code, so the site must stay down until the operator migrates. */
    private bool $keepMaintenanceForMigrations = false;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        private readonly string $archiveFilename,
        private readonly ?int $triggeredById = null,
        private readonly ?string $triggeredByName = null,
        private readonly ?string $pendingToken = null,
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
            //
            // Only transition — and thus own — maintenance mode if the app is not
            // ALREADY down (an operator or a deploy may have put it there); in
            // that case it is already quiesced, and we must not lift a window we
            // did not open.
            if (! app()->isDownForMaintenance()) {
                if (Artisan::call('down') !== 0) {
                    $this->record('failed', 'Could not enter maintenance mode to quiesce writes before the restore; aborted to avoid corrupting data.');

                    return;
                }

                // Record ownership two ways: an instance flag the finally reads
                // without touching the cache (so a cache outage can't strand the
                // site in maintenance), and a cache flag the fresh-instance
                // failed() callback can read.
                $this->enteredMaintenance = true;
                $this->rememberMaintenanceOwnership();

                // Maintenance mode only blocks NEW requests; let any HTTP request
                // that was already in flight when it engaged finish writing before
                // we touch the database.
                $this->drainInFlightRequests();
            }

            // force: the operator has already confirmed in the GUI; the guard
            // that refuses to overwrite a populated install is exactly what they
            // acknowledged.
            $result = $restores->restore($path, force: true);

            // A migration-relevant version skew means the restored schema predates
            // the running code; keep the site down so the operator migrates before
            // any request hits an incompatible schema (they were warned in the
            // preflight and the success message says how).
            $this->keepMaintenanceForMigrations = (bool) ($result['version_skew'] ?? false);

            $this->record('succeeded', $this->successMessage($result), $result);
        } catch (Throwable $exception) {
            $this->record('failed', $exception->getMessage());

            throw $exception;
        } finally {
            if ($this->keepMaintenanceForMigrations) {
                // Deliberately leave the site in maintenance so an incompatible
                // schema is never exposed. Hand it to the operator (relinquish our
                // ownership record) — they run migrations, then `php artisan up`.
                $this->forgetMaintenanceOwnership();
            } else {
                // Bring the app back up before releasing the lock. Uses the
                // instance flag — no cache read — so a cache outage can never
                // leave the site stuck in the window this job opened.
                $this->liftMaintenance($this->enteredMaintenance);
            }

            // A release failure must never mask the restore outcome already
            // recorded above; the lock's TTL expires it.
            try {
                $lock->release();
            } catch (Throwable $releaseException) {
                report($releaseException);
            }
        }
    }

    /** Wait for in-flight HTTP requests to drain after maintenance mode engages. */
    private function drainInFlightRequests(): void
    {
        $seconds = (int) config('wayfindr.backup.restore_drain_seconds', 5);

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Runs on a fresh instance (notably a timeout kill, which interrupts before
     * the catch can record). Only overwrite a status still 'running' — a
     * terminal outcome recorded in handle() stays.
     */
    public function failed(?Throwable $exception): void
    {
        // Lift maintenance FIRST — before any other cache access — so a cache
        // failure while recording the status can never leave the site stranded in
        // maintenance mode. This runs on a fresh instance, so the instance flag is
        // gone; fall back to the cache ownership record (which errs toward lifting
        // if it cannot be read, since an indefinite outage is worse than a
        // spurious up).
        $this->liftMaintenance($this->ownsMaintenanceViaCache());

        try {
            $status = Cache::get(self::STATUS_KEY);

            if (is_array($status) && ($status['status'] ?? null) === 'running') {
                $this->record(
                    'failed',
                    $exception?->getMessage() ?: 'The restore job was terminated (it may have exceeded the timeout). Check the queue worker.',
                );
            }
        } catch (Throwable $cacheException) {
            report($cacheException);
        }
    }

    /**
     * Lift maintenance mode iff this restore established it. Best-effort and fully
     * guarded: a cache failure here must never throw (that would mask the restore
     * outcome) nor leave the site stuck down.
     */
    private function liftMaintenance(bool $owns): void
    {
        if (! $owns) {
            return;
        }

        try {
            Artisan::call('up');
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            Cache::forget(self::MAINTENANCE_OWNED_KEY);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function rememberMaintenanceOwnership(): void
    {
        try {
            Cache::put(self::MAINTENANCE_OWNED_KEY, true, now()->addDay());
        } catch (Throwable $exception) {
            // The instance flag still covers the finally path; only the
            // fresh-instance failed() fallback is weakened.
            report($exception);
        }
    }

    /** Relinquish the ownership record without lifting maintenance (see the skew
     *  path) so nothing auto-lifts a window we intentionally leave up. */
    private function forgetMaintenanceOwnership(): void
    {
        try {
            Cache::forget(self::MAINTENANCE_OWNED_KEY);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function ownsMaintenanceViaCache(): bool
    {
        try {
            return (bool) Cache::get(self::MAINTENANCE_OWNED_KEY);
        } catch (Throwable $exception) {
            report($exception);

            // Cannot tell whether we own it; prefer lifting so a cache outage
            // never strands the site in maintenance mode.
            return true;
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
     * Atomically claim the single "a restore is pending" slot before enqueueing,
     * so a double-submit or two operators confirming can't queue two destructive
     * restores that the dedicated worker would then run one after the other.
     * Returns an ownership token to pass to the job, or null when a restore is
     * already pending or running.
     *
     * Modelled as a cache LOCK so the claim and its owner-checked release are each
     * a single atomic operation — a plain get-then-forget could delete a newer
     * operator's token that slipped in between. The TTL is generous enough to
     * cover a realistic queue wait (a restore may sit behind a running backup)
     * plus the restore's own run; it only expires if the job is lost entirely,
     * after which a retry is reasonable. (Actual execution is additionally
     * serialised by the backup lock, so an expired claim can never cause two
     * restores to run at once — only, at worst, back to back.)
     */
    public static function claimPending(): ?string
    {
        $lock = Cache::lock(self::PENDING_KEY, self::pendingTtl());

        return $lock->get() ? $lock->owner() : null;
    }

    /**
     * Release the pending claim, but only if it is still OURS — a job that timed
     * out after its slot was reclaimed by a newer restore must not free the new
     * one. restoreLock()->release() is an atomic owner-checked delete. A null
     * token (a direct call with no claim) is a no-op.
     */
    public static function releasePending(?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            Cache::restoreLock(self::PENDING_KEY, $token)->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function pendingTtl(): int
    {
        // Cover a restore waiting behind one long backup (lock_ttl) plus its own
        // run, with headroom; operators with deeper backup queues can raise it.
        return (int) config('wayfindr.backup.restore_pending_ttl', 2 * (int) config('wayfindr.backup.lock_ttl', 3900));
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function record(string $status, string $message, ?array $result = null): void
    {
        self::putStatus($status, $message, $this->archiveFilename, $this->triggeredById, $this->triggeredByName, $result);

        // A terminal outcome frees our pending-restore slot so the next one can be
        // confirmed; a still-running status keeps it held.
        if ($status !== 'running') {
            self::releasePending($this->pendingToken);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function successMessage(array $result): string
    {
        $parts = ['Restore complete.'];

        if ($result['version_skew'] ?? false) {
            $parts[] = 'The backup was taken on version '.($result['archive_version'] ?? '?')
                .' but this install runs '.($result['running_version'] ?? '?')
                .'. The site is being kept in maintenance mode — on the server run `php artisan migrate --force`, then `php artisan up`.';
        }

        $dangling = $result['integrity']['dangling'] ?? null;

        if (is_array($dangling) && $dangling !== []) {
            $parts[] = count($dangling).' attachment(s) referenced by the database are missing their files.';
        }

        return implode(' ', $parts);
    }
}
