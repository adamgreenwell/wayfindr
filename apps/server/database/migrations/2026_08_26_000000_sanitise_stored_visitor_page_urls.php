<?php

use App\Support\Visitors\StoredPageUrlSweep;
use Illuminate\Database\Migrations\Migration;

/**
 * Rewrite page addresses already stored whole.
 *
 * The forward fix stops new query strings being kept. It does nothing about the
 * ones already stored -- and those are the reason this matters: a reset token
 * stored last month is on an agent's screen the next time somebody opens that
 * visitor's profile.
 *
 * THIS IS THE FIRST OF TWO PASSES, and on the supported zero-downtime deploy it
 * is not the one that finishes the job. `zero-downtime-deploy.forge` runs
 * `migrate` BEFORE `$ACTIVATE_RELEASE()`, so the previous release is still
 * serving widget traffic with the old unsanitised writers while this sweeps. A
 * row written after its chunk has been passed keeps its query string, and this
 * migration reports success anyway.
 *
 * `wayfindr:sanitise-page-urls` runs after activation and catches exactly those.
 * The logic lives in StoredPageUrlSweep so both passes are the same code rather
 * than two implementations that can drift apart.
 */
return new class extends Migration
{
    /**
     * NOT inside the migration's own transaction.
     *
     * PostgreSQL wraps a migration in one by default, which would make every
     * per-row transaction in the sweep a savepoint instead -- so each
     * `SELECT ... FOR UPDATE` would hold its lock until `up()` finished
     * scanning all three tables rather than releasing it per row.
     *
     * This migration deliberately runs while the previous release is still
     * serving. On a large install that would block writes to every row already
     * processed, for the length of the whole sweep, which is the opposite of
     * what a zero-downtime deploy is for.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        StoredPageUrlSweep::run();
    }

    /**
     * Irreversible on purpose.
     *
     * The query strings are gone, which is the point. A `down()` that pretended
     * otherwise would be a lie, and one that threw would block a rollback for
     * no reason.
     */
    public function down(): void {}
};
