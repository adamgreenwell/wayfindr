<?php

namespace App\Support\Backup;

use App\Models\BackupRun;
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
 */
class BackupRunner
{
    public function __construct(private readonly BackupService $backups) {}

    /**
     * @return array{path: string, size: int, manifest: array<string, mixed>, remote: array<string, string>|null}
     */
    public function run(BackupRun $run, string $destination): array
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
