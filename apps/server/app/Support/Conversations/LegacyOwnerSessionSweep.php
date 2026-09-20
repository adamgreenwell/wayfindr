<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Claim conversations that carry no owning session as predating the control.
 *
 * Run from the migration that adds the column and again after the new release is
 * activated, because on the supported zero-downtime path the migration works
 * while the PREVIOUS release is still serving widget traffic. That release does
 * not know the column exists, so a conversation it opens after this sweep has
 * passed keeps a null -- and null grants nothing, which would leave a visitor
 * unable to reach a conversation they had just started.
 *
 * Safe to run at any time, which is what lets it be scheduled. Every write path
 * that legitimately has no widget session now records `~none` explicitly, so a
 * null means only one thing: a row written before this control existed. After an
 * install has crossed the upgrade there are none, and this costs a scan and
 * reports nothing.
 *
 * Both passes are the same code rather than two, so a fix to one cannot miss the
 * other.
 */
class LegacyOwnerSessionSweep
{
    public static function run(): int
    {
        $total = 0;

        // A self-terminating loop rather than chunkById: that needs a stable
        // order and behaves differently on sqlite and Postgres. The predicate
        // removes each batch from its own result set, so this ends on its own.
        do {
            $claimed = DB::table('conversations')
                ->whereNull('owner_session_id')
                ->limit(1000)
                ->update(['owner_session_id' => Conversation::LEGACY_OWNER_SESSION]);

            $total += $claimed;
        } while ($claimed > 0);

        return $total;
    }
}
