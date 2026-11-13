<?php

declare(strict_types=1);

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Usage\DatabaseAiUsageClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\SQLiteConnection;

test('PostgreSQL usage clock samples advancing database wall time across UTC midnight', function (): void {
    $connection = Mockery::mock(Connection::class);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->twice()->andReturn($connection);
    $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
    $queries = [];
    $connection->shouldReceive('selectOne')->twice()->andReturnUsing(function (string $sql, array $bindings = [], bool $useReadPdo = true) use (&$queries): object {
        $queries[] = [$sql, $bindings, $useReadPdo];

        return count($queries) === 1
            ? (object) ['usage_now' => '2026-10-15 23:59:59.900000+00']
            : (object) ['usage_now' => '2026-10-16 02:00:00.100000+02'];
    });
    $clock = new DatabaseAiUsageClock($database);
    expect($clock->now()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-15 23:59:59.900000+00:00')
        ->and($clock->now()->format('Y-m-d H:i:s.uP'))->toBe('2026-10-16 00:00:00.100000+00:00')
        ->and($queries)->toBe(array_fill(0, 2, ['SELECT clock_timestamp() AS usage_now', [], false]),
            'Admission must sample PostgreSQL wall time on the lock-owning writer, not transaction-start time or a replica.');
});

test('SQLite usage clock comes from the database despite frozen PHP time and timezone', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, '', '', ['driver' => 'sqlite']);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $timezone = date_default_timezone_get();
    CarbonImmutable::setTestNow('1999-01-01 00:00:00');
    date_default_timezone_set('Pacific/Honolulu');
    try {
        $before = microtime(true);
        $now = (new DatabaseAiUsageClock($database))->now();
        expect($now->getTimestamp())->toBeGreaterThanOrEqual((int) $before)
            ->toBeLessThanOrEqual((int) microtime(true))
            ->and($now->getTimezone()->getName())->toBe('UTC')
            ->and($now->year)->not->toBe(1999);
    } finally {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($timezone);
    }
});

test('unavailable or malformed database clocks deny without PHP fallback or error chain', function (string $driver, mixed $value): void {
    $connection = Mockery::mock(Connection::class);
    $database = Mockery::mock(DatabaseManager::class);
    $database->shouldReceive('connection')->once()->andReturn($connection);
    $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
    if ($driver === 'pgsql') {
        if ($value instanceof Throwable) {
            $connection->shouldReceive('selectOne')->once()->andThrow($value);
        } else {
            $connection->shouldReceive('selectOne')->once()->andReturn((object) ['usage_now' => $value]);
        }
    }
    try {
        (new DatabaseAiUsageClock($database))->now();
        test()->fail('Unavailable database wall time must not fall back to PHP time.');
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe('usage_clock_unavailable')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('private-database-marker');
    }
})->with([
    'unsupported driver' => ['mysql', null],
    'missing value' => ['pgsql', null],
    'ambiguous timezone' => ['pgsql', '2026-10-15 12:00:00'],
    'invalid calendar date' => ['pgsql', '2026-02-30 12:00:00+00'],
    'unparseable value' => ['pgsql', 'private-database-marker'],
    'read failure' => ['pgsql', new RuntimeException('private-database-marker')],
]);
