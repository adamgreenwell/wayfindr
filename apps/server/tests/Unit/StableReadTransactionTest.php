<?php

use App\Support\Database\StableReadTransaction;
use Illuminate\Database\Connection;

test('a caller managed root PostgreSQL transaction starts with repeatable read', function (): void {
    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
        ->method('transactionLevel')
        ->willReturn(0);
    $connection->expects($this->once())
        ->method('beginTransaction');
    $connection->expects($this->once())
        ->method('getDriverName')
        ->willReturn('pgsql');
    $connection->expects($this->once())
        ->method('statement')
        ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    StableReadTransaction::begin($connection);
});

test('a stable read owns and configures its root PostgreSQL transaction', function (): void {
    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
        ->method('transactionLevel')
        ->willReturn(0);
    $connection->expects($this->once())
        ->method('transaction')
        ->willReturnCallback(fn (Closure $read): string => $read());
    $connection->expects($this->once())
        ->method('getDriverName')
        ->willReturn('pgsql');
    $connection->expects($this->once())
        ->method('statement')
        ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    expect(StableReadTransaction::run($connection, fn (): string => 'snapshot'))
        ->toBe('snapshot');
});

test('a nested read preserves its callers transaction isolation', function (): void {
    $connection = $this->createMock(Connection::class);
    $connection->expects($this->once())
        ->method('transactionLevel')
        ->willReturn(1);
    $connection->expects($this->never())->method('transaction');
    $connection->expects($this->never())->method('statement');

    expect(StableReadTransaction::run($connection, fn (): string => 'caller snapshot'))
        ->toBe('caller snapshot');
});
