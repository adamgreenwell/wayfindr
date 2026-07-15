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

        try {
            fwrite($stream, "zINSTREAM\0");

            while (! feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($stream, pack('N', strlen($chunk)).$chunk);
            }

            // Zero-length chunk signals end of stream.
            fwrite($stream, pack('N', 0));

            $response = '';

            while (! feof($stream)) {
                $buffer = fread($stream, 4096);

                if ($buffer === false) {
                    break;
                }

                $response .= $buffer;
            }

            return $this->interpret($response);
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
