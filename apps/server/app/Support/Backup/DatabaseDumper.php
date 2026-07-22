<?php

namespace App\Support\Backup;

/**
 * Writes a restorable SQL dump of the application database to a file. The
 * production implementation shells out to pg_dump; tests bind a fake so the
 * archive assembly can be exercised without a live Postgres.
 */
interface DatabaseDumper
{
    /**
     * @return string a short human label of what produced the dump (for the
     *                backup manifest, e.g. the pg_dump/server version)
     */
    public function dump(string $destination): string;
}
