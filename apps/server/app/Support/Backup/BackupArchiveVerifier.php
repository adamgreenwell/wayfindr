<?php

declare(strict_types=1);

namespace App\Support\Backup;

use App\Support\Release\UpgradeGuard;
use RuntimeException;

/** Byte and coverage verification only; this never loads a database or extracts files. */
final class BackupArchiveVerifier
{
    public const MAX_MEMBERS = 32768;

    private const MAX_MANIFEST_BYTES = 8388608;

    public function verify(string $archive, array $expected, string $operationId): array
    {
        if (is_link($archive) || ! is_file($archive) || ! is_readable($archive)) {
            $this->fail();
        }

        $version = config('wayfindr.release.version');
        $commit = config('wayfindr.release.commit');
        $profile = app(UpgradeGuard::class)->installationProfile();

        if (! is_string($version) || preg_match('/^v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1
            || ! is_string($commit) || preg_match('/^[0-9a-f]{40}(?:[0-9a-f]{24})?$/D', $commit) !== 1
            || ($expected['wayfindr_version'] ?? null) !== $version
            || ($expected['wayfindr_commit'] ?? null) !== $commit
            || ($expected['installation_profile'] ?? null) !== $profile
            || ($expected['protective_operation'] ?? null) !== $operationId
            || ($expected['app_key_fingerprints'] ?? null) !== BackupService::appKeyFingerprints()
            || BackupService::appKeyFingerprints() === []) {
            $this->fail();
        }

        $integrity = $expected['archive_integrity'] ?? null;
        $members = is_array($integrity) ? ($integrity['members'] ?? null) : null;

        if (($integrity['schema'] ?? null) !== 1 || ($integrity['algorithm'] ?? null) !== 'sha256'
            || ! is_array($members) || count($members) > self::MAX_MEMBERS || ! isset($members['database.sql'])) {
            $this->fail();
        }

        $allowedDirs = ['' => true];
        $attachmentDisks = [];

        foreach ($members as $name => $facts) {
            if (! is_string($name) || ! $this->safeName($name)
                || ($name !== 'database.sql' && preg_match('#^attachments/(attachments[^/]*)/.+#D', $name, $match) !== 1)
                || ! is_array($facts) || array_keys($facts) !== ['bytes', 'sha256']
                || ! is_int($facts['bytes']) || $facts['bytes'] < 0
                || ! is_string($facts['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $facts['sha256']) !== 1) {
                $this->fail();
            }

            if ($name !== 'database.sql') {
                $attachmentDisks[$match[1]] = true;
            }

            $dir = dirname($name);

            while ($dir !== '.') {
                $allowedDirs[$dir] = true;
                $dir = dirname($dir);
            }
        }

        if ($members['database.sql']['bytes'] === 0) {
            $this->fail();
        }

        $locals = $expected['local_attachment_disks'] ?? null;
        $external = $expected['external_attachment_disks'] ?? null;

        if (! is_array($locals) || ! array_is_list($locals) || ! is_array($external) || ! array_is_list($external)
            || array_filter([...$locals, ...$external], static fn ($disk): bool => ! is_string($disk) || $disk === '') !== []) {
            $this->fail();
        }

        $captured = array_keys($attachmentDisks);
        sort($captured);
        sort($locals);

        if ($locals !== $captured || ($expected['includes_local_attachment_binaries'] ?? null) !== ($captured !== [])) {
            $this->fail();
        }

        $stream = @gzopen($archive, 'rb');

        if ($stream === false) {
            $this->fail();
        }

        $actual = [];
        $seen = [];
        $manifestBytes = null;

        try {
            while (true) {
                $header = $this->read($stream, 512);

                if ($header === str_repeat("\0", 512)) {
                    if ($this->read($stream, 512) !== str_repeat("\0", 512)) {
                        $this->fail();
                    }

                    while (! gzeof($stream)) {
                        $tail = gzread($stream, 65536);

                        if ($tail === false || trim($tail, "\0") !== '') {
                            $this->fail();
                        }
                    }

                    break;
                }

                $this->header($header);
                $name = $this->field($header, 0, 100);
                $prefix = $this->field($header, 345, 155);
                $name = ($prefix === '' ? '' : $prefix.'/').$name;
                $name = str_starts_with($name, './') ? substr($name, 2) : $name;
                $type = $header[156];
                $size = $this->octal(substr($header, 124, 12));

                if ($type === '5') {
                    $name = rtrim($name, '/');

                    if ($size !== 0 || ! isset($allowedDirs[$name])) {
                        $this->fail();
                    }
                } elseif ($type !== '0' && $type !== "\0") {
                    // Hardlinks, symlinks, devices, extended headers and sparse
                    // formats are deliberately outside this generated format.
                    $this->fail();
                } elseif (! $this->safeName($name) || ($name !== 'manifest.json' && ! isset($members[$name]))) {
                    $this->fail();
                }

                $seenKey = ($type === '5' ? 'directory:' : 'file:').$name;

                if (isset($seen[$seenKey]) || count($seen) > self::MAX_MEMBERS * 4) {
                    $this->fail();
                }

                $seen[$seenKey] = true;
                $hash = hash_init('sha256');
                $remaining = $size;
                $content = '';

                if ($name === 'manifest.json' && $size > self::MAX_MANIFEST_BYTES) {
                    $this->fail();
                }

                while ($remaining > 0) {
                    $bytes = $this->read($stream, min($remaining, 65536));
                    hash_update($hash, $bytes);

                    if ($name === 'manifest.json') {
                        $content .= $bytes;
                    }

                    $remaining -= strlen($bytes);
                }

                if ($size % 512 !== 0) {
                    $this->read($stream, 512 - $size % 512);
                }

                if ($type !== '5') {
                    if ($name === 'manifest.json') {
                        $manifestBytes = $content;
                    } else {
                        $actual[$name] = ['bytes' => $size, 'sha256' => hash_final($hash)];
                    }
                }
            }
        } finally {
            gzclose($stream);
        }

        ksort($actual);
        ksort($members);
        $expectedBytes = json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if ($actual !== $members || $manifestBytes !== $expectedBytes) {
            $this->fail();
        }

        $sha = hash_file('sha256', $archive);
        $size = filesize($archive);

        if ($sha === false || ! is_int($size) || $size <= 0) {
            $this->fail();
        }

        return [
            'archive_sha256' => $sha,
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'archive_bytes' => $size,
            'source' => ['version' => $version, 'commit' => $commit, 'profile' => $profile],
            'external_attachment_disks' => count(array_unique($external)),
        ];
    }

    private function header(string $header): void
    {
        if (substr($header, 257, 6) !== "ustar\0" || substr($header, 157, 100) !== str_repeat("\0", 100)) {
            $this->fail();
        }

        $sum = array_sum(array_map(ord(...), str_split(substr_replace($header, str_repeat(' ', 8), 148, 8))));

        if ($sum !== $this->octal(substr($header, 148, 8))) {
            $this->fail();
        }
    }

    private function octal(string $bytes): int
    {
        $text = trim($bytes, "\0 ");

        if ($text === '' || preg_match('/^[0-7]{1,12}$/D', $text) !== 1) {
            $this->fail();
        }

        return intval($text, 8);
    }

    private function field(string $header, int $offset, int $length): string
    {
        $field = substr($header, $offset, $length);
        $name = rtrim($field, "\0");

        if (str_contains($name, "\0")) {
            $this->fail();
        }

        return $name;
    }

    private function safeName(string $name): bool
    {
        return $name !== '' && ! str_starts_with($name, '/') && ! str_contains($name, '\\') && ! str_contains($name, '//')
            && preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)|[\x00-\x1f\x7f]#', $name) !== 1;
    }

    private function read($stream, int $bytes): string
    {
        $value = '';

        while (strlen($value) < $bytes) {
            $chunk = gzread($stream, $bytes - strlen($value));

            if ($chunk === false || $chunk === '') {
                $this->fail();
            }

            $value .= $chunk;
        }

        return $value;
    }

    private function fail(): never
    {
        throw new RuntimeException('protective_backup_verification_failed');
    }
}
