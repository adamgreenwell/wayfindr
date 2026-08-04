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
        $value = $this->read()['version'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) @file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The newest release whose requirements were ALL met, which is not the same
     * as the newest release that migrated.
     *
     * A v1 -> v3 upgrade passes through v2, and an after-start action of v2's
     * cannot block the migration — it needs the migrated schema to be performed
     * at all. So migration completes, v3 is recorded, and if the span were
     * computed from what is RUNNING it would collapse to (v3, v3] and v2's
     * requirement would vanish: outstanding one moment, served the next, with
     * nothing done about it.
     *
     * This marker only advances when nothing is outstanding, so the span keeps
     * reaching back to the last release that was genuinely clean.
     *
     * Null means it has never advanced — read by the guard as "fall back to the
     * recorded version", which is the right answer for an install that has only
     * ever been clean.
     */
    public function satisfiedThrough(): ?string
    {
        $value = $this->read()['satisfied_through'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Record that this release got far enough to be considered installed.
     *
     * Written only after migrations complete successfully. A release whose
     * migrations failed has not arrived, and recording it would let the next
     * upgrade compute its span from a release that never took effect.
     *
     * `$satisfiedThrough` is deliberately a separate argument rather than
     * defaulting to `$version`: advancing it is a claim that nothing is
     * outstanding, and only the caller that just evaluated the requirements is
     * in a position to make it.
     */
    public function record(string $version, ?string $commit, ?string $satisfiedThrough = null): bool
    {
        $path = $this->path();
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        // Preserved when the caller does not advance it. Losing it would silently
        // widen the span back to the recorded version - which is the very
        // collapse this field exists to prevent.
        $satisfiedThrough ??= $this->satisfiedThrough();

        $written = @file_put_contents($path, json_encode([
            'version' => $version,
            'commit' => $commit,
            'satisfied_through' => $satisfiedThrough,
            'recorded_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        return $written !== false;
    }
}
