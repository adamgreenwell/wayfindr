<?php

namespace App\Support\Backup;

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
    public function dump(string $destination): string
    {
        $connection = (string) config('database.default');
        $config = config("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException(
                "Wayfindr backups require the pgsql driver; the '{$connection}' connection is '".($config['driver'] ?? 'unknown')."'."
            );
        }

        $process = new Process(
            command: [
                'pg_dump',
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.(string) ($config['port'] ?? 5432),
                '--username='.($config['username'] ?? ''),
                '--dbname='.($config['database'] ?? ''),
                '--no-owner',
                '--no-privileges',
                '--file='.$destination,
            ],
            env: ['PGPASSWORD' => (string) ($config['password'] ?? '')],
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
}
