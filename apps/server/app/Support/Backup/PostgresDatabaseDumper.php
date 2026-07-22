<?php

namespace App\Support\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dumps the Postgres database with pg_dump, using the app's own connection
 * credentials. Plain SQL (--no-owner --no-privileges) so a restore is portable
 * across role names and inspectable, and restores cleanly into a fresh
 * database via psql (ADR 0009).
 */
class PostgresDatabaseDumper implements DatabaseDumper
{
    /**
     * Tables whose DATA is ephemeral, operator-owned, or credential-bearing
     * (ADR 0009): the schema is kept (the table exists after restore) but the
     * rows are not dumped. Sessions and password-reset tokens especially must
     * not ride into a restore — reviving them is a security hole — and
     * failed-job payloads can carry serialized secrets. Patterns that match no
     * table are silently ignored, so this is safe on installs using redis for
     * cache/queue.
     */
    private const EXCLUDED_TABLE_DATA = [
        'public.sessions',
        'public.password_reset_tokens',
        'public.cache',
        'public.cache_locks',
        'public.jobs',
        'public.job_batches',
        'public.failed_jobs',
    ];

    public function dump(string $destination): string
    {
        $connection = (string) config('database.default');

        // The connection's own config, not the raw config array: this reflects
        // DB_URL parsing, so pg_dump targets the same database Laravel uses
        // rather than the config-file defaults.
        $config = DB::connection($connection)->getConfig();

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(
                "Wayfindr backups require the pgsql driver; the '{$connection}' connection is '".($config['driver'] ?? 'unknown')."'."
            );
        }

        $process = new Process(
            command: $this->dumpCommand($config, $destination),
            env: $this->environmentFor($config),
            timeout: null,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $version = new Process(['pg_dump', '--version']);
        $version->run();

        return trim($version->getOutput()) ?: 'pg_dump';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function dumpCommand(array $config, string $destination): array
    {
        $command = [
            'pg_dump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
            '--no-owner',
            '--no-privileges',
        ];

        foreach (self::EXCLUDED_TABLE_DATA as $table) {
            $command[] = '--exclude-table-data='.$table;
        }

        $command[] = '--file='.$destination;

        return $command;
    }

    /**
     * pg_dump's libpq environment. The password, plus the app connection's SSL
     * policy mapped to PGSSL* — otherwise pg_dump falls back to libpq defaults
     * and would ignore a verify-ca/verify-full requirement the app enforces,
     * silently downgrading TLS to a remote Postgres.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public function environmentFor(array $config): array
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
