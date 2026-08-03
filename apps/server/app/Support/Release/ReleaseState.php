<?php

declare(strict_types=1);

namespace App\Support\Release;

use Throwable;

/**
 * Which release this install was last running (ADR 0013).
 *
 * The guard needs the upgrade's starting point to know which declarations it
 * traverses, and nothing recorded it before this. It lives on the persistent
 * volume rather than in the database because the guard runs before migrations,
 * and rather than beside the baked manifest because /etc/wayfindr is root-owned
 * while the application runs as an unprivileged user.
 */
final class ReleaseState
{
    public function path(): string
    {
        return (string) config('wayfindr.release.state_path', storage_path('app/release-state.json'));
    }

    /**
     * The release recorded by the last successful run, or null when there is no
     * record. Null is NOT "fresh install" on its own — see UpgradeGuard, which
     * disambiguates it against the existing schema.
     */
    public function recordedVersion(): ?string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $version = is_array($decoded) ? ($decoded['version'] ?? null) : null;

        return is_string($version) && trim($version) !== '' ? $version : null;
    }

    /**
     * Record that this release got far enough to be considered installed.
     *
     * Written only after migrations complete successfully. A release whose
     * migrations failed has not arrived, and recording it would let the next
     * upgrade compute its span from a release that never took effect.
     */
    public function record(string $version, ?string $commit): bool
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        $written = @file_put_contents($path, json_encode([
            'version' => $version,
            'commit' => $commit,
            'recorded_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $written !== false;
    }
}
