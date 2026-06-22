<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TicketExternalIssueState
{
    public const string FAILED = 'failed';

    public const string PENDING = 'pending';

    public const string LINKED = 'linked';

    public const string NONE = 'none';

    /**
     * @param  Builder<Ticket>  $query
     * @return array<string, int>
     */
    public static function countsForQuery(Builder $query): array
    {
        return $query
            ->with([
                'auditEvents' => fn ($query) => $query->whereIn('action', self::trackedAuditActions()),
                'externalLinks',
            ])
            ->get()
            ->countBy(fn (Ticket $ticket): string => self::forTicket($ticket))
            ->all();
    }

    public static function forTicket(Ticket $ticket): string
    {
        $externalLinks = $ticket->relationLoaded('externalLinks')
            ? $ticket->externalLinks
            : $ticket->externalLinks()->get();
        $externalLinks = $externalLinks
            ->filter(fn ($externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id);

        $auditEvents = $ticket->relationLoaded('auditEvents')
            ? $ticket->auditEvents
            : $ticket->auditEvents()
                ->whereIn('action', self::trackedAuditActions())
                ->get();
        $auditEvents = $auditEvents
            ->where('account_id', $ticket->account_id);
        $successfulIssueCreations = $auditEvents
            ->where('action', 'ticket.external_issue_created')
            ->values();
        $removedExternalLinks = $auditEvents
            ->where('action', 'ticket.external_link_removed')
            ->values();
        $currentSuccessfulIssueCreations = $successfulIssueCreations
            ->reject(fn (AuditEvent $event): bool => self::externalIssueCreationWasRemoved($event, $removedExternalLinks))
            ->values();
        $failedEvents = $auditEvents
            ->where('action', 'ticket.external_sync_failed')
            ->reject(fn (AuditEvent $event): bool => self::externalIssueFailureWasResolved($event, $successfulIssueCreations));

        if ($externalLinks->where('sync_status', ExternalIssueSyncStatus::FAILED)->isNotEmpty() || $failedEvents->isNotEmpty()) {
            return self::FAILED;
        }

        if ($externalLinks->where('sync_status', ExternalIssueSyncStatus::PENDING)->isNotEmpty()) {
            return self::PENDING;
        }

        if ($externalLinks->isNotEmpty() || $currentSuccessfulIssueCreations->isNotEmpty()) {
            return self::LINKED;
        }

        return self::NONE;
    }

    /**
     * @return array<int, string>
     */
    public static function trackedAuditActions(): array
    {
        return [
            'ticket.external_issue_created',
            'ticket.external_link_removed',
            'ticket.external_sync_failed',
        ];
    }

    /**
     * @param  Collection<int, AuditEvent>  $successfulIssueCreations
     */
    private static function externalIssueFailureWasResolved(AuditEvent $failure, Collection $successfulIssueCreations): bool
    {
        $failedProjectId = data_get($failure->metadata, 'site_external_issue_project_id');
        $failedProvider = data_get($failure->metadata, 'provider');

        if (! is_numeric($failedProjectId) || ! is_string($failedProvider)) {
            return false;
        }

        return $successfulIssueCreations->contains(function (AuditEvent $success) use ($failure, $failedProjectId, $failedProvider): bool {
            return (int) data_get($success->metadata, 'site_external_issue_project_id') === (int) $failedProjectId
                && data_get($success->metadata, 'provider') === $failedProvider
                && self::externalIssueEventIsAfter($success, $failure);
        });
    }

    /**
     * @param  Collection<int, AuditEvent>  $removedExternalLinks
     */
    private static function externalIssueCreationWasRemoved(AuditEvent $creation, Collection $removedExternalLinks): bool
    {
        return $removedExternalLinks->contains(function (AuditEvent $removal) use ($creation): bool {
            return self::externalIssueEventsReferenceSameLink($creation, $removal)
                && self::externalIssueEventIsAfter($removal, $creation);
        });
    }

    private static function externalIssueEventsReferenceSameLink(AuditEvent $left, AuditEvent $right): bool
    {
        $leftProvider = data_get($left->metadata, 'provider');
        $rightProvider = data_get($right->metadata, 'provider');

        if (! is_string($leftProvider) || $leftProvider !== $rightProvider) {
            return false;
        }

        $leftReference = self::externalIssueEventReference($left);
        $rightReference = self::externalIssueEventReference($right);

        if ($leftReference !== null && $rightReference !== null) {
            return $leftReference === $rightReference
                && self::externalIssueEventsReferenceSameProject($left, $right, true);
        }

        return self::externalIssueEventsReferenceSameProject($left, $right, false);
    }

    private static function externalIssueEventsReferenceSameProject(AuditEvent $left, AuditEvent $right, bool $allowMissingProject): bool
    {
        $leftProjectId = data_get($left->metadata, 'site_external_issue_project_id');
        $rightProjectId = data_get($right->metadata, 'site_external_issue_project_id');

        if (is_numeric($leftProjectId) && is_numeric($rightProjectId)) {
            return (int) $leftProjectId === (int) $rightProjectId;
        }

        $leftProjectKey = data_get($left->metadata, 'project_key');
        $rightProjectKey = data_get($right->metadata, 'project_key');

        if (is_string($leftProjectKey) && $leftProjectKey !== '' && is_string($rightProjectKey) && $rightProjectKey !== '') {
            return $leftProjectKey === $rightProjectKey;
        }

        return $allowMissingProject;
    }

    private static function externalIssueEventReference(AuditEvent $event): ?string
    {
        $reference = data_get($event->metadata, 'external_key')
            ?: data_get($event->metadata, 'external_id');

        return is_string($reference) && trim($reference) !== ''
            ? trim($reference)
            : null;
    }

    private static function externalIssueEventIsAfter(AuditEvent $candidate, AuditEvent $reference): bool
    {
        if (! $candidate->occurred_at || ! $reference->occurred_at) {
            return (int) $candidate->id > (int) $reference->id;
        }

        if ($candidate->occurred_at->greaterThan($reference->occurred_at)) {
            return true;
        }

        return $candidate->occurred_at->equalTo($reference->occurred_at)
            && (int) $candidate->id > (int) $reference->id;
    }
}
