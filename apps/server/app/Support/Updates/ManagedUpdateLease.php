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

        // This file carries no data. Its authorized readers need flock access,
        // not permission to rewrite it or chmod another process's inode.
        $handle = $node === false ? self::createFile($directory, $path) : @fopen($path, 'rb');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_update_state_unavailable');
        }

        try {
            clearstatcache(true, $path);
            $node = @lstat($path);
            $opened = @fstat($handle);

            if (! is_array($node) || ! is_array($opened) || ($node['mode'] & 0170000) !== 0100000
                || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino']) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            // Local flock permits an exclusive lock on a read-only descriptor.
            // Unsupported filesystem semantics refuse rather than run unlocked.
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('managed_update_busy');
            }

            return new self($handle, $path, $operationId);
        } catch (Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @return resource|false */
    private static function createFile(string $directory, string $path): mixed
    {
        // Initialize a fresh random name before publishing the fixed path.
        // Readers must never see a half-initialized root-owned inode, and a
        // collision must never change or replace the shared existing lock.
        $staging = $directory.'/.managed-upgrade-lock-'.bin2hex(random_bytes(16));
        if (! @mkdir($staging, 0700)) {
            return false;
        }

        $stagingNode = @lstat($staging);
        $temporary = $staging.'/'.bin2hex(random_bytes(16));
        $handle = false;
        $opened = false;

        try {
            // chmod has no descriptor/no-follow form in PHP. Keep its new
            // unpredictable target inside a private directory instead; do not
            // change process-wide umask on a threaded FrankenPHP runtime.
            if (! is_array($stagingNode) || ($stagingNode['mode'] & 0170000) !== 0040000
                || ($stagingNode['mode'] & 0077) !== 0) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            $handle = @fopen($temporary, 'x+b');

            if (! is_resource($handle)) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            $opened = @fstat($handle);
            $directoryNode = @lstat($directory);
            $node = @lstat($temporary);
            clearstatcache(true, $staging);
            $privateDirectory = @lstat($staging);

            if (! is_array($opened) || ! is_array($directoryNode) || ! is_array($node) || ! is_array($privateDirectory)
                || ($directoryNode['mode'] & 0170000) !== 0040000 || ($node['mode'] & 0170000) !== 0100000
                || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino']
                || $stagingNode['uid'] !== $opened['uid']
                || $privateDirectory['dev'] !== $stagingNode['dev'] || $privateDirectory['ino'] !== $stagingNode['ino']
                || ($privateDirectory['mode'] & 0170077) !== 0040000) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            if (! @chmod($temporary, 0640)) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            // Shared storage authorizes its group. Change only our new name,
            // without following a substituted symlink into an unrelated file.
            if ($opened['gid'] !== $directoryNode['gid']
                && (! function_exists('lchgrp') || ! @lchgrp($temporary, $directoryNode['gid']))) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            clearstatcache(true, $temporary);
            $node = @lstat($temporary);
            $initialized = @fstat($handle);
            clearstatcache(true, $directory);
            $currentDirectory = @lstat($directory);
            clearstatcache(true, $staging);
            $currentStaging = @lstat($staging);

            if (! is_array($node) || ! is_array($initialized) || ! is_array($currentDirectory) || ! is_array($currentStaging)
                || ($node['mode'] & 0170000) !== 0100000
                || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino']
                || $initialized['gid'] !== $directoryNode['gid']
                || $currentDirectory['dev'] !== $directoryNode['dev'] || $currentDirectory['ino'] !== $directoryNode['ino']
                || $currentStaging['dev'] !== $stagingNode['dev'] || $currentStaging['ino'] !== $stagingNode['ino']
                || ($currentStaging['mode'] & 0170077) !== 0040000) {
                throw new RuntimeException('managed_update_state_unavailable');
            }

            // Same-directory hard linking publishes without overwriting. If a
            // concurrent creator won, use its inode read-only after validation.
            if (! @link($temporary, $path)) {
                fclose($handle);
                clearstatcache(true, $path);
                $existing = @lstat($path);

                return is_array($existing) && ($existing['mode'] & 0170000) === 0100000
                    ? @fopen($path, 'rb') : false;
            }

            return $handle;
        } catch (Throwable $failure) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw $failure;
        } finally {
            // Never clean a substituted directory or file. A disturbed private
            // staging name can be retained safely; it contains no authority.
            clearstatcache(true, $staging);
            $cleanupDirectory = @lstat($staging);
            if (is_array($stagingNode) && is_array($cleanupDirectory) && is_array($opened)
                && $stagingNode['uid'] === $opened['uid']
                && $cleanupDirectory['dev'] === $stagingNode['dev'] && $cleanupDirectory['ino'] === $stagingNode['ino']
                && ($cleanupDirectory['mode'] & 0170000) === 0040000) {
                clearstatcache(true, $temporary);
                $cleanupFile = @lstat($temporary);
                if (is_array($opened) && is_array($cleanupFile) && ($cleanupFile['mode'] & 0170000) === 0100000
                    && $cleanupFile['dev'] === $opened['dev'] && $cleanupFile['ino'] === $opened['ino']) {
                    @unlink($temporary);
                }
                @rmdir($staging);
            }
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
