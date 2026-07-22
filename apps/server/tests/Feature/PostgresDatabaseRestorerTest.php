<?php

// The psql restore command (ADR 0009, slice 2): the whole restore runs as one
// transaction that drops and recreates the schema before applying the dump, so
// a failure rolls back rather than leaving a half-restored database. Shared
// connection/SSL plumbing lives in PostgresConnection.

use App\Support\Backup\PostgresConnection;
use App\Support\Backup\PostgresDatabaseRestorer;

test('the restore command drops-and-recreates the schema, then applies the dump in one transaction', function (): void {
    $command = (new PostgresDatabaseRestorer)->restoreCommand(
        ['host' => 'db', 'port' => 5432, 'username' => 'u', 'database' => 'wayfindr'],
        '/work/database.sql',
    );

    expect($command[0])->toBe('psql')
        ->and($command)->toContain('--host=db')
        ->and($command)->toContain('--port=5432')
        ->and($command)->toContain('--username=u')
        ->and($command)->toContain('--dbname=wayfindr')
        ->and($command)->toContain('--single-transaction')
        ->and($command)->toContain('--set=ON_ERROR_STOP=on')
        ->and($command)->toContain('--command=DROP SCHEMA IF EXISTS public CASCADE')
        ->and($command)->toContain('--command=CREATE SCHEMA public')
        ->and($command)->toContain('--file=/work/database.sql');

    // The drop/create must precede the dump so the dump lands in a clean schema.
    $dropIndex = array_search('--command=DROP SCHEMA IF EXISTS public CASCADE', $command, true);
    $fileIndex = array_search('--file=/work/database.sql', $command, true);

    expect($dropIndex)->toBeLessThan($fileIndex);
});

test('a missing dump file fails before shelling out to psql', function (): void {
    expect(fn () => (new PostgresDatabaseRestorer)->restore('/no/such/database.sql'))
        ->toThrow(RuntimeException::class);
});

test('base arguments carry host/port/user/db and default sensibly', function (): void {
    expect(PostgresConnection::baseArguments(['host' => 'h', 'port' => 6000, 'username' => 'x', 'database' => 'd']))
        ->toBe(['--host=h', '--port=6000', '--username=x', '--dbname=d'])
        ->and(PostgresConnection::baseArguments([]))
        ->toBe(['--host=127.0.0.1', '--port=5432', '--username=', '--dbname=']);
});

test('libpq environment carries the password and maps SSL, omitting blanks', function (): void {
    $env = PostgresConnection::libpqEnvironment([
        'password' => 'secret',
        'sslmode' => 'verify-full',
        'sslcert' => '',
        'sslkey' => null,
    ]);

    expect($env['PGPASSWORD'])->toBe('secret')
        ->and($env['PGSSLMODE'])->toBe('verify-full')
        ->and($env)->not->toHaveKey('PGSSLCERT')
        ->and($env)->not->toHaveKey('PGSSLKEY');
});
