<?php

namespace App\Support\Updates;

use App\Support\Backup\BackupRunner;
use Illuminate\Support\Facades\Cache;
use JsonException;
use RuntimeException;
use Throwable;

/** Shared application admission. The host journal remains the update authority. */
final class ManagedUpdateGate
{
    public function markerPath(): string
    {
        return storage_path('framework/managed-upgrade.json');
    }

    public function active(): bool
    {
        $this->assertDirectory();
        clearstatcache(true, $this->markerPath());

        // Any node holds admission, including a corrupt file or dangling link.
        return @lstat($this->markerPath()) !== false;
    }

    /** @return array{schema: int, operation_id: ?string, held: bool, created_at: ?int} */
    public function status(): array
    {
        if (! $this->active()) {
            return ['schema' => 1, 'operation_id' => null, 'held' => false, 'created_at' => null];
        }

        $path = $this->markerPath();
        $node = @lstat($path);

        if (! is_array($node) || ($node['mode'] & 0170000) !== 0100000 || $node['size'] > 1024) {
            throw new RuntimeException('managed_update_state_invalid');
        }

        $bytes = @file_get_contents($path, false, null, 0, 1025);

        if (! is_string($bytes)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        if (strlen($bytes) > 1024) {
            throw new RuntimeException('managed_update_state_invalid');
        }

        try {
            $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('managed_update_state_invalid');
        }

        if (! is_array($value) || count($value) !== 3 || array_diff(array_keys($value), ['schema', 'operation_id', 'created_at']) !== []
            || ($value['schema'] ?? null) !== 1 || ! $this->uuid($value['operation_id'] ?? null)
            || ! is_int($value['created_at'] ?? null) || $value['created_at'] < 0) {
            throw new RuntimeException('managed_update_state_invalid');
        }

        // Only our frozen encoding is accepted. This also rejects duplicate
        // JSON keys that a permissive decoder would silently overwrite.
        if ($bytes !== json_encode(['schema' => 1, 'operation_id' => $value['operation_id'], 'created_at' => $value['created_at']], JSON_UNESCAPED_SLASHES)."\n") {
            throw new RuntimeException('managed_update_state_invalid');
        }

        return ['schema' => 1, 'operation_id' => $value['operation_id'], 'held' => true, 'created_at' => $value['created_at']];
    }

    /** @return array{schema: int, operation_id: ?string, held: bool, created_at: ?int} */
    public function enter(string $operationId): array
    {
        $this->assertUuid($operationId);
        $lease = $this->acquireFile(null);
        $legacyLock = null;

        try {
            $status = $this->status();

            if ($status['held']) {
                if ($status['operation_id'] !== $operationId) {
                    throw new RuntimeException('managed_update_busy');
                }

                return $status;
            }

            // Covers an ordinary operation started by an older application
            // process, which has not acquired the new shared file lease.
            $legacyLock = $this->legacyLock();
            $this->writeMarker(['schema' => 1, 'operation_id' => $operationId, 'created_at' => time()]);

            return $this->status();
        } finally {
            $this->releaseLegacy($legacyLock);
            $lease->release();
        }
    }

    /** @return array{schema: int, operation_id: ?string, held: bool, created_at: ?int} */
    public function release(string $operationId): array
    {
        $this->assertUuid($operationId);
        $lease = $this->acquireFile(null);

        try {
            $status = $this->status();

            if (! $status['held'] || $status['operation_id'] !== $operationId) {
                throw new RuntimeException('managed_update_busy');
            }

            if (! @unlink($this->markerPath())) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            $this->syncDirectory();

            return $this->status();
        } finally {
            $lease->release();
        }
    }

    public function acquireNormal(): ManagedUpdateLease
    {
        $lease = $this->acquireFile(null);

        try {
            $lease->assertNormal();

            return $lease;
        } catch (Throwable $failure) {
            $lease->release();

            throw $failure;
        }
    }

    public function acquireProtective(string $operationId): ManagedUpdateLease
    {
        $this->assertUuid($operationId);
        $lease = $this->acquireFile($operationId);

        try {
            $lease->assertProtective($operationId);

            return $lease;
        } catch (Throwable $failure) {
            $lease->release();

            throw $failure;
        }
    }

    private function acquireFile(?string $operationId): ManagedUpdateLease
    {
        return ManagedUpdateLease::acquire($operationId);
    }

    private function assertDirectory(): void
    {
        $directory = storage_path('framework');

        if (! is_dir($directory) || is_link($directory) || ! is_readable($directory) || ! is_executable($directory)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }
    }

    private function legacyLock(): mixed
    {
        try {
            $lock = Cache::lock(BackupRunner::LOCK_KEY, (int) config('wayfindr.backup.lock_ttl', 3900));

            if (! $lock->get()) {
                throw new RuntimeException('managed_update_busy');
            }

            return $lock;
        } catch (RuntimeException $failure) {
            if ($failure->getMessage() === 'managed_update_busy') {
                throw $failure;
            }

            throw new RuntimeException('managed_update_state_unavailable');
        } catch (Throwable) {
            throw new RuntimeException('managed_update_state_unavailable');
        }
    }

    private function releaseLegacy(mixed $lock): void
    {
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable $failure) {
            // A durable marker still denies admission when cache release fails.
            report($failure);
        }
    }

    /** @param array{schema: int, operation_id: string, created_at: int} $value */
    private function writeMarker(array $value): void
    {
        $temporary = storage_path('framework/.managed-upgrade-'.bin2hex(random_bytes(16)));
        $handle = @fopen($temporary, 'xb');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        try {
            $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            if (! @chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            fclose($handle);
            $handle = null;

            if (! @rename($temporary, $this->markerPath())) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            $this->syncDirectory();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function syncDirectory(): void
    {
        $handle = @fopen(storage_path('framework'), 'r');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        try {
            if (! fsync($handle)) {
                throw new RuntimeException('managed_update_state_unavailable');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertUuid(string $operationId): void
    {
        if (! $this->uuid($operationId)) {
            throw new RuntimeException('managed_update_request_invalid');
        }
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
