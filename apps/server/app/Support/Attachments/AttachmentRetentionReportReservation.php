<?php

declare(strict_types=1);

namespace App\Support\Attachments;

use RuntimeException;

/**
 * Exclusively reserve and atomically publish one retention-measurement report.
 */
final class AttachmentRetentionReportReservation
{
    /** @var resource|null */
    private mixed $lockHandle;

    /** @param array{dev: int, ino: int} $lockIdentity */
    private function __construct(
        private readonly string $output,
        private readonly string $lockPath,
        mixed $lockHandle,
        private readonly array $lockIdentity,
    ) {
        $this->lockHandle = $lockHandle;
    }

    public static function claim(string $output): self
    {
        $lockPath = $output.'.lock';
        $lockHandle = @fopen($lockPath, 'x+b');

        if (! is_resource($lockHandle)) {
            throw new RuntimeException('The report destination is already reserved by another measurement.');
        }

        $identity = fstat($lockHandle);

        if ($identity === false || ! isset($identity['dev'], $identity['ino'])) {
            fclose($lockHandle);
            @unlink($lockPath);

            throw new RuntimeException('Could not identify the report reservation.');
        }

        if (fwrite($lockHandle, ((string) getmypid()).PHP_EOL) === false || ! fflush($lockHandle)) {
            fclose($lockHandle);
            @unlink($lockPath);

            throw new RuntimeException('Could not persist the report reservation.');
        }

        return new self($output, $lockPath, $lockHandle, [
            'dev' => (int) $identity['dev'],
            'ino' => (int) $identity['ino'],
        ]);
    }

    public function publish(string $contents): void
    {
        if (! is_resource($this->lockHandle)) {
            throw new RuntimeException('The report destination is no longer reserved by this measurement.');
        }

        $temporary = $this->output.'.tmp.'.bin2hex(random_bytes(8));
        $temporaryHandle = @fopen($temporary, 'x+b');

        if (! is_resource($temporaryHandle)) {
            throw new RuntimeException('Could not create the temporary report.');
        }

        try {
            $remaining = $contents;

            while ($remaining !== '') {
                $written = fwrite($temporaryHandle, $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not write the temporary report.');
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($temporaryHandle)) {
                throw new RuntimeException('Could not flush the temporary report.');
            }

            if (function_exists('fsync') && ! fsync($temporaryHandle)) {
                throw new RuntimeException('Could not sync the temporary report.');
            }

            fclose($temporaryHandle);
            $temporaryHandle = null;

            if (! @link($temporary, $this->output)) {
                throw new RuntimeException('Refusing to replace a report created while the measurement was running.');
            }
        } finally {
            if (is_resource($temporaryHandle)) {
                fclose($temporaryHandle);
            }

            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function release(): void
    {
        if (! is_resource($this->lockHandle)) {
            return;
        }

        fclose($this->lockHandle);
        $this->lockHandle = null;
        $currentIdentity = @lstat($this->lockPath);

        if (
            is_array($currentIdentity)
            && (int) ($currentIdentity['dev'] ?? -1) === $this->lockIdentity['dev']
            && (int) ($currentIdentity['ino'] ?? -1) === $this->lockIdentity['ino']
        ) {
            @unlink($this->lockPath);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
