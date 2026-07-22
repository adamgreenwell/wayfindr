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
