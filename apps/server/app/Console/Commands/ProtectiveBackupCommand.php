<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Support\Backup\BackupRunner;
use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Fixed host-helper subordinate operation; no caller-selected destinations. */
final class ProtectiveBackupCommand extends Command
{
    protected $signature = 'wayfindr:protective-backup {operation : The active managed-update operation UUID} {--json : Output the redacted verification receipt}';

    protected $description = 'Write and verify an operation-owned protective archive without pruning';

    public function handle(BackupRunner $runner, UpgradeGuard $guard): int
    {
        $operation = $this->argument('operation');
        $valid = is_string($operation)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $operation) === 1;

        try {
            if (! $valid || $guard->installationProfile() !== 'image') {
                throw new RuntimeException('protective_backup_invalid_operation');
            }

            $destination = storage_path('app/managed-updates/'.$operation);
            $this->assertDestination($destination);
            $ledgerPresent = $this->ledgerPresent();
            $run = BackupRun::query()->create([
                'status' => BackupRun::STATUS_RUNNING,
                'triggered_by_id' => null,
                'started_at' => now(),
            ]);
            $result = $runner->runProtective($run, $destination, $operation);
            $verified = $result['verification'];
            $source = $verified['source'];
            // Official releases bake their v-prefixed Git tag. The host plan
            // uses the canonical version, while the archive retains raw identity.
            $source['version'] = str_starts_with($source['version'], 'v') ? substr($source['version'], 1) : $source['version'];
            $remote = $result['remote'] ?? null;
            $configured = trim((string) config('wayfindr.backup.disk')) !== '';
            $receipt = [
                'schema' => 1,
                'operation_id' => $operation,
                'archive_sha256' => $verified['archive_sha256'],
                'manifest_sha256' => $verified['manifest_sha256'],
                'archive_bytes' => $verified['archive_bytes'],
                'source' => $source,
                'coverage' => [
                    'database' => true,
                    'local_attachments' => true,
                    'external_attachment_disks' => $verified['external_attachment_disks'],
                    'external_attachments_included' => false,
                    'external_attachments_verified' => false,
                    'erasure_ledger_present' => $ledgerPresent,
                    'offsite_configured' => $configured,
                    'offsite_uploaded' => is_array($remote) && isset($remote['key']) && ! isset($remote['error']),
                    'offsite_verification' => $configured ? 'existence-and-size' : 'not-configured',
                ],
                'pruning_suppressed' => true,
            ];
        } catch (Throwable $exception) {
            $allowed = [
                'managed_update_busy', 'managed_update_state_invalid', 'managed_update_state_unavailable',
                'managed_update_request_invalid', 'protective_backup_invalid_operation', 'protective_backup_busy',
                'protective_backup_offsite_failed', 'protective_backup_verification_failed',
                'protective_backup_publish_failed', 'protective_backup_local_coverage_failed',
                'protective_backup_destination_unavailable',
                'protective_backup_erasure_custody_unavailable',
            ];
            $reason = in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'protective_backup_failed';

            if ($this->option('json')) {
                $this->line(json_encode(['schema' => 1, 'operation_id' => $valid ? $operation : null, 'status' => 'failed', 'reason' => $reason], JSON_THROW_ON_ERROR));
            } else {
                $this->error('Protective backup failed ('.$reason.'). The managed update remains held.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Protective archive verified; retention was suppressed. External attachments and separate key/configuration/erasure custody remain distinct recovery dependencies.');
        }

        return self::SUCCESS;
    }

    private function assertDestination(string $destination): void
    {
        foreach ([storage_path('app'), storage_path('app/managed-updates'), $destination] as $directory) {
            if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                throw new RuntimeException('protective_backup_destination_unavailable');
            }
        }

        if (@lstat($destination.'/archive.tar.gz') !== false) {
            throw new RuntimeException('protective_backup_destination_unavailable');
        }
    }

    private function ledgerPresent(): bool
    {
        $path = storage_path('app/erasure-ledger');

        if (rtrim((string) config('wayfindr.erasure.ledger_path'), '/') !== $path) {
            throw new RuntimeException('protective_backup_erasure_custody_unavailable');
        }

        $node = @lstat($path);

        if ($node === false) {
            return false;
        }

        // The managed VM adapter supports the official fixed ledger location.
        // A custom ledger path is a separate custody adapter, never guessed.
        if (($node['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('protective_backup_erasure_custody_unavailable');
        }

        return true;
    }
}
