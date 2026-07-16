<?php

namespace App\Support\Attachments\Scanning;

use Throwable;

/**
 * Streams an upload's bytes to a ClamAV daemon (clamd) over its INSTREAM
 * protocol and interprets the verdict. clamd runs locally (TCP or unix socket),
 * so files never leave the trust boundary — no third-party service sees them.
 *
 * INSTREAM: send "zINSTREAM\0", then a sequence of chunks each prefixed with a
 * 4-byte network-order length, terminated by a zero-length chunk; clamd replies
 * "stream: OK" (clean), "stream: <signature> FOUND" (infected), or an error.
 */
class ClamAvScanner implements AttachmentScanner
{
    public function __construct(
        private readonly string $socket,
        private readonly int $scanTimeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $chunkSize = 65536,
    ) {}

    public function scan(string $path): ScanResult
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ScanResult::unavailable('Could not open the file for scanning.');
        }

        $stream = $this->connect($this->scanTimeoutSeconds);

        if ($stream === null) {
            fclose($handle);

            return ScanResult::unavailable(sprintf('Could not connect to clamd at %s.', $this->socket));
        }

        // A hard wall-clock deadline for the whole scan. Socket-activated clamd
        // can accept a connection while the daemon behind it is dead (systemd
        // accepts, the service never answers); without a deadline the request
        // would hang instead of failing closed.
        $deadline = microtime(true) + max(1, $this->scanTimeoutSeconds);

        try {
            if (! $this->writeAll($stream, "zINSTREAM\0", $deadline)) {
                return $this->verdictAfterSendFailure($stream, $deadline);
            }

            while (! feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                if (! $this->writeAll($stream, pack('N', strlen($chunk)).$chunk, $deadline)) {
                    return $this->verdictAfterSendFailure($stream, $deadline);
                }
            }

            // Zero-length chunk signals end of stream.
            if (! $this->writeAll($stream, pack('N', 0), $deadline)) {
                return $this->verdictAfterSendFailure($stream, $deadline);
            }

            $response = $this->readResponse($stream, $deadline);

            return $response === ''
                ? ScanResult::unavailable('Timed out waiting for a clamd verdict.')
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
        $stream = $this->connect($this->connectTimeoutSeconds);

        if ($stream === null) {
            return false;
        }

        try {
            fwrite($stream, "zPING\0");
            $response = trim(str_replace("\0", '', (string) fread($stream, 64)));

            return $response === 'PONG';
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Interpret a raw clamd response into a ScanResult. Kept separate from the
     * socket I/O so the verdict parsing is unit-testable.
     */
    public function interpret(string $response): ScanResult
    {
        $response = trim(str_replace("\0", '', $response));

        if ($response === '') {
            return ScanResult::unavailable('Empty response from clamd.');
        }

        if (str_ends_with($response, 'OK')) {
            return ScanResult::clean();
        }

        if (str_contains($response, 'FOUND')) {
            // "stream: Win.Test.EICAR_HDB-1 FOUND" -> "Win.Test.EICAR_HDB-1"
            $threat = trim(preg_replace('/^stream:\s*/', '', str_replace('FOUND', '', $response)) ?? '');

            return ScanResult::infected($threat !== '' ? $threat : 'unknown');
        }

        return ScanResult::unavailable('clamd error: '.$response);
    }

    /**
     * A send failure does not always mean clamd is gone: it writes its verdict
     * EARLY and closes the connection when it can already decide (an infected
     * stream, or a size-limit error) — which surfaces on our side as a failed
     * write. Drain whatever verdict is already in the receive buffer and honor
     * it; only report unavailable when there is genuinely no answer. Without
     * this, a fail-open install could accept a file clamd had already flagged.
     *
     * @param  resource  $stream
     */
    private function verdictAfterSendFailure($stream, float $deadline): ScanResult
    {
        $response = $this->readResponse($stream, $deadline);

        return $response === ''
            ? ScanResult::unavailable('Timed out sending the file to clamd.')
            : $this->interpret($response);
    }

    /**
     * Read clamd's response until EOF, a timeout, or the deadline — returning
     * whatever arrived ('' if nothing). fread returns '' (not false) on a
     * socket timeout with feof still false, so both are treated as "clamd is
     * not answering" rather than spinning forever.
     *
     * @param  resource  $stream
     */
    private function readResponse($stream, float $deadline): string
    {
        $response = '';

        while (! feof($stream)) {
            // Check the budget BEFORE blocking, and cap each read by what
            // remains — otherwise a scan whose writes consumed the budget would
            // still block a full socket timeout here, overshooting the deadline
            // to ~2x the configured limit.
            if (! $this->applyRemainingTimeout($stream, $deadline)) {
                return $response;
            }

            $buffer = fread($stream, 4096);

            if ($buffer === false || $buffer === '') {
                if ($buffer === false || stream_get_meta_data($stream)['timed_out']) {
                    return $response;
                }

                continue;
            }

            $response .= $buffer;
        }

        return $response;
    }

    /**
     * Bound the next blocking socket operation by the time left before the
     * deadline. Returns false when the budget is already spent.
     *
     * @param  resource  $stream
     */
    private function applyRemainingTimeout($stream, float $deadline): bool
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            return false;
        }

        stream_set_timeout($stream, (int) $remaining, (int) (fmod($remaining, 1.0) * 1_000_000));

        return true;
    }

    /**
     * Write the full payload, honoring socket timeouts and the wall-clock
     * deadline. fwrite can time out (false), stall (0 bytes), or write
     * partially; anything that stops making progress before the deadline is a
     * failure, never a hang.
     *
     * @param  resource  $stream
     */
    private function writeAll($stream, string $bytes, float $deadline): bool
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            // Cap each blocking write by the remaining budget, mirroring the
            // read side, so no single fwrite can overshoot the deadline.
            if (! $this->applyRemainingTimeout($stream, $deadline)) {
                return false;
            }

            $written = @fwrite($stream, substr($bytes, $offset));

            if ($written === false || stream_get_meta_data($stream)['timed_out']) {
                return false;
            }

            if ($written === 0) {
                // No progress this pass; back off briefly instead of hot-spinning
                // until the deadline decides.
                usleep(50_000);

                continue;
            }

            $offset += $written;
        }

        return true;
    }

    /**
     * @return resource|null
     */
    private function connect(int $timeoutSeconds)
    {
        $stream = @stream_socket_client(
            $this->socket,
            $errno,
            $errstr,
            max(1, $timeoutSeconds),
        );

        if ($stream === false) {
            return null;
        }

        stream_set_timeout($stream, max(1, $timeoutSeconds));

        return $stream;
    }
}
