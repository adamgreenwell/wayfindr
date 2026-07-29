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
use Illuminate\Support\Str;
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

    /** True when the site must be LEFT in maintenance for the operator rather than
     *  auto-lifted — a version skew, or any failure that may have partially
     *  applied. Only a fully-clean restore brings the site back up automatically. */
    private bool $keepMaintenance = false;

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
        // If our pending lease lapsed (a long queue wait) and a NEWER restore has
        // since claimed the slot, this one has been superseded — abort before
        // doing anything destructive, so two different archives can't restore back
        // to back. (Concurrent execution is separately impossible: the backup lock
        // below serialises it.)
        if (! $this->stillHoldsPendingClaim()) {
            $this->record('failed', 'This restore was superseded by a newer one, or its confirmation lapsed after a long wait, and was not run. Confirm again to retry.');

            return;
        }

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

        try {
            // Inside the guarded region so a cache failure recording status still
            // reaches the finally that releases the lock — otherwise the lock (and
            // the pending claim) would sit held until their multi-hour TTLs.
            $this->record('running', 'Restore in progress…');

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
                    $this->record('failed', 'Could not enter maintenance mode to quiesce writes before the restore; aborted to avoid corrupting data. '.$this->workerRestartHint());

                    return;
                }

                // Record ownership two ways: an instance flag the finally reads
                // without touching the cache (so a cache outage can't strand the
                // site in maintenance), and a cache flag we clear on the way out.
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

            // A version skew means the restored schema and the running code may
            // not match (either direction); keep the site down so the operator
            // reconciles them before any request hits it (they were warned in the
            // preflight and the success message says how). An INDETERMINATE pair
            // (either side carries no release identity) is treated the same way —
            // we cannot prove the schema matches, and a destructive restore is
            // exactly where an unprovable assumption should fail safe.
            $this->keepMaintenance = (bool) ($result['version_skew'] ?? false)
                || (bool) ($result['version_indeterminate'] ?? false);

            $this->record('succeeded', $this->successMessage($result), $result);
        } catch (Throwable $exception) {
            // A failure here may be AFTER the database transaction committed (the
            // attachment purge/copy phase, or a timeout during it), leaving a
            // PARTIALLY applied restore. Keep the site down for the operator to
            // verify rather than exposing an inconsistent install.
            $this->keepMaintenance = true;
            $this->record('failed', $this->failureMessage($exception));

            throw $exception;
        } finally {
            if ($this->keepMaintenance) {
                // Deliberately leave the site in maintenance (skew, or a possibly
                // partial failure) and hand it to the operator; relinquish our
                // ownership record so nothing auto-lifts it.
                $this->forgetMaintenanceOwnership();
            } else {
                // A fully-clean restore: bring the app back up. Uses the instance
                // flag — no cache read — so a cache outage can never leave the
                // site stuck in the window this job opened.
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
        // Do NOT lift maintenance here. A failed or timed-out restore may have
        // partially applied (the database can be replaced before the attachment
        // phase finishes), so the site stays down for the operator to verify.
        // Relinquish our ownership record so a later restore starts clean; the
        // site remains down because we never call `up`.
        $this->forgetMaintenanceOwnership();

        try {
            $status = Cache::get(self::STATUS_KEY);

            if (is_array($status) && ($status['status'] ?? null) === 'running') {
                $this->record('failed', $this->failureMessage($exception));
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
     * A plain readable token via Cache::add (atomic set-if-absent), NOT a cache
     * lock: the token has to be READABLE across drivers so the job can validate
     * ownership on start (stillHoldsPendingClaim) — a cache lock keeps its owner
     * where Cache::get can't portably read it (the array store, e.g., holds it
     * apart from normal storage). The TTL is generous enough to cover a realistic
     * queue wait (a restore may sit behind a running backup) plus the restore's
     * own run; it only expires if the job is lost entirely, after which a retry is
     * reasonable. Actual execution is additionally serialised by the backup lock,
     * so an expired claim can never cause two restores to run at once.
     */
    public static function claimPending(): ?string
    {
        $token = (string) Str::uuid();

        return Cache::add(self::PENDING_KEY, $token, self::pendingTtl()) ? $token : null;
    }

    /**
     * Release the pending claim, but only if it is still OURS — a job that timed
     * out after its slot was reclaimed by a newer restore must not free the new
     * one. The get→forget window is tiny and backstopped by stillHoldsPendingClaim
     * (a superseded job aborts; an absent claim is treated as safe to run), so it
     * can never let two restores run. A null token (a direct call) is a no-op.
     */
    public static function releasePending(?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            if (Cache::get(self::PENDING_KEY) === $token) {
                Cache::forget(self::PENDING_KEY);
            }
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
     * Whether this job still holds the pending-restore lease. We require the slot
     * to hold OUR EXACT token: a different token means a newer restore superseded
     * us, and an ABSENT token means our lease lapsed (a long queue wait) — in
     * which case another operator may have already claimed and enqueued a newer
     * restore, so we must not proceed as if we still had permission. Either way
     * the job aborts and the operator re-confirms. A cache READ failure is
     * non-fatal (the backup lock still serialises execution, and the lock
     * acquisition below would fail cleanly on a real outage anyway); a null
     * pendingToken is a direct/programmatic call (tests) with nothing to validate.
     */
    private function stillHoldsPendingClaim(): bool
    {
        if ($this->pendingToken === null) {
            return true;
        }

        try {
            return Cache::get(self::PENDING_KEY) === $this->pendingToken;
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
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

        if ($result['version_indeterminate'] ?? false) {
            $parts[] = 'The versions could NOT be verified (archive: '.($result['archive_version'] ?? '?')
                .', this install: '.($result['running_version'] ?? '?')
                .') — this install carries no release identity, so a schema mismatch cannot be ruled out. '
                .'The site is being kept in maintenance mode. On the server, confirm the schema is current '
                .'(`php artisan migrate --force` is safe if it is already up to date), then run `php artisan up`. '
                .'Set WAYFINDR_VERSION so future restores can verify this automatically.';
        } elseif ($result['version_skew'] ?? false) {
            // Direction-neutral: version strings may not be reliably comparable
            // (tags vs commits), and a NEWER archive can't be fixed by migrating
            // older code — so name both remedies.
            $parts[] = 'The backup was taken on version '.($result['archive_version'] ?? '?')
                .' but this install runs '.($result['running_version'] ?? '?')
                .'. The site is being kept in maintenance mode so the schema and code can'."'".'t mismatch. On the server, make them compatible — if the backup is OLDER, run `php artisan migrate --force`; if it is NEWER, deploy a matching or newer release — then run `php artisan up`.';
        }

        $dangling = $result['integrity']['dangling'] ?? null;

        if (is_array($dangling) && $dangling !== []) {
            $parts[] = count($dangling).' attachment(s) referenced by the database are missing their files.';
        }

        $parts[] = $this->workerRestartHint();

        return implode(' ', $parts);
    }

    private function failureMessage(?Throwable $exception): string
    {
        $reason = $exception?->getMessage() ?: 'The restore job was terminated (it may have exceeded the timeout). Check the queue worker.';

        return 'Restore failed: '.$reason
            .' The site has been left in maintenance mode in case the restore applied only partially — verify the database and attachments, then run `php artisan up` (or re-run the restore). '
            .$this->workerRestartHint();
    }

    private function workerRestartHint(): string
    {
        return 'Restart the queue and scheduler workers you stopped: `docker compose start queue scheduler`.';
    }
}
