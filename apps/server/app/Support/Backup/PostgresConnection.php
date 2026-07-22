<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Shared Postgres plumbing for backup (pg_dump) and restore (psql). One place
 * resolves the app's own connection config, builds the host/port/user/db
 * arguments both tools take, and maps the app's SSL policy into libpq's PGSSL*
 * environment — so a dump or restore to a remote Postgres honors the same TLS
 * requirement the app enforces rather than silently downgrading (ADR 0009).
 */
class PostgresConnection
{
    /**
     * The app connection's own config — not the raw config array, so it
     * reflects DB_URL parsing and targets the exact database Laravel uses.
     * Fails loud on a non-Postgres connection: backup and restore are
     * pg_dump/psql shell-outs and support Postgres only.
     *
     * @return array<string, mixed>
     */
    public static function resolve(?string $connection = null): array
    {
        $connection = $connection ?: (string) config('database.default');

        $config = DB::connection($connection)->getConfig();

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(
                "Wayfindr backup and restore require the pgsql driver; the '{$connection}' connection is '".($config['driver'] ?? 'unknown')."'."
            );
        }

        return $config;
    }

    /**
     * The connection arguments common to pg_dump and psql.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function baseArguments(array $config): array
    {
        return [
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
        ];
    }

    /**
     * libpq's environment: the password, plus the app connection's SSL policy
     * mapped to PGSSL* — otherwise pg_dump/psql fall back to libpq defaults and
     * would ignore a verify-ca/verify-full requirement the app enforces.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function libpqEnvironment(array $config): array
    {
        $env = ['PGPASSWORD' => (string) ($config['password'] ?? '')];

        $sslKeys = [
            'sslmode' => 'PGSSLMODE',
            'sslrootcert' => 'PGSSLROOTCERT',
            'sslcert' => 'PGSSLCERT',
            'sslkey' => 'PGSSLKEY',
        ];

        foreach ($sslKeys as $configKey => $envKey) {
            $value = $config[$configKey] ?? null;

            if (is_string($value) && $value !== '') {
                $env[$envKey] = $value;
            }
        }

        return $env;
    }
}
