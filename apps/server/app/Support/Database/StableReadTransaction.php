<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;

/**
 * Run multi-query reads against one coherent database snapshot.
 */
final class StableReadTransaction
{
    /**
     * Begin a caller-managed transaction with stable read isolation.
     *
     * When a test or caller already owns the root transaction, it also owns
     * that transaction's isolation level; PostgreSQL cannot change it after
     * the first query. The new savepoint still preserves the caller's rollback
     * boundary.
     */
    public static function begin(Connection $connection): void
    {
        $ownsRootTransaction = $connection->transactionLevel() === 0;

        $connection->beginTransaction();

        if ($ownsRootTransaction) {
            self::configure($connection);
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    public static function run(Connection $connection, Closure $read): mixed
    {
        if ($connection->transactionLevel() > 0) {
            return $read();
        }

        return $connection->transaction(function () use ($connection, $read): mixed {
            self::configure($connection);

            return $read();
        });
    }

    private static function configure(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            // PostgreSQL accepts this after BEGIN and before the first query.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }
}
