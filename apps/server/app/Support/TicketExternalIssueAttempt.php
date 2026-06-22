<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class TicketExternalIssueAttempt
{
    /**
     * @param  Collection<int, TicketExternalLink>|null  $externalLinks
     * @param  Collection<int, AuditEvent>|null  $auditEvents
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null}
     */
    public static function latestForTicket(Ticket $ticket, ?Collection $externalLinks = null, ?Collection $auditEvents = null): array
    {
        return self::latestCueForTicket($ticket, $externalLinks, $auditEvents) ?? [
            'label' => 'No external attempt yet',
            'body' => 'Create or link an external issue when this ticket needs work in another tracker.',
            'occurred_at' => null,
        ];
    }

    /**
     * @param  Collection<int, TicketExternalLink>|null  $externalLinks
     * @param  Collection<int, AuditEvent>|null  $auditEvents
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null}|null
     */
    public static function latestCueForTicket(Ticket $ticket, ?Collection $externalLinks = null, ?Collection $auditEvents = null): ?array
    {
        $linkAttempts = self::externalLinksForTicket($ticket, $externalLinks)
            ->map(fn (TicketExternalLink $externalLink): array => self::linkAttemptItem($externalLink))
            ->toBase();
        $eventAttempts = self::auditEventsForTicket($ticket, $auditEvents)
            ->map(fn (AuditEvent $event): array => self::eventAttemptItem($event))
            ->toBase();

        $attempt = $linkAttempts
            ->merge($eventAttempts)
            ->sortByDesc(fn (array $attempt): string => sprintf(
                '%020d.%020d',
                $attempt['occurred_at']?->getTimestamp() ?? 0,
                $attempt['sequence'],
            ))
            ->first();

        if (! $attempt) {
            return null;
        }

        return [
            'body' => $attempt['body'],
            'label' => $attempt['label'],
            'occurred_at' => $attempt['occurred_at'],
        ];
    }

    public static function eventProjectKey(AuditEvent $event): string
    {
        $projectKey = data_get($event->metadata, 'project_key');

        return is_string($projectKey) && trim($projectKey) !== ''
            ? trim($projectKey)
            : 'Project not recorded';
    }

    /**
     * @param  Collection<int, TicketExternalLink>|null  $externalLinks
     * @return Collection<int, TicketExternalLink>
     */
    private static function externalLinksForTicket(Ticket $ticket, ?Collection $externalLinks = null): Collection
    {
        $externalLinks ??= $ticket->relationLoaded('externalLinks')
            ? $ticket->externalLinks
            : $ticket->externalLinks()->get();

        return $externalLinks
            ->filter(fn (TicketExternalLink $externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id)
            ->values();
    }

    /**
     * @param  Collection<int, AuditEvent>|null  $auditEvents
     * @return Collection<int, AuditEvent>
     */
    private static function auditEventsForTicket(Ticket $ticket, ?Collection $auditEvents = null): Collection
    {
        $auditEvents ??= $ticket->auditEvents()
            ->whereIn('action', TicketExternalIssueState::trackedAuditActions())
            ->get();

        return $auditEvents
            ->where('account_id', $ticket->account_id)
            ->whereIn('action', TicketExternalIssueState::trackedAuditActions())
            ->values();
    }

    /**
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null, sequence: int}
     */
    private static function linkAttemptItem(TicketExternalLink $externalLink): array
    {
        $provider = $externalLink->providerLabel();
        $projectKey = $externalLink->project_key ?: 'Project not recorded';
        $externalReference = $externalLink->external_key ?: $externalLink->external_id;
        $occurredAt = $externalLink->last_synced_at ?? $externalLink->updated_at;

        return match ($externalLink->sync_status) {
            ExternalIssueSyncStatus::FAILED => [
                'body' => "{$projectKey} needs attention. Provider details withheld.",
                'label' => "{$provider} sync failed",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            ExternalIssueSyncStatus::PENDING => [
                'body' => "{$projectKey} is waiting for provider confirmation.",
                'label' => "{$provider} sync pending",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            default => [
                'body' => $externalReference
                    ? "{$projectKey} is linked to {$externalReference}."
                    : "{$projectKey} is linked.",
                'label' => "{$provider} link active",
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
        };
    }

    /**
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null, sequence: int}
     */
    private static function eventAttemptItem(AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');
        $providerLabel = ExternalIssueProvider::label(is_string($provider) ? $provider : null);
        $projectKey = self::eventProjectKey($event);

        if ($event->action === 'ticket.external_link_removed') {
            $externalReference = self::eventReference($event);

            return [
                'body' => $externalReference
                    ? "{$projectKey} is no longer linked to {$externalReference}."
                    : "{$projectKey} external link was removed.",
                'label' => "{$providerLabel} link removed",
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        if ($event->action === 'ticket.external_issue_created') {
            $externalReference = self::eventReference($event);

            return [
                'body' => $externalReference
                    ? "{$projectKey} is linked to {$externalReference}."
                    : "{$projectKey} was created in the external tracker.",
                'label' => "{$providerLabel} issue created",
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        return [
            'body' => "{$projectKey} needs attention. Provider details withheld.",
            'label' => "{$providerLabel} sync failed",
            'occurred_at' => $event->occurred_at,
            'sequence' => (int) $event->id,
        ];
    }

    private static function eventReference(AuditEvent $event): ?string
    {
        $externalReference = data_get($event->metadata, 'external_key')
            ?: data_get($event->metadata, 'external_id');

        return is_string($externalReference) && trim($externalReference) !== ''
            ? trim($externalReference)
            : null;
    }
}
