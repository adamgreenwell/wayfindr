<?php

// pg_dump's libpq environment (ADR 0009): the password plus the app
// connection's SSL policy, so a dump to a remote Postgres honors the same
// TLS requirement the app enforces rather than libpq's looser default.

use App\Support\Backup\PostgresDatabaseDumper;

test('the password is always passed', function (): void {
    $env = (new PostgresDatabaseDumper)->environmentFor(['password' => 'secret']);

    expect($env['PGPASSWORD'])->toBe('secret');
});

test('SSL settings are mapped to the PGSSL* env vars', function (): void {
    $env = (new PostgresDatabaseDumper)->environmentFor([
        'password' => 'secret',
        'sslmode' => 'verify-full',
        'sslrootcert' => '/certs/ca.pem',
        'sslcert' => '/certs/client.pem',
        'sslkey' => '/certs/client.key',
    ]);

    expect($env['PGSSLMODE'])->toBe('verify-full')
        ->and($env['PGSSLROOTCERT'])->toBe('/certs/ca.pem')
        ->and($env['PGSSLCERT'])->toBe('/certs/client.pem')
        ->and($env['PGSSLKEY'])->toBe('/certs/client.key');
});

test('absent or blank SSL settings are omitted, not passed empty', function (): void {
    $env = (new PostgresDatabaseDumper)->environmentFor([
        'password' => 'secret',
        'sslmode' => '',
        'sslcert' => null,
    ]);

    expect($env)->not->toHaveKey('PGSSLMODE')
        ->and($env)->not->toHaveKey('PGSSLCERT')
        ->and($env)->not->toHaveKey('PGSSLROOTCERT');
});

test('the dump command excludes ephemeral table DATA but keeps the schema', function (): void {
    $command = (new PostgresDatabaseDumper)->dumpCommand(
        ['host' => 'db', 'port' => 5432, 'username' => 'u', 'database' => 'wayfindr'],
        '/tmp/out.sql',
    );

    // Data excluded (schema kept — the table still exists after restore).
    expect($command)->toContain('--exclude-table-data=public.sessions')
        ->and($command)->toContain('--exclude-table-data=public.password_reset_tokens')
        ->and($command)->toContain('--exclude-table-data=public.cache')
        ->and($command)->toContain('--exclude-table-data=public.jobs')
        ->and($command)->toContain('--exclude-table-data=public.failed_jobs')
        // Source-of-truth tables are NOT excluded.
        ->and(implode(' ', $command))->not->toContain('conversations')
        ->and($command)->toContain('--no-owner')
        ->and($command)->toContain('--file=/tmp/out.sql');
});

test('a renamed session table (SESSION_TABLE) is still excluded', function (): void {
    config()->set('session.table', 'wf_sessions');

    $command = (new PostgresDatabaseDumper)->dumpCommand(
        ['host' => 'db', 'database' => 'wayfindr'],
        '/tmp/out.sql',
    );

    expect($command)->toContain('--exclude-table-data=public.wf_sessions')
        ->and($command)->not->toContain('--exclude-table-data=public.sessions');
});

test('the null-valued cache lock_table key still excludes cache_locks', function (): void {
    // Default config sets cache.stores.database.lock_table to null (env unset);
    // config(key, default) would return null, dropping cache_locks — the ?:
    // coalesce catches it.
    config()->set('cache.stores.database.lock_table', null);

    $command = (new PostgresDatabaseDumper)->dumpCommand(
        ['host' => 'db', 'database' => 'wayfindr'],
        '/tmp/out.sql',
    );

    expect($command)->toContain('--exclude-table-data=public.cache_locks');
});
