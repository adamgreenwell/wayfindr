<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        return collect(self::states())
            ->mapWithKeys(function (string $state) use ($query): array {
                $count = self::whereState(clone $query, $state)->count();

                return $count > 0 ? [$state => $count] : [];
            })
            ->all();
    }

    /**
     * Narrow a ticket query to the state decided by `forTicket()`, without
     * hydrating every matching ticket and its audit history first.
     *
     * This is intentionally assembled from query-builder predicates instead
     * of driver-specific JSON SQL. Laravel compiles the `metadata->field`
     * selectors for both SQLite and PostgreSQL, and the parity test keeps this
     * second implementation pinned to the PHP state machine.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public static function whereState(Builder $query, string $state): Builder
    {
        if (! in_array($state, self::states(), true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $stateQuery) use ($state): void {
            if ($state === self::FAILED) {
                self::whereFailed($stateQuery);

                return;
            }

            self::whereNotFailed($stateQuery);

            if ($state === self::PENDING) {
                self::whereExternalLinkExists($stateQuery, ExternalIssueSyncStatus::PENDING);

                return;
            }

            self::whereExternalLinkExists($stateQuery, ExternalIssueSyncStatus::PENDING, not: true);

            if ($state === self::LINKED) {
                $stateQuery->where(function (Builder $linkedQuery): void {
                    self::whereExternalLinkExists($linkedQuery);
                    self::whereCurrentCreationExists($linkedQuery, boolean: 'or');
                });

                return;
            }

            self::whereExternalLinkExists($stateQuery, not: true);
            self::whereCurrentCreationExists($stateQuery, not: true);
        });
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
     * @return array<int, string>
     */
    private static function states(): array
    {
        return [self::FAILED, self::PENDING, self::LINKED, self::NONE];
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private static function whereFailed(Builder $query): void
    {
        $query->where(function (Builder $failedQuery): void {
            self::whereExternalLinkExists($failedQuery, ExternalIssueSyncStatus::FAILED);
            self::whereUnresolvedFailureExists($failedQuery, boolean: 'or');
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private static function whereNotFailed(Builder $query): void
    {
        self::whereExternalLinkExists($query, ExternalIssueSyncStatus::FAILED, not: true);
        self::whereUnresolvedFailureExists($query, not: true);
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private static function whereExternalLinkExists(
        Builder $query,
        ?string $syncStatus = null,
        string $boolean = 'and',
        bool $not = false,
    ): void {
        $query->whereExists(function (QueryBuilder $links) use ($syncStatus): void {
            $links
                ->selectRaw('1')
                ->from('ticket_external_links as external_state_links')
                ->whereColumn('external_state_links.ticket_id', 'tickets.id')
                ->whereColumn('external_state_links.account_id', 'tickets.account_id')
                ->when($syncStatus !== null, fn (QueryBuilder $links) => $links
                    ->where('external_state_links.sync_status', $syncStatus));
        }, $boolean, $not);
    }

    /**
     * A failed audit event remains current until a later successful creation
     * for the same provider and project resolves it.
     *
     * @param  Builder<Ticket>  $query
     */
    private static function whereUnresolvedFailureExists(
        Builder $query,
        string $boolean = 'and',
        bool $not = false,
    ): void {
        $query->whereExists(function (QueryBuilder $failures): void {
            $failures
                ->selectRaw('1')
                ->from('audit_events as external_state_failure')
                ->whereColumn('external_state_failure.subject_id', 'tickets.id')
                ->whereColumn('external_state_failure.account_id', 'tickets.account_id')
                ->where('external_state_failure.subject_type', (new Ticket)->getMorphClass())
                ->where('external_state_failure.action', 'ticket.external_sync_failed')
                ->whereNotExists(function (QueryBuilder $successes): void {
                    $successes
                        ->selectRaw('1')
                        ->from('audit_events as external_state_success')
                        ->whereColumn('external_state_success.subject_id', 'external_state_failure.subject_id')
                        ->whereColumn('external_state_success.account_id', 'external_state_failure.account_id')
                        ->whereColumn('external_state_success.subject_type', 'external_state_failure.subject_type')
                        ->where('external_state_success.action', 'ticket.external_issue_created');

                    self::whereSameJsonValue(
                        $successes,
                        'external_state_success',
                        'external_state_failure',
                        'provider'
                    );
                    self::whereSameJsonValue(
                        $successes,
                        'external_state_success',
                        'external_state_failure',
                        'site_external_issue_project_id'
                    );
                    self::whereEventIsAfter($successes, 'external_state_success', 'external_state_failure');
                });
        }, $boolean, $not);
    }

    /**
     * A creation remains current unless a later removal names the same link.
     *
     * @param  Builder<Ticket>  $query
     */
    private static function whereCurrentCreationExists(
        Builder $query,
        string $boolean = 'and',
        bool $not = false,
    ): void {
        $query->whereExists(function (QueryBuilder $creations): void {
            $creations
                ->selectRaw('1')
                ->from('audit_events as external_state_creation')
                ->whereColumn('external_state_creation.subject_id', 'tickets.id')
                ->whereColumn('external_state_creation.account_id', 'tickets.account_id')
                ->where('external_state_creation.subject_type', (new Ticket)->getMorphClass())
                ->where('external_state_creation.action', 'ticket.external_issue_created')
                ->whereNotExists(function (QueryBuilder $removals): void {
                    $removals
                        ->selectRaw('1')
                        ->from('audit_events as external_state_removal')
                        ->whereColumn('external_state_removal.subject_id', 'external_state_creation.subject_id')
                        ->whereColumn('external_state_removal.account_id', 'external_state_creation.account_id')
                        ->whereColumn('external_state_removal.subject_type', 'external_state_creation.subject_type')
                        ->where('external_state_removal.action', 'ticket.external_link_removed');

                    self::whereSameExternalLink($removals, 'external_state_creation', 'external_state_removal');
                    self::whereEventIsAfter($removals, 'external_state_removal', 'external_state_creation');
                });
        }, $boolean, $not);
    }

    private static function whereSameExternalLink(QueryBuilder $query, string $left, string $right): void
    {
        $leftProvider = self::jsonValue($query, $left, 'provider');
        $rightProvider = self::jsonValue($query, $right, 'provider');
        $leftReference = self::externalReferenceSql($query, $left);
        $rightReference = self::externalReferenceSql($query, $right);

        $query
            ->whereRaw("{$leftProvider} is not null and {$leftProvider} = {$rightProvider}")
            ->where(function (QueryBuilder $referenceQuery) use (
                $left,
                $right,
                $leftReference,
                $rightReference,
            ): void {
                $referenceQuery
                    ->where(function (QueryBuilder $withReference) use (
                        $left,
                        $right,
                        $leftReference,
                        $rightReference,
                    ): void {
                        $withReference
                            ->whereRaw("{$leftReference} is not null and {$rightReference} is not null")
                            ->whereRaw("{$leftReference} = {$rightReference}");
                        self::whereSameProject($withReference, $left, $right, allowMissingProject: true);
                    })
                    ->orWhere(function (QueryBuilder $withoutReference) use (
                        $left,
                        $right,
                        $leftReference,
                        $rightReference,
                    ): void {
                        $withoutReference->whereRaw("({$leftReference} is null or {$rightReference} is null)");
                        self::whereSameProject($withoutReference, $left, $right, allowMissingProject: false);
                    });
            });
    }

    private static function whereSameProject(
        QueryBuilder $query,
        string $left,
        string $right,
        bool $allowMissingProject,
    ): void {
        $leftId = self::jsonValue($query, $left, 'site_external_issue_project_id');
        $rightId = self::jsonValue($query, $right, 'site_external_issue_project_id');
        $leftKey = self::jsonValue($query, $left, 'project_key');
        $rightKey = self::jsonValue($query, $right, 'project_key');
        $bothIds = "{$leftId} is not null and {$rightId} is not null";
        $bothKeys = "{$leftKey} is not null and {$leftKey} <> ''"
            ." and {$rightKey} is not null and {$rightKey} <> ''";

        $query->where(function (QueryBuilder $projectQuery) use (
            $allowMissingProject,
            $leftId,
            $rightId,
            $leftKey,
            $rightKey,
            $bothIds,
            $bothKeys,
        ): void {
            $projectQuery
                ->whereRaw("({$bothIds}) and cast({$leftId} as text) = cast({$rightId} as text)")
                ->orWhereRaw("not ({$bothIds}) and ({$bothKeys}) and {$leftKey} = {$rightKey}");

            if ($allowMissingProject) {
                $projectQuery->orWhereRaw("not ({$bothIds}) and not ({$bothKeys})");
            }
        });
    }

    private static function whereSameJsonValue(
        QueryBuilder $query,
        string $left,
        string $right,
        string $field,
    ): void {
        $leftValue = self::jsonValue($query, $left, $field);
        $rightValue = self::jsonValue($query, $right, $field);

        $query->whereRaw("cast({$leftValue} as text) = cast({$rightValue} as text)");
    }

    private static function whereEventIsAfter(QueryBuilder $query, string $candidate, string $reference): void
    {
        $query->where(function (QueryBuilder $later) use ($candidate, $reference): void {
            $later
                ->where(function (QueryBuilder $dated) use ($candidate, $reference): void {
                    $dated
                        ->whereNotNull("{$candidate}.occurred_at")
                        ->whereNotNull("{$reference}.occurred_at")
                        ->where(function (QueryBuilder $after) use ($candidate, $reference): void {
                            $after
                                ->whereColumn("{$candidate}.occurred_at", '>', "{$reference}.occurred_at")
                                ->orWhere(function (QueryBuilder $tied) use ($candidate, $reference): void {
                                    $tied
                                        ->whereColumn("{$candidate}.occurred_at", "{$reference}.occurred_at")
                                        ->whereColumn("{$candidate}.id", '>', "{$reference}.id");
                                });
                        });
                })
                ->orWhere(function (QueryBuilder $undated) use ($candidate, $reference): void {
                    $undated
                        ->where(function (QueryBuilder $missingDate) use ($candidate, $reference): void {
                            $missingDate
                                ->whereNull("{$candidate}.occurred_at")
                                ->orWhereNull("{$reference}.occurred_at");
                        })
                        ->whereColumn("{$candidate}.id", '>', "{$reference}.id");
                });
        });
    }

    private static function externalReferenceSql(QueryBuilder $query, string $alias): string
    {
        $externalKey = self::jsonValue($query, $alias, 'external_key');
        $externalId = self::jsonValue($query, $alias, 'external_id');

        // PHP chooses the key before trimming it. A whitespace-only key is
        // therefore no reference, not a reason to fall back to external_id.
        return "case when {$externalKey} is not null and {$externalKey} <> '' and {$externalKey} <> '0'"
            ." then nullif(trim({$externalKey}), '') else nullif(trim({$externalId}), '') end";
    }

    private static function jsonValue(QueryBuilder $query, string $alias, string $field): string
    {
        return $query->getGrammar()->wrap("{$alias}.metadata->{$field}");
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
