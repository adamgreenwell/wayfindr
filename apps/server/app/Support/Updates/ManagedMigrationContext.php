<?php

declare(strict_types=1);

namespace App\Support\Updates;

use RuntimeException;

/** A process-local permission backed by a live operation-owned file lease. */
final class ManagedMigrationContext
{
    private static ?ManagedUpdateLease $lease = null;

    private static ?string $operation = null;

    public function during(ManagedUpdateLease $lease, string $operation, callable $migrate): int
    {
        if (self::$lease !== null) {
            throw new RuntimeException('managed_apply_busy');
        }

        $lease->assertProtective($operation);
        self::$lease = $lease;
        self::$operation = $operation;

        try {
            return $migrate();
        } finally {
            self::$lease = null;
            self::$operation = null;
        }
    }

    public function allows(string $command): bool
    {
        if ($command !== 'migrate' || self::$lease === null || self::$operation === null) {
            return false;
        }

        self::$lease->assertProtective(self::$operation);

        return true;
    }
}
