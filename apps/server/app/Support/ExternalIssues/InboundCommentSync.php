<?php

namespace App\Support\ExternalIssues;

use App\Models\TicketExternalLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records inbound external-issue comments as internal notes on the linked
 * Wayfindr ticket, and keeps a bounded ledger of comment ids we have already
 * seen. That ledger is the echo-loop guard: the outbound relay records the id
 * of every comment it posts, so when the provider's webhook delivers that same
 * comment back, we skip it instead of mirroring our own note. The ledger also
 * makes inbound delivery idempotent under webhook retries.
 *
 * The check-and-write runs under a row lock on the link so concurrent
 * deliveries of the same comment (parallel webhook retries or manual
 * redeliveries) serialize rather than both recording a note.
 */
class InboundCommentSync
{
    private const LEDGER_CAP = 200;

    private const BODY_LIMIT = 4000;

    /**
     * Remember an external comment id (called by the outbound relay for every
     * comment it posts) so the inbound webhook does not echo it back.
     */
    public function remember(TicketExternalLink $link, string $commentId): void
    {
        $commentId = trim($commentId);

        if ($commentId === '') {
            return;
        }

        DB::transaction(function () use ($link, $commentId): void {
            $locked = $this->lockLink($link);

            if ($locked) {
                $this->appendLedgerId($locked, $commentId);
            }
        });
    }

    /**
     * Record an inbound external comment as an internal note, unless we have
     * already synced this comment id (our own relayed comment, or a webhook
     * retry). Returns true only when a note was actually recorded.
     */
    public function record(TicketExternalLink $link, string $commentId, string $body, ?string $author, string $source): bool
    {
        $commentId = trim($commentId);
        $body = trim($body);

        if ($commentId === '' || $body === '') {
            return false;
        }

        return DB::transaction(function () use ($link, $commentId, $body, $author, $source): bool {
            $locked = $this->lockLink($link);
            $ticket = $locked?->ticket;

            if (! $locked || ! $ticket) {
                return false;
            }

            // The dedup check and the ledger write happen under the same lock,
            // so a concurrent delivery of this id cannot slip a second note in.
            if (in_array($commentId, $this->ledger($locked), true)) {
                return false;
            }

            $this->appendLedgerId($locked, $commentId);

            $ticket->auditEvents()->create([
                'account_id' => $locked->account_id,
                'site_id' => $locked->site_id,
                'actor_type' => null,
                'actor_id' => null,
                'action' => 'ticket.external_comment_received',
                'metadata' => [
                    'provider' => $locked->provider,
                    'external_key' => $locked->external_key,
                    'external_comment_id' => $commentId,
                    'author' => filled($author) ? Str::limit(trim((string) $author), 120) : null,
                    'body' => Str::limit($body, self::BODY_LIMIT),
                    'source' => $source,
                ],
                'occurred_at' => now(),
            ]);

            return true;
        });
    }

    private function lockLink(TicketExternalLink $link): ?TicketExternalLink
    {
        return TicketExternalLink::query()
            ->whereKey($link->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return list<string>
     */
    private function ledger(TicketExternalLink $link): array
    {
        $ids = data_get($link->metadata, 'synced_comment_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $ids,
        )));
    }

    private function appendLedgerId(TicketExternalLink $lockedLink, string $commentId): void
    {
        $ids = $this->ledger($lockedLink);

        if (in_array($commentId, $ids, true)) {
            return;
        }

        $ids[] = $commentId;

        // Keep the ledger bounded; only recent ids matter for the loop/retry guard.
        if (count($ids) > self::LEDGER_CAP) {
            $ids = array_slice($ids, -self::LEDGER_CAP);
        }

        $lockedLink->forceFill([
            'metadata' => array_merge($lockedLink->metadata ?? [], ['synced_comment_ids' => $ids]),
        ])->save();
    }
}
