<?php

namespace App\Support\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Cache;
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
    /** Instance-wide serialization lock shared by every backup entry point. */
    private const LOCK_KEY = 'wayfindr:backup';

    public function __construct(private readonly BackupService $backups) {}

    /**
     * Returns the backup result, or null when this run was skipped because
     * another backup already held the lock (the run is finalized as failed with
     * a "Skipped" message so the caller and the history make the reason clear).
     *
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}|null
     */
    public function run(BackupRun $run, string $destination): ?array
    {
        // TTL bounds a lock leaked by a crashed/killed process so future backups
        // aren't blocked forever; it comfortably exceeds the backup timeout.
        $lock = Cache::lock(self::LOCK_KEY, (int) config('wayfindr.backup.job_timeout', 3600) + 120);

        if (! $lock->get()) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'message' => 'Skipped: another backup was already running. Wait for it to finish, then run again.',
                'finished_at' => now(),
            ]);

            return null;
        }

        try {
            return $this->perform($run, $destination);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}
     */
    private function perform(BackupRun $run, string $destination): array
    {
        try {
            $result = $this->backups->create($destination);
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

                return $result;
            }

            $pruned = $this->backups->pruneExpired($destination, basename($result['path']));

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
