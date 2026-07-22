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
        return self::resolve(env('WAYFINDR_VERSION'), self::readFile(self::VERSION_FILE));
    }

    public static function commit(): ?string
    {
        return self::resolve(env('WAYFINDR_COMMIT'), self::readFile(self::COMMIT_FILE));
    }

    /**
     * First non-blank of the env override then the baked value; null when
     * neither carries anything (a source build with no identity set).
     */
    public static function resolve(?string $envValue, ?string $bakedValue): ?string
    {
        foreach ([$envValue, $bakedValue] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private static function readFile(string $path): ?string
    {
        return is_file($path) ? (string) @file_get_contents($path) : null;
    }
}
