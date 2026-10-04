<?php

namespace App\Support\Attachments\Scanning;

use Throwable;

/**
 * Streams uploads to a trusted clamd socket using its NUL-framed INSTREAM
 * protocol. Raw clamd TCP sockets do not provide authentication or encryption.
 */
class ClamAvScanner implements AttachmentScanner
{
    private const MAX_RESPONSE_BYTES = 4096;

    public function __construct(
        private readonly string $socket,
        private readonly int $scanTimeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $chunkSize = 65536,
    ) {}

    public function scan(string $path): ScanResult
    {
        // Connection, streaming and verdict all share this monotonic budget.
        $deadline = $this->now() + max(1, $this->scanTimeoutSeconds);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ScanResult::unavailable('Could not open the file for scanning.');
        }

        $stream = null;

        try {
            $stream = $this->connect($deadline);

            if ($stream === null) {
                return ScanResult::unavailable(sprintf('Could not connect to clamd at %s.', $this->socket));
            }

            if (! $this->writeAll($stream, "zINSTREAM\0", $deadline)) {
                return $this->verdictAfterSendFailure($stream, $deadline);
            }

            while (! feof($handle)) {
                if ($this->now() >= $deadline) {
                    return ScanResult::unavailable('Timed out sending the file to clamd.');
                }

                $chunk = @fread($handle, $this->chunkSize);

                if ($chunk === false || ($chunk === '' && ! feof($handle))) {
                    return ScanResult::unavailable('Could not read the complete file for scanning.');
                }

                if ($chunk !== '' && ! $this->writeAll($stream, pack('N', strlen($chunk)).$chunk, $deadline)) {
                    return $this->verdictAfterSendFailure($stream, $deadline);
                }
            }

            // A clean verdict is valid only after the full stream terminator.
            if (! $this->writeAll($stream, pack('N', 0), $deadline)) {
                return $this->verdictAfterSendFailure($stream, $deadline);
            }

            $response = $this->readResponse($stream, $deadline);

            return $response === null
                ? ScanResult::unavailable('Timed out or received an incomplete or oversized clamd verdict.')
                : $this->interpret($response);
        } catch (Throwable $exception) {
            return ScanResult::unavailable($exception->getMessage());
        } finally {
            fclose($handle);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        $deadline = $this->now() + max(1, $this->connectTimeoutSeconds);
        $stream = null;

        try {
            $stream = $this->connect($deadline);

            if ($stream === null || ! $this->writeAll($stream, "zPING\0", $deadline)) {
                return false;
            }

            return $this->readResponse($stream, $deadline) === "PONG\0";
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Interpret exactly one complete NUL-framed INSTREAM verdict. */
    public function interpret(string $response): ScanResult
    {
        if (strlen($response) > self::MAX_RESPONSE_BYTES
            || ! str_ends_with($response, "\0")
            || substr_count($response, "\0") !== 1) {
            return ScanResult::unavailable('Invalid or incomplete verdict from clamd.');
        }

        if ($response === "stream: OK\0") {
            return ScanResult::clean();
        }

        // Signatures are bounded ASCII tokens; controls, whitespace, extra
        // verdicts and arbitrary text cannot become a clean or infected result.
        if (preg_match('~\Astream: ([A-Za-z0-9][A-Za-z0-9._:()+/@-]{0,254}) FOUND\x00\z~', $response, $matches) === 1) {
            return ScanResult::infected($matches[1]);
        }

        return ScanResult::unavailable('Invalid or unsupported verdict from clamd.');
    }

    /**
     * clamd can flag an infected stream and close before consuming every byte.
     * Preserve that complete infected verdict, but never accept an early clean
     * verdict after a failed or interrupted send.
     *
     * @param  resource  $stream
     */
    private function verdictAfterSendFailure($stream, float $deadline): ScanResult
    {
        $response = $this->readResponse($stream, $deadline, preserveInterruptedInfection: true);
        $result = $response === null ? null : $this->interpret($response);

        return $result?->isInfected()
            ? $result
            : ScanResult::unavailable('Timed out or interrupted sending the complete file to clamd.');
    }

    /**
     * A non-session command closes its connection after replying. Require that
     * EOF and one complete frame, so a partial reply, stalled connection or
     * later contradictory record cannot silently become a clean result.
     *
     * @param  resource  $stream
     */
    private function readResponse($stream, float $deadline, bool $preserveInterruptedInfection = false): ?string
    {
        $response = '';

        while (! feof($stream)) {
            $read = [$stream];
            $write = [];

            if (! $this->waitForSocket($read, $write, $deadline)) {
                return null;
            }

            // Read one byte beyond the cap to detect overflow without allowing
            // an unbounded daemon response to allocate unbounded memory.
            $buffer = @fread($stream, min(4096, self::MAX_RESPONSE_BYTES + 1 - strlen($response)));

            if ($buffer === false) {
                // Linux can reset a peer that closes with unread upload bytes.
                // Keep a complete infected frame already received after an
                // interrupted send; a reset never makes a clean frame valid.
                if ($preserveInterruptedInfection && feof($stream) && $this->interpret($response)->isInfected()) {
                    return $response;
                }

                return null;
            }

            if ($buffer === '' && ! feof($stream)) {
                return null;
            }

            $response .= $buffer;

            if (strlen($response) > self::MAX_RESPONSE_BYTES) {
                return null;
            }
        }

        return $response !== '' && str_ends_with($response, "\0") ? $response : null;
    }

    /** @param array<resource> $read @param array<resource> $write */
    private function waitForSocket(array &$read, array &$write, float $deadline): bool
    {
        $remaining = $deadline - $this->now();

        if ($remaining <= 0) {
            return false;
        }

        $microseconds = max(1, (int) ceil($remaining * 1_000_000));

        $except = [];

        return @stream_select($read, $write, $except, intdiv($microseconds, 1_000_000), $microseconds % 1_000_000) > 0;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes, float $deadline): bool
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            // Notice early replies even when the remaining upload would fit in
            // the local send buffer. Sending must finish before accepting OK.
            $read = [$stream];
            $write = [$stream];

            if (! $this->waitForSocket($read, $write, $deadline) || $read !== []) {
                return false;
            }

            $written = @fwrite($stream, substr($bytes, $offset));

            if ($written === false) {
                return false;
            }

            if ($written === 0) {
                continue;
            }

            $offset += $written;
        }

        return true;
    }

    /** @return resource|null */
    private function connect(float $deadline)
    {
        $remaining = $deadline - $this->now();

        if ($remaining <= 0) {
            return null;
        }

        $stream = @stream_socket_client(
            $this->socket,
            $errno,
            $errstr,
            min(max(1, $this->connectTimeoutSeconds), $remaining),
        );

        if ($stream === false) {
            return null;
        }

        // Blocking writes can ignore stream_set_timeout on some platforms.
        // Nonblocking I/O plus select bounds both backpressure and silent peers.
        if ($this->now() >= $deadline || ! stream_set_blocking($stream, false)) {
            fclose($stream);

            return null;
        }

        return $stream;
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
