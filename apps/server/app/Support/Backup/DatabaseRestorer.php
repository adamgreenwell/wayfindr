<?php

namespace App\Support\Backup;

/**
 * Restores a SQL dump (produced by DatabaseDumper) into the application
 * database, REPLACING its current contents. The production implementation
 * drops and recreates the schema and applies the dump with psql in a single
 * transaction; tests bind a fake so RestoreService can be exercised without a
 * live Postgres (ADR 0009).
 */
interface DatabaseRestorer
{
    public function restore(string $sqlFile): void;
}
