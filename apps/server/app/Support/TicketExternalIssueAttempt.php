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
     * @return array{label: string, label_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, body: string, body_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, occurred_at: CarbonInterface|null}
     */
    public static function latestForTicket(Ticket $ticket, ?Collection $externalLinks = null, ?Collection $auditEvents = null): array
    {
        return self::latestCueForTicket($ticket, $externalLinks, $auditEvents) ?? [
            'label' => __('tickets.external_attempt.none_label'),
            'label_feedback' => self::feedback('tickets.external_attempt.none_label'),
            'body' => __('tickets.external_attempt.none_body'),
            'body_feedback' => self::feedback('tickets.external_attempt.none_body'),
            'occurred_at' => null,
        ];
    }

    /**
     * @param  Collection<int, TicketExternalLink>|null  $externalLinks
     * @param  Collection<int, AuditEvent>|null  $auditEvents
     * @return array{label: string, label_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, body: string, body_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, occurred_at: CarbonInterface|null}|null
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
            'body_feedback' => $attempt['body_feedback'],
            'label' => $attempt['label'],
            'label_feedback' => $attempt['label_feedback'],
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
        // Queried unless the CALLER hands over a collection, and deliberately
        // not `relationLoaded()`. Loaded is not the same as loaded with these
        // rows: the ticket detail page eager-loads `auditEvents` constrained to
        // `ticket.note_added`, which is not a tracked action, so reusing it
        // because it happens to be present reports "no external attempt yet" on
        // a ticket whose latest attempt is an event. Only a caller knows whether
        // its own eager load matches this scope.
        $auditEvents ??= $ticket->auditEvents()
            ->whereIn('action', TicketExternalIssueState::trackedAuditActions())
            ->get();

        return $auditEvents
            ->where('account_id', $ticket->account_id)
            ->whereIn('action', TicketExternalIssueState::trackedAuditActions())
            ->values();
    }

    /**
     * @return array{label: string, label_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, body: string, body_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, occurred_at: CarbonInterface|null, sequence: int}
     */
    private static function linkAttemptItem(TicketExternalLink $externalLink): array
    {
        $provider = self::providerLabel($externalLink->provider);
        $hasProjectKey = filled($externalLink->project_key);
        $projectKey = $hasProjectKey ? $externalLink->project_key : __('tickets.external_attempt.project_unknown');
        $projectParameters = $hasProjectKey ? ['project' => $projectKey] : [];
        $localizedProjectParameters = $hasProjectKey ? [] : ['project' => $projectKey];
        $externalReference = $externalLink->external_key ?: $externalLink->external_id;
        $occurredAt = $externalLink->last_synced_at ?? $externalLink->updated_at;

        return match ($externalLink->sync_status) {
            ExternalIssueSyncStatus::FAILED => [
                'body' => __('tickets.external_attempt.failed_body', ['project' => $projectKey]),
                'body_feedback' => self::feedback('tickets.external_attempt.failed_body', $projectParameters, $localizedProjectParameters),
                'label' => __('tickets.external_attempt.failed_label', ['provider' => $provider]),
                'label_feedback' => self::providerFeedback('tickets.external_attempt.failed_label', $externalLink->provider),
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            ExternalIssueSyncStatus::PENDING => [
                'body' => __('tickets.external_attempt.pending_body', ['project' => $projectKey]),
                'body_feedback' => self::feedback('tickets.external_attempt.pending_body', $projectParameters, $localizedProjectParameters),
                'label' => __('tickets.external_attempt.pending_label', ['provider' => $provider]),
                'label_feedback' => self::providerFeedback('tickets.external_attempt.pending_label', $externalLink->provider),
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
            default => [
                'body' => $externalReference
                    ? __('tickets.external_attempt.linked_body', ['project' => $projectKey, 'reference' => $externalReference])
                    : __('tickets.external_attempt.linked_body_bare', ['project' => $projectKey]),
                'body_feedback' => self::feedback(
                    $externalReference ? 'tickets.external_attempt.linked_body' : 'tickets.external_attempt.linked_body_bare',
                    [...$projectParameters, ...($externalReference ? ['reference' => $externalReference] : [])],
                    $localizedProjectParameters,
                ),
                'label' => __('tickets.external_attempt.linked_label', ['provider' => $provider]),
                'label_feedback' => self::providerFeedback('tickets.external_attempt.linked_label', $externalLink->provider),
                'occurred_at' => $occurredAt,
                'sequence' => (int) $externalLink->id,
            ],
        };
    }

    /**
     * @return array{label: string, label_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, body: string, body_feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, occurred_at: CarbonInterface|null, sequence: int}
     */
    private static function eventAttemptItem(AuditEvent $event): array
    {
        $provider = data_get($event->metadata, 'provider');
        $providerLabel = self::providerLabel(is_string($provider) ? $provider : null);
        $projectKey = self::eventProjectKey($event);
        $hasProjectKey = is_string(data_get($event->metadata, 'project_key'))
            && trim((string) data_get($event->metadata, 'project_key')) !== '';
        $projectParameters = $hasProjectKey ? ['project' => $projectKey] : [];
        $localizedProjectParameters = $hasProjectKey ? [] : ['project' => $projectKey];

        if ($event->action === 'ticket.external_link_removed') {
            $externalReference = self::eventReference($event);

            return [
                'body' => $externalReference
                    ? __('tickets.external_attempt.removed_body', ['project' => $projectKey, 'reference' => $externalReference])
                    : __('tickets.external_attempt.removed_body_bare', ['project' => $projectKey]),
                'body_feedback' => self::feedback(
                    $externalReference ? 'tickets.external_attempt.removed_body' : 'tickets.external_attempt.removed_body_bare',
                    [...$projectParameters, ...($externalReference ? ['reference' => $externalReference] : [])],
                    $localizedProjectParameters,
                ),
                'label' => __('tickets.external_attempt.removed_label', ['provider' => $providerLabel]),
                'label_feedback' => self::providerFeedback('tickets.external_attempt.removed_label', is_string($provider) ? $provider : null),
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
                'body_feedback' => self::feedback(
                    $externalReference ? 'tickets.external_attempt.created_body' : 'tickets.external_attempt.created_body_bare',
                    [...$projectParameters, ...($externalReference ? ['reference' => $externalReference] : [])],
                    $localizedProjectParameters,
                ),
                'label' => __('tickets.external_attempt.created_label', ['provider' => $providerLabel]),
                'label_feedback' => self::providerFeedback('tickets.external_attempt.created_label', is_string($provider) ? $provider : null),
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
            'body_feedback' => self::feedback('tickets.external_attempt.failed_body', $projectParameters, $localizedProjectParameters),
            'label' => __('tickets.external_attempt.failed_label', ['provider' => $providerLabel]),
            'label_feedback' => self::providerFeedback('tickets.external_attempt.failed_label', is_string($provider) ? $provider : null),
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

    /**
     * @param  array<string, string>  $parameters
     * @param  array<string, string>  $localizedParameters
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private static function feedback(string $key, array $parameters = [], array $localizedParameters = []): array
    {
        return [
            'key' => $key,
            'parameters' => $parameters,
            'localized_parameters' => $localizedParameters,
        ];
    }

    private static function providerLabel(?string $provider): string
    {
        return match ($provider) {
            'other' => __('ticket_detail.external.provider_other'),
            null => __('ticket_detail.external.provider_unknown'),
            default => ExternalIssueProvider::options()[$provider] ?? __('ticket_detail.external.provider_unknown'),
        };
    }

    /**
     * Product names are proper nouns with no reliable language. Generic and
     * unknown provider labels come from the active dashboard catalogue.
     *
     * @return array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}
     */
    private static function providerFeedback(string $key, ?string $provider): array
    {
        $label = self::providerLabel($provider);
        $isBrand = $provider !== null
            && $provider !== 'other'
            && array_key_exists($provider, ExternalIssueProvider::options());

        return self::feedback(
            $key,
            $isBrand ? ['provider' => $label] : [],
            $isBrand ? [] : ['provider' => $label],
        );
    }
}
