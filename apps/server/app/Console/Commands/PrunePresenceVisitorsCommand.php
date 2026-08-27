<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Visitor;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete visitors who never made contact and have stopped coming back.
 *
 * The product's first automatic retention control (ADR 0019 §4), and it ships
 * with the collection rather than after it. Without presence, `visitors` grows
 * when somebody opens a chat; with it, the table grows with every unique
 * visitor, which turns the absence of pruning from a gap into a defect.
 *
 * Measured from `last_seen_at`, not `created_at`, and which timestamp is the
 * whole rule. From `created_at`, somebody first seen 31 days ago is deleted
 * WHILE they are on the site heartbeating and reappears seconds later as brand
 * new -- the board loses them mid-visit and `returning or new` starts lying.
 * From activity, a row goes only once nobody has been behind it for a month.
 *
 * A visitor who HAS made contact is never touched. They are support history,
 * and support history is not presence data -- deleting them would take their
 * conversations' requester with them.
 */
class PrunePresenceVisitorsCommand extends Command
{
    protected $signature = 'wayfindr:prune-presence-visitors {--days= : Override the retention window}';

    protected $description = 'Delete presence-only visitors whose last heartbeat is past the retention window';

    /**
     * ADR 0019 §4. An operator may shorten this; they may not lengthen it, so
     * it is the product's maximum retention for a presence-only row rather than
     * merely its default.
     */
    public const MAXIMUM_DAYS = 30;

    public function handle(): int
    {
        $days = $this->retentionDays();
        $cutoff = now()->subDays($days);

        $deleted = 0;

        Visitor::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            // Never made contact, in the three ways contact is recorded. A
            // visitor with any of them is support history.
            ->whereDoesntHave('conversations')
            ->whereDoesntHave('tickets')
            ->orderBy('id')
            ->chunkById(500, function ($visitors) use (&$deleted, $cutoff): void {
                foreach ($visitors as $visitor) {
                    if ($this->deleteIfStillStale((int) $visitor->id, $cutoff)) {
                        $deleted++;
                    }
                }
            });

        $this->info($deleted === 0
            ? sprintf('No presence-only visitor is older than %d days.', $days)
            : sprintf('Deleted %d presence-only visitor(s) last seen over %d days ago.', $deleted, $days));

        return self::SUCCESS;
    }

    /**
     * Delete only if the reasons still hold, checked under a lock.
     *
     * The chunk is a snapshot. A visitor selected as stale can heartbeat, open
     * a chat or raise a ticket before the loop reaches them, and an
     * unconditional delete by primary key ignores all of it -- worse than
     * useless, because `conversations.visitor_id` cascades, so the support
     * request that just landed is deleted along with them. Reversed, an insert
     * arriving after the delete fails on a foreign key and the visitor is told
     * their message could not be sent.
     *
     * So the predicates are re-evaluated inside the transaction that deletes,
     * against a locked row, rather than trusted from the earlier read.
     */
    private function deleteIfStillStale(int $id, CarbonInterface $cutoff): bool
    {
        return (bool) DB::transaction(function () use ($id, $cutoff): bool {
            $visitor = Visitor::query()->whereKey($id)->lockForUpdate()->first();

            if ($visitor === null) {
                return false;
            }

            if ($visitor->last_seen_at === null || $visitor->last_seen_at->greaterThanOrEqualTo($cutoff)) {
                // They came back while we were working, which is the best
                // possible reason not to delete somebody.
                return false;
            }

            if ($visitor->conversations()->exists() || $visitor->tickets()->exists()) {
                return false;
            }

            $visitor->delete();

            return true;
        });
    }

    private function retentionDays(): int
    {
        $configured = (int) ($this->option('days') ?: config('wayfindr.presence.retention_days', self::MAXIMUM_DAYS));

        // Clamped rather than trusted. An operator may shorten the window; a
        // configuration that lengthens it would quietly raise the product's
        // stated maximum, which is a decision this command does not get to
        // make on their behalf.
        return max(1, min($configured, self::MAXIMUM_DAYS));
    }
}
