<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final /*
 * Reached only from controllers and views -- never a job, command or mail build
 * -- so it may use the catalogue directly. A class that CAN run outside a
 * request must hand out keys instead; see Ticket::attentionLabelKey().
 */ class TicketExternalIssueAttempt
{
    /**
     * @param  Collection<int, TicketExternalLink>|null  $externalLinks
     * @param  Collection<int, AuditEvent>|null  $auditEvents
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null}
     */
    public static function latestForTicket(Ticket $ticket, ?Collection $externalLinks = null, ?Collection $auditEvents = null): array
    {
        return self::latestCueForTicket($ticket, $externalLinks, $auditEvents) ?? [
            'label' => __('tickets.external_attempt.none_label'),
            'body' => __('tickets.external_attempt.none_body'),
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
            : __('tickets.external_attempt.project_unknown');
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
        // The loaded relation FIRST, matching this class's own
        // `externalLinksForTicket()` and `TicketExternalIssueState::forTicket()`.
        // Going straight to the relation threw away the ticket queue's eager
        // load and cost one query per ticket -- 12,499 of them on a desk with
        // 12,500 tickets. Of the three helpers reading these two relations, this
        // was the only one missing the check.
        $auditEvents ??= $ticket->relationLoaded('auditEvents')
            ? $ticket->auditEvents
            : $ticket->auditEvents()
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
        $projectKey = $externalLink->project_key ?: __('tickets.external_attempt.project_unknown');
        $externalReference = $externalLink->external_key ?: $externalLink->external_id;
        $occurredAt = $externalLink->last_synced_at ?? $externalLink->updated_at;

        return match ($externalLink->sync_status) {
            ExternalIssueSyncStatus::FAILED => [
                'body' => __('tickets.external_attempt.failed_body', ['project' => $projectKey]),
                'label' => __('tickets.external_attempt.failed_label', ['provider' => $provider]),
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            ExternalIssueSyncStatus::PENDING => [
                'body' => __('tickets.external_attempt.pending_body', ['project' => $projectKey]),
                'label' => __('tickets.external_attempt.pending_label', ['provider' => $provider]),
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            default => [
                'body' => $externalReference
                    ? __('tickets.external_attempt.linked_body', ['project' => $projectKey, 'reference' => $externalReference])
                    : __('tickets.external_attempt.linked_body_bare', ['project' => $projectKey]),
                'label' => __('tickets.external_attempt.linked_label', ['provider' => $provider]),
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
                    ? __('tickets.external_attempt.removed_body', ['project' => $projectKey, 'reference' => $externalReference])
                    : __('tickets.external_attempt.removed_body_bare', ['project' => $projectKey]),
                'label' => __('tickets.external_attempt.removed_label', ['provider' => $providerLabel]),
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        if ($event->action === 'ticket.external_issue_created') {
            $externalReference = self::eventReference($event);

            return [
                'body' => $externalReference
                    ? __('tickets.external_attempt.created_body', ['project' => $projectKey, 'reference' => $externalReference])
                    : __('tickets.external_attempt.created_body_bare', ['project' => $projectKey]),
                'label' => __('tickets.external_attempt.created_label', ['provider' => $providerLabel]),
                'occurred_at' => $event->occurred_at,
                'sequence' => (int) $event->id,
            ];
        }

        // The fall-through, which every audit action that is not a create or
        // a remove lands in -- `ticket.external_sync_failed` among them. It was
        // the one branch of this class still building English, and it is the
        // most common of the three.
        return [
            'body' => __('tickets.external_attempt.failed_body', ['project' => $projectKey]),
            'label' => __('tickets.external_attempt.failed_label', ['provider' => $providerLabel]),
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
