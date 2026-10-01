<?php

declare(strict_types=1);

namespace App\Support\Zip;

use InvalidArgumentException;
use RuntimeException;

/**
 * A ZIP archive written to a local file, entry by entry, without holding any
 * entry in memory and without the zip extension, which the runtime does not
 * require.
 *
 * Entries are stored, not compressed, and each one's checksum and size are
 * written back into its header once its data is in: the file is seekable, so
 * no data descriptor is needed, and every unzip tool reads the result. There
 * is no ZIP64, so an archive is refused past 65,535 entries or 4 GiB, or the
 * smaller size its caller allows, with ArchiveTooLarge: callers check what
 * they can count first, and the writer holds the rest to it as it goes.
 */
final class StoredZipWriter
{
    public const MAX_ENTRIES = 0xFFFF;

    public const MAX_BYTES = 0xFFFFFFFF;

    /** UTF-8 names. */
    private const FLAGS = 0x0800;

    /** @var resource */
    private $handle;

    /** @var list<array{name: string, crc: int, size: int, offset: int}> */
    private array $entries = [];

    /** @var array{name: string, offset: int, hash: \HashContext, size: int}|null */
    private ?array $open = null;

    private bool $closed = false;

    private readonly int $dosTime;

    private readonly int $dosDate;

    public function __construct(string $path, private readonly int $maxBytes = self::MAX_BYTES)
    {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('An archive without ZIP64 holds between 1 byte and 4 GiB.');
        }

        $handle = @fopen($path, 'w+b');

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not open {$path} to write an archive.");
        }

        $this->handle = $handle;
        $now = getdate();
        $this->dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $this->dosDate = ((max(1980, $now['year']) - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
    }

    public function addFromString(string $name, string $data): void
    {
        $this->begin($name);
        $this->write($data);
        $this->end();
    }

    /**
     * @param  resource  $stream
     */
    public function addFromStream(string $name, $stream): void
    {
        $this->begin($name);

        while (! feof($stream)) {
            $chunk = fread($stream, 1 << 20);

            if ($chunk === false) {
                throw new RuntimeException("Could not read the data for {$name}.");
            }

            $this->write($chunk);
        }

        $this->end();
    }

    /** Start an entry whose data follows through write(). */
    public function begin(string $name): void
    {
        if ($this->closed || $this->open !== null) {
            throw new RuntimeException('An archive entry is already open, or the archive is closed.');
        }

        self::assertName($name);

        if (count($this->entries) >= self::MAX_ENTRIES) {
            throw new ArchiveTooLarge('The archive would hold more entries than a ZIP file without ZIP64 can.');
        }

        $offset = $this->position();
        $this->put(pack('VvvvvvVVVvv', 0x04034B50, 20, self::FLAGS, 0, $this->dosTime, $this->dosDate, 0, 0, 0, strlen($name), 0).$name);
        $this->open = ['name' => $name, 'offset' => $offset, 'hash' => hash_init('crc32b'), 'size' => 0];
    }

    public function write(string $data): void
    {
        if ($this->open === null) {
            throw new RuntimeException('No archive entry is open.');
        }

        if ($data === '') {
            return;
        }

        hash_update($this->open['hash'], $data);
        $this->open['size'] += strlen($data);
        $this->put($data);
    }

    /** Finish the open entry, writing its checksum and size into its header. */
    public function end(): void
    {
        if ($this->open === null) {
            throw new RuntimeException('No archive entry is open.');
        }

        $entry = $this->open;
        $this->open = null;
        $crc = (int) hexdec(hash_final($entry['hash']));

        if ($entry['size'] > self::MAX_BYTES) {
            throw new ArchiveTooLarge("{$entry['name']} is larger than a ZIP file without ZIP64 can hold.");
        }

        $end = $this->position();

        // The CRC and both sizes sit 14 bytes into the local header.
        if (fseek($this->handle, $entry['offset'] + 14) !== 0) {
            throw new RuntimeException('Could not finish an archive entry.');
        }

        $this->put(pack('VVV', $crc, $entry['size'], $entry['size']));

        if (fseek($this->handle, $end) !== 0) {
            throw new RuntimeException('Could not finish an archive entry.');
        }

        $this->entries[] = ['name' => $entry['name'], 'crc' => $crc, 'size' => $entry['size'], 'offset' => $entry['offset']];
    }

    /** Write the central directory and close the file. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->open !== null) {
            throw new RuntimeException('An archive entry is still open.');
        }

        $start = $this->position();

        foreach ($this->entries as $entry) {
            $this->put(pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                0x0314,
                20,
                self::FLAGS,
                0,
                $this->dosTime,
                $this->dosDate,
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0o100644 << 16,
                $entry['offset'],
            ).$entry['name']);
        }

        $size = $this->position() - $start;
        $this->put(pack('VvvvvVVv', 0x06054B50, 0, 0, count($this->entries), count($this->entries), $size, $start, 0));

        if (! fflush($this->handle) || ! fclose($this->handle)) {
            throw new RuntimeException('Could not finish writing the archive.');
        }

        $this->closed = true;
    }

    /** Close the file without finishing the archive, after a failure. */
    public function abandon(): void
    {
        if (! $this->closed) {
            @fclose($this->handle);
            $this->closed = true;
        }
    }

    public function count(): int
    {
        return count($this->entries);
    }

    private function put(string $bytes): void
    {
        $remaining = $bytes;

        while ($remaining !== '') {
            $written = fwrite($this->handle, $remaining);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the archive.');
            }

            $remaining = substr($remaining, $written);
        }

        if ($this->position() > $this->maxBytes) {
            throw new ArchiveTooLarge('The archive would be larger than its writer allows.');
        }
    }

    private function position(): int
    {
        $position = ftell($this->handle);

        if ($position === false) {
            throw new RuntimeException('Could not tell where the archive ends.');
        }

        return $position;
    }

    /** Relative, forward-slashed and inside the archive. */
    private static function assertName(string $name): void
    {
        if ($name === ''
            || strlen($name) > 0xFFFF
            || ! mb_check_encoding($name, 'UTF-8')
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || in_array('..', explode('/', $name), true)) {
            throw new InvalidArgumentException("{$name} is not a safe name for an archive entry.");
        }
    }
}
