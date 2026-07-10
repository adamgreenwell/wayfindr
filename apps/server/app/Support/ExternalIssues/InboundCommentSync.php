<?php

namespace App\Support\ExternalIssues;

use App\Models\TicketExternalLink;
use Illuminate\Support\Str;

/**
 * Records inbound external-issue comments as internal notes on the linked
 * Wayfindr ticket, and keeps a bounded ledger of comment ids we have already
 * seen. That ledger is the echo-loop guard: the outbound relay records the id
 * of every comment it posts, so when the provider's webhook delivers that same
 * comment back, we skip it instead of mirroring our own note. The ledger also
 * makes inbound delivery idempotent under webhook retries.
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

        if ($commentId === '' || $this->alreadySynced($link, $commentId)) {
            return;
        }

        $ids = $this->ledger($link);
        $ids[] = $commentId;

        $this->writeLedger($link, $ids);
    }

    public function alreadySynced(TicketExternalLink $link, string $commentId): bool
    {
        return in_array(trim($commentId), $this->ledger($link), true);
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
        $ticket = $link->ticket;

        if ($commentId === '' || $body === '' || ! $ticket) {
            return false;
        }

        if ($this->alreadySynced($link, $commentId)) {
            return false;
        }

        $this->remember($link, $commentId);

        $ticket->auditEvents()->create([
            'account_id' => $link->account_id,
            'site_id' => $link->site_id,
            'actor_type' => null,
            'actor_id' => null,
            'action' => 'ticket.external_comment_received',
            'metadata' => [
                'provider' => $link->provider,
                'external_key' => $link->external_key,
                'external_comment_id' => $commentId,
                'author' => filled($author) ? Str::limit(trim((string) $author), 120) : null,
                'body' => Str::limit($body, self::BODY_LIMIT),
                'source' => $source,
            ],
            'occurred_at' => now(),
        ]);

        return true;
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

    /**
     * @param  list<string>  $ids
     */
    private function writeLedger(TicketExternalLink $link, array $ids): void
    {
        // Keep the ledger bounded; only recent ids matter for the loop/retry guard.
        if (count($ids) > self::LEDGER_CAP) {
            $ids = array_slice($ids, -self::LEDGER_CAP);
        }

        $link->forceFill([
            'metadata' => array_merge($link->metadata ?? [], ['synced_comment_ids' => $ids]),
        ])->save();
    }
}
