<?php

namespace App\Support;

/**
 * Where the running release identity comes from.
 *
 * The official image bakes its version and commit into BOTH an env var (for
 * `printenv` visibility) and a file (`/etc/wayfindr/*`). Config reads through
 * here so an empty or unset env var falls back to the baked file: a Compose
 * `env_file` line like `WAYFINDR_VERSION=` cannot shadow the image identity
 * to blank, which is exactly what a pre-identity install's `.env` carried. A
 * non-empty env var still wins, so operators of custom builds can override.
 */
class ReleaseIdentity
{
    public const VERSION_FILE = '/etc/wayfindr/version';

    public const COMMIT_FILE = '/etc/wayfindr/commit';

    public static function version(): ?string
    {
        return self::resolve(
            env('WAYFINDR_VERSION'),
            self::readFile(self::VERSION_FILE),
            // Last resort for a deploy that builds from source ON THE HOST
            // (Forge, ADR 0003): nothing bakes /etc/wayfindr, so without this it
            // would report no identity at all. The repo's VERSION file names the
            // version under development, so `<VERSION>-dev` is the honest answer
            // — it identifies the lineage without claiming to be that release.
            // Operators who want an exact identity set WAYFINDR_VERSION, which
            // still wins (ADR 0012).
            self::developmentVersion(),
        );
    }

    public static function commit(): ?string
    {
        return self::resolve(env('WAYFINDR_COMMIT'), self::readFile(self::COMMIT_FILE));
    }

    /**
     * First non-blank candidate, in precedence order; null when none carries
     * anything.
     */
    public static function resolve(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * `<VERSION>-dev` from the repository's VERSION file (two levels above the
     * Laravel app root in this monorepo), or null when it is not readable — the
     * published image, where /etc/wayfindr already answered.
     */
    private static function developmentVersion(): ?string
    {
        $version = self::readFile(dirname(base_path(), 2).'/VERSION');

        if ($version === null || trim($version) === '') {
            return null;
        }

        return trim($version).'-dev';
    }

    private static function readFile(string $path): ?string
    {
        return is_file($path) ? (string) @file_get_contents($path) : null;
    }
}
