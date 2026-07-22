<?php

namespace App\Support\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Restores a plain-SQL pg_dump with psql, using the app's own connection
 * credentials. The whole restore runs as ONE transaction (--single-transaction
 * + ON_ERROR_STOP): the schema is dropped and recreated and the dump applied
 * atomically, so a failure part-way rolls back and leaves the database
 * untouched rather than half-restored. Dropping the schema first means the
 * dump's CREATE statements land in a clean namespace — the command guards
 * against running on a populated database without --force (RestoreService),
 * and the --no-owner/--no-privileges dump restores under the connecting role
 * (ADR 0009).
 */
class PostgresDatabaseRestorer implements DatabaseRestorer
{
    public function restore(string $sqlFile): void
    {
        if (! is_file($sqlFile)) {
            throw new RuntimeException("Database dump not found in archive: {$sqlFile}");
        }

        $config = PostgresConnection::resolve();

        $process = new Process(
            command: $this->restoreCommand($config, $sqlFile),
            env: PostgresConnection::libpqEnvironment($config),
            timeout: null,
        );

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Database restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * psql executes -c/--command and -f/--file in the order given; with
     * --single-transaction all of them are wrapped in one BEGIN/COMMIT. So the
     * schema is dropped, recreated, and the dump applied as a single atomic
     * unit.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function restoreCommand(array $config, string $sqlFile): array
    {
        return array_merge(
            ['psql'],
            PostgresConnection::baseArguments($config),
            [
                // Roll the whole restore back on the first error instead of
                // ploughing on and leaving a partially-applied database.
                '--set=ON_ERROR_STOP=on',
                '--single-transaction',
                // Replace the schema wholesale: the dump recreates every object
                // in the now-empty public schema. Guarded against a populated
                // database without --force in RestoreService.
                '--command=DROP SCHEMA IF EXISTS public CASCADE',
                '--command=CREATE SCHEMA public',
                '--file='.$sqlFile,
            ],
        );
    }
}
