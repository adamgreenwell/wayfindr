<?php

namespace App\Support\Backup;

use App\Models\BackupRun;
use App\Support\Updates\ManagedUpdateGate;
use App\Support\Updates\ManagedUpdateLease;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Runs a backup (dump + archive + optional offsite upload + retention prune) and
 * records its outcome on a BackupRun (ADR 0011 slice 3), so the queued
 * "run a backup now" AND the scheduled wayfindr:backup command share one code
 * path and both appear in the operator's backup history.
 *
 * The caller creates the BackupRun (so a job's timeout/kill handler can still
 * mark it) and passes it in. Mirrors BackupCommand's semantics: a configured-
 * but-failed offsite upload is a failure — recorded, not thrown, since the local
 * archive is intact — while a dump/archive error is recorded then re-thrown so
 * the queue/CLI sees it. Retention runs only after a fully successful backup.
 *
 * A single instance-wide lock lives here (not in a job/command) so BOTH entry
 * points serialize: a scheduled wayfindr:backup and an operator "run now" can't
 * run concurrent dump/archive/upload/prune passes, which would double the load
 * and race on the same archive directory.
 */
class BackupRunner
{
    /**
     * Instance-wide serialization lock shared by every backup entry point AND
     * the restore job (RunRestoreJob), so a restore and a backup — which both
     * touch the whole database — never run at the same time.
     */
    public const LOCK_KEY = 'wayfindr:backup';

    public function __construct(private readonly BackupService $backups, private readonly ?ManagedUpdateGate $updates = null) {}

    /**
     * Returns the backup result, or null when this run was skipped because
     * another backup already held the lock (the run is finalized as failed with
     * a "Skipped" message so the caller and the history make the reason clear).
     *
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}|null
     */
    public function run(BackupRun $run, string $destination, ?ManagedUpdateLease $lease = null): ?array
    {
        if ($lease !== null) {
            $lease->assertNormal();

            return $this->withBackupLock($run, $destination, null);
        }

        return $this->withLocks($run, $destination);
    }

    /** An operation-owned, synchronous backup; skipping is always a failure. */
    public function runProtective(BackupRun $run, string $destination, string $operationId): array
    {
        return $this->withLocks($run, $destination, $operationId)
            ?? throw new RuntimeException('protective_backup_busy');
    }

    private function withLocks(BackupRun $run, string $destination, ?string $operationId = null): ?array
    {
        try {
            $gate = $this->updates ?? app(ManagedUpdateGate::class);
            $lease = $operationId === null ? $gate->acquireNormal() : $gate->acquireProtective($operationId);
        } catch (Throwable $exception) {
            if ($operationId === null && isset($gate) && $this->ordinaryContention($gate, $exception)) {
                $this->recordFailure($run, 'Skipped: another backup or restore was already running. Wait for it to finish, then run again.');

                return null;
            }

            $this->recordFailure($run, $exception->getMessage());

            throw $exception;
        }

        try {
            return $this->withBackupLock($run, $destination, $operationId);
        } finally {
            $lease->release();
        }
    }

    private function ordinaryContention(ManagedUpdateGate $gate, Throwable $exception): bool
    {
        if ($exception->getMessage() !== 'managed_update_busy') {
            return false;
        }

        try {
            return ! $gate->active();
        } catch (Throwable) {
            return false;
        }
    }

    private function withBackupLock(BackupRun $run, string $destination, ?string $operationId): ?array
    {
        // The lock lifetime must exceed the longest a backup can take — for the
        // scheduled command that means its whole (untimed) run, not just the
        // queued job's timeout — while still bounding a lock leaked by a crashed
        // process so future backups aren't blocked forever. Operators with
        // multi-hour backups raise wayfindr.backup.lock_ttl to cover them.
        $lock = Cache::lock(self::LOCK_KEY, (int) config('wayfindr.backup.lock_ttl', 3900));

        try {
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            // Cache backend down: record the failure so the run isn't left stuck
            // 'running'. The CLI command only prints the error, and while a
            // queued job's failed() would also catch this, recording here covers
            // both entry points. Re-throw so the caller still registers a failure.
            $this->recordFailure($run, 'Could not acquire the backup lock: '.$exception->getMessage());

            throw $exception;
        }

        if (! $acquired) {
            $this->recordFailure($run, 'Skipped: another backup was already running. Wait for it to finish, then run again.');

            if ($operationId !== null) {
                throw new RuntimeException('protective_backup_busy');
            }

            return null;
        }

        try {
            return $this->perform($run, $destination, $operationId);
        } finally {
            // Releasing must never turn a finished backup into a failure:
            // perform() has already written the archive, uploaded, pruned, and
            // recorded the outcome. If the cache dropped mid-release, let the
            // lock's TTL expire it rather than masking a successful backup.
            try {
                $lock->release();
            } catch (Throwable $releaseException) {
                report($releaseException);
            }
        }
    }

    private function recordFailure(BackupRun $run, string $message): void
    {
        $run->update([
            'status' => BackupRun::STATUS_FAILED,
            'message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}
     */
    private function perform(BackupRun $run, string $destination, ?string $operationId): array
    {
        try {
            $result = $operationId === null
                ? $this->backups->create($destination)
                : $this->backups->createProtective($destination, $operationId);
            $remote = $result['remote'] ?? null;

            if (is_array($remote) && isset($remote['error'])) {
                $run->update([
                    'status' => BackupRun::STATUS_FAILED,
                    'archive_path' => $result['path'],
                    'size_bytes' => $result['size'],
                    'offsite_disk' => $remote['disk'] ?? null,
                    'message' => 'Offsite upload to ['.($remote['disk'] ?? '?').'] failed: '.$remote['error'].'. The local archive is intact at '.$result['path'].'.',
                    'finished_at' => now(),
                ]);

                if ($operationId !== null) {
                    throw new RuntimeException('protective_backup_offsite_failed');
                }

                return $result;
            }

            if ($operationId !== null) {
                $result['verification'] = app(BackupArchiveVerifier::class)->verify($result['path'], $result['manifest'], $operationId);
                $fixed = rtrim($destination, '/').'/archive.tar.gz';

                // link() publishes without overwriting an existing recovery
                // point, even if another process creates it after our check.
                if (! @link($result['path'], $fixed)) {
                    throw new RuntimeException('protective_backup_publish_failed');
                }

                @unlink($result['path']);
                $result['path'] = $fixed;
                $pruned = ['local' => 0, 'remote' => 0];
            } else {
                $pruned = $this->backups->pruneExpired($destination, basename($result['path']));
            }

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

            return $result;
        } catch (Throwable $exception) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }
}
