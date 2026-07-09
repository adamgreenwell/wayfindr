<?php

namespace App\Support\ExternalIssues;

use App\Models\ExternalIssueProviderConnection;
use App\Models\TicketExternalLink;

/**
 * Shared inbound-webhook reflection: match a provider issue event to the
 * linked Wayfindr ticket and record the external open/closed state on it.
 * State is reflected, never enforced — the Wayfindr ticket is never
 * auto-closed; that stays the agent's decision. Every provider receiver
 * (GitHub, GitLab, …) verifies authenticity its own way, then funnels here.
 */
class InboundIssueStateSync
{
    public function linkFor(ExternalIssueProviderConnection $connection, string $externalId): ?TicketExternalLink
    {
        if (trim($externalId) === '') {
            return null;
        }

        return TicketExternalLink::query()
            ->where('account_id', $connection->account_id)
            ->where('provider', $connection->provider)
            ->where('external_id', $externalId)
            ->whereJsonContains('metadata->external_issue_provider_connection_id', $connection->id)
            ->first();
    }

    public function reflect(TicketExternalLink $link, string $state, string $source): void
    {
        $previousState = data_get($link->metadata, 'external_state');

        $link->forceFill([
            'last_synced_at' => now(),
            'metadata' => array_merge($link->metadata ?? [], [
                'external_state' => $state,
                'external_state_synced_at' => now()->toJSON(),
            ]),
        ])->save();

        if ($previousState === $state) {
            return;
        }

        $link->ticket?->auditEvents()->create([
            'account_id' => $link->account_id,
            'site_id' => $link->site_id,
            'actor_type' => null,
            'actor_id' => null,
            'action' => 'ticket.external_issue_state_changed',
            'metadata' => [
                'provider' => $link->provider,
                'project_key' => $link->project_key,
                'external_key' => $link->external_key,
                'external_state' => $state,
                'source' => $source,
            ],
            'occurred_at' => now(),
        ]);
    }
}
