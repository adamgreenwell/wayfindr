<?php

namespace App\Support\Updates;

use RuntimeException;
use Throwable;

/** A live process lease; durable update ownership belongs to the marker. */
final class ManagedUpdateLease
{
    /** @param resource $handle */
    private function __construct(
        private mixed $handle,
        private readonly string $path,
        private readonly ?string $operationId,
    ) {}

    /** Fixed-path factory: every typed lease owns a real exclusive flock. */
    public static function acquire(?string $operationId): self
    {
        $path = storage_path('framework/managed-upgrade.lock');
        $directory = storage_path('framework');

        if (! is_dir($directory) || is_link($directory) || ! is_readable($directory) || ! is_executable($directory)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        clearstatcache(true, $path);
        $node = @lstat($path);

        if ($node !== false && ($node['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        $handle = @fopen($path, 'c+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        try {
            clearstatcache(true, $path);
            $node = @lstat($path);
            $opened = @fstat($handle);

            if (! is_array($node) || ! is_array($opened) || ($node['mode'] & 0170000) !== 0100000
                || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino'] || ! @chmod($path, 0600)) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('managed_update_busy');
            }

            return new self($handle, $path, $operationId);
        } catch (Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    public function assertNormal(): void
    {
        $this->assertLive();

        if ($this->operationId !== null) {
            throw new RuntimeException('managed_update_busy');
        }

        if ((new ManagedUpdateGate)->active()) {
            throw new RuntimeException('managed_update_busy');
        }
    }

    public function assertProtective(string $operationId): void
    {
        $this->assertLive();

        if ($this->operationId !== $operationId) {
            throw new RuntimeException('managed_update_busy');
        }

        if (((new ManagedUpdateGate)->status()['operation_id'] ?? null) !== $operationId) {
            throw new RuntimeException('managed_update_busy');
        }
    }

    private function assertLive(): void
    {
        if (! is_resource($this->handle) || $this->path !== storage_path('framework/managed-upgrade.lock')) {
            throw new RuntimeException('managed_update_busy');
        }

        clearstatcache(true, $this->path);
        $node = @lstat($this->path);
        $opened = @fstat($this->handle);

        if (! is_array($node) || ! is_array($opened)
            || ($node['mode'] & 0170000) !== 0100000
            || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino']) {
            throw new RuntimeException('managed_update_state_unavailable');
        }
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
