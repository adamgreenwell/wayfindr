<?php

use App\Support\Visitors\StoredPageUrlSweep;
use Illuminate\Database\Migrations\Migration;

/**
 * Sweep the addresses cobrowse stored whole.
 *
 * The forward fix (#818) stopped new ones being kept and rewrote nothing that
 * was already on disk. Cobrowse is the copy that matters most for that: it
 * holds four addresses per session, and it keeps them longest. Pruning strips
 * the heavy payloads on schedule and preserves the addresses deliberately, so
 * an unsanitised one written before the forward fix outlives every other trace
 * of the session it came from -- and a reset token in a query string is on an
 * agent's screen the next time anybody opens that conversation.
 *
 * A second migration rather than editing the first: installs that already ran
 * `2026_08_26_000000` have it recorded, and a migration that has run does not
 * run again however its body changes.
 *
 * Everything else about the shape is inherited from that first sweep, including
 * the reason there are two passes -- see its docblock, and
 * `wayfindr:sanitise-page-urls`, which the deploy script runs after activation
 * and which now covers cobrowse as well.
 */
return new class extends Migration
{
    /**
     * NOT inside the migration's own transaction, for the reason the first
     * sweep gives: PostgreSQL would turn every per-row transaction into a
     * savepoint, holding each `SELECT ... FOR UPDATE` until `up()` finished
     * rather than releasing it per row -- blocking writes to every row already
     * processed, for the length of the whole sweep, while the previous release
     * is still serving.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        StoredPageUrlSweep::run();
    }

    /**
     * Irreversible on purpose. The query strings are gone, which is the point.
     */
    public function down(): void {}
};
