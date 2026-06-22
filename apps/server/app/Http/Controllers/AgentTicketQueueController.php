<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExternalIssueSyncStatus;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AgentTicketQueueController extends Controller
{
    private const string EXTERNAL_ISSUE_FAILED = 'failed';

    private const string EXTERNAL_ISSUE_PENDING = 'pending';

    private const string EXTERNAL_ISSUE_LINKED = 'linked';

    private const string EXTERNAL_ISSUE_NONE = 'none';

    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('latestVisitor')
            ->orderBy('name')
            ->get();

        return view('agent.tickets.index', [
            'account' => $account,
            'agent' => $agent,
            'sites' => $sites,
            ...$this->ticketQueueData($agent, $account, $sites, $request),
        ]);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    private function ticketQueueData(User $agent, Account $account, Collection $sites, Request $request): array
    {
        $ticketFilters = [
            'all' => 'Any assignee',
            'assigned_to_me' => 'Assigned to me',
            'unassigned' => 'Unassigned',
        ];
        $ticketFilter = $request->query('ticket_filter', 'all');
        $ticketFilter = is_string($ticketFilter) && array_key_exists($ticketFilter, $ticketFilters)
            ? $ticketFilter
            : 'all';
        $ticketStatusFilters = [
            'open' => 'All open',
            'pending' => 'Pending',
            'closed' => 'Closed',
            'all' => 'All tickets',
        ];
        $ticketStatus = $request->query('ticket_status', 'open');
        $ticketStatus = is_string($ticketStatus) && array_key_exists($ticketStatus, $ticketStatusFilters)
            ? $ticketStatus
            : 'open';
        $ticketPriorityFilters = [
            'all' => 'Any priority',
            ...array_map(fn (array $priority): string => $priority['label'], TicketPriority::guidanceOptions()),
        ];
        $ticketPriority = $request->query('ticket_priority', 'all');
        $ticketPriority = is_string($ticketPriority) && array_key_exists($ticketPriority, $ticketPriorityFilters)
            ? $ticketPriority
            : 'all';
        $ticketCategoryFilters = [
            'all' => 'Any category',
            'uncategorized' => 'Uncategorized',
            ...array_map(fn (array $category): string => $category['label'], TicketCategory::options()),
        ];
        $ticketCategory = $request->query('ticket_category', 'all');
        $ticketCategory = is_string($ticketCategory) && array_key_exists($ticketCategory, $ticketCategoryFilters)
            ? $ticketCategory
            : 'all';
        $ticketLabels = $account->ticketLabels()
            ->orderBy('name')
            ->get();
        $ticketLabelFilters = [
            'all' => 'Any label',
            ...$ticketLabels->pluck('name', 'slug')->all(),
        ];
        $ticketLabel = $request->query('ticket_label', 'all');
        $ticketLabel = is_string($ticketLabel) && array_key_exists($ticketLabel, $ticketLabelFilters)
            ? $ticketLabel
            : 'all';
        $ticketAttentionFilters = [
            'all' => 'Any next step',
            'escalated' => 'Recently escalated',
            'needs_reply' => 'Needs reply',
            'needs_owner' => 'Needs owner',
            'needs_agent' => 'Needs agent',
            'waiting_on_customer' => 'Waiting on customer',
            'resolved' => 'Resolved',
        ];
        $ticketAttention = $request->query('ticket_attention', 'all');
        $ticketAttention = is_string($ticketAttention) && array_key_exists($ticketAttention, $ticketAttentionFilters)
            ? $ticketAttention
            : 'all';
        $ticketExternalIssueFilters = [
            'all' => 'Any external issue',
            self::EXTERNAL_ISSUE_FAILED => 'Needs attention',
            self::EXTERNAL_ISSUE_PENDING => 'Sync pending',
            self::EXTERNAL_ISSUE_LINKED => 'Linked',
            self::EXTERNAL_ISSUE_NONE => 'No external issue',
        ];
        $ticketExternalIssue = $request->query('ticket_external', 'all');
        $ticketExternalIssue = is_string($ticketExternalIssue) && array_key_exists($ticketExternalIssue, $ticketExternalIssueFilters)
            ? $ticketExternalIssue
            : 'all';
        if ($ticketAttention === 'resolved' && ! in_array($ticketStatus, ['closed', 'all'], true)) {
            $ticketStatus = 'closed';
        }
        $requestedTicketSite = $request->query('ticket_site');
        $ticketSite = is_string($requestedTicketSite) && ctype_digit($requestedTicketSite) && $sites->contains('id', (int) $requestedTicketSite)
            ? (int) $requestedTicketSite
            : null;
        $ticketSearch = $request->query('ticket_search', '');
        $ticketSearch = is_string($ticketSearch)
            ? mb_substr(trim($ticketSearch), 0, 120)
            : '';
        $ticketStatusSummary = match ($ticketStatus) {
            'all' => 'total',
            default => $ticketStatus,
        };
        $ticketHasActiveRefinement = $ticketFilter !== 'all'
            || $ticketSite
            || $ticketPriority !== 'all'
            || $ticketCategory !== 'all'
            || $ticketLabel !== 'all'
            || $ticketAttention !== 'all'
            || $ticketExternalIssue !== 'all'
            || $ticketSearch !== '';
        $ticketEmptyMessage = $ticketHasActiveRefinement
            ? 'No tickets match those filters.'
            : match ($ticketStatus) {
                'all' => 'No tickets yet.',
                'pending' => 'No pending tickets yet.',
                'closed' => 'No closed tickets yet.',
                default => 'No open tickets yet.',
            };
        $ticketQuery = $this->ticketQueryParams($ticketStatus, $ticketFilter, $ticketSite, $ticketPriority, $ticketCategory, $ticketLabel, $ticketAttention, $ticketExternalIssue, $ticketSearch);
        $ticketResults = Ticket::query()
            ->with([
                'assignee',
                'auditEvents' => fn ($query) => $query->whereIn('action', [
                    'ticket.external_issue_created',
                    'ticket.external_link_removed',
                    'ticket.external_sync_failed',
                ]),
                'conversation.latestAgentMessage',
                'conversation.latestMessage',
                'externalLinks',
                'labels',
                'latestEscalationEvent.actor',
                'site',
            ])
            ->where('account_id', $account->id)
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->when($ticketStatus !== 'all', fn ($query) => $query->where('status', $ticketStatus))
            ->when($ticketFilter === 'assigned_to_me', fn ($query) => $query->where('assignee_id', $agent->id))
            ->when($ticketFilter === 'unassigned', fn ($query) => $query->whereNull('assignee_id'))
            ->when($ticketSite, fn ($query) => $query->where('site_id', $ticketSite))
            ->when($ticketPriority !== 'all', fn ($query) => $query->where('priority', $ticketPriority))
            ->when($ticketCategory === 'uncategorized', fn ($query) => $query->whereNull('category'))
            ->when($ticketCategory !== 'all' && $ticketCategory !== 'uncategorized', fn ($query) => $query->where('category', $ticketCategory))
            ->when($ticketLabel !== 'all', fn ($query) => $query->whereHas('labels', fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('slug', $ticketLabel)))
            ->when($ticketSearch !== '', function ($query) use ($ticketSearch): void {
                $searchPattern = '%'.$ticketSearch.'%';
                $ticketReferenceId = $this->ticketReferenceId($ticketSearch);

                $query->where(function ($query) use ($searchPattern, $ticketReferenceId): void {
                    $query
                        ->whereLike('subject', $searchPattern)
                        ->orWhereLike('description', $searchPattern)
                        ->orWhereHas('conversation', fn ($query) => $query->whereLike('support_code', $searchPattern))
                        ->orWhereHas('requester', fn ($query) => $query
                            ->whereLike('external_id', $searchPattern)
                            ->orWhereLike('anonymous_id', $searchPattern)
                            ->orWhereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern));

                    if ($ticketReferenceId) {
                        $query->orWhere('id', $ticketReferenceId);
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();
        $ticketResults = $ticketResults
            ->filter(fn (Ticket $ticket): bool => $ticketExternalIssue === 'all'
                || $this->ticketExternalIssueState($ticket) === $ticketExternalIssue)
            ->values();
        $ticketQueueSummary = $this->ticketQueueSummary($ticketResults, $ticketQuery, $ticketAttentionFilters);
        $tickets = $ticketResults
            ->filter(fn (Ticket $ticket): bool => $ticketAttention === 'all' || $this->ticketDashboardAttentionState($ticket) === $ticketAttention)
            ->sortBy(fn (Ticket $ticket): array => [
                $this->ticketDashboardAttentionSortRank($ticket),
                -$ticket->updated_at->getTimestamp(),
                -$ticket->created_at->getTimestamp(),
            ])
            ->values();

        return [
            'ticketAttention' => $ticketAttention,
            'ticketAttentionFilters' => $ticketAttentionFilters,
            'ticketCategory' => $ticketCategory,
            'ticketCategoryFilters' => $ticketCategoryFilters,
            'ticketEmptyMessage' => $ticketEmptyMessage,
            'ticketFilter' => $ticketFilter,
            'ticketFilters' => $ticketFilters,
            'ticketLabel' => $ticketLabel,
            'ticketLabelFilters' => $ticketLabelFilters,
            'ticketPriority' => $ticketPriority,
            'ticketPriorityFilters' => $ticketPriorityFilters,
            'ticketQueueSummary' => $ticketQueueSummary,
            'ticketActiveFilters' => $this->activeTicketFilters(
                $ticketQuery,
                $ticketStatus,
                $ticketStatusFilters,
                $ticketFilter,
                $ticketFilters,
                $ticketSite,
                $sites,
                $ticketPriority,
                $ticketPriorityFilters,
                $ticketCategory,
                $ticketCategoryFilters,
                $ticketLabel,
                $ticketLabelFilters,
                $ticketAttention,
                $ticketAttentionFilters,
                $ticketExternalIssue,
                $ticketExternalIssueFilters,
                $ticketSearch,
            ),
            'ticketExternalIssue' => $ticketExternalIssue,
            'ticketExternalIssueFilters' => $ticketExternalIssueFilters,
            'ticketExternalIssueStates' => $tickets
                ->mapWithKeys(fn (Ticket $ticket): array => [
                    $ticket->id => $this->ticketExternalIssueStateCue($this->ticketExternalIssueState($ticket)),
                ]),
            'ticketQuery' => $ticketQuery,
            'ticketSearch' => $ticketSearch,
            'ticketSite' => $ticketSite,
            'ticketStatus' => $ticketStatus,
            'ticketStatusFilters' => $ticketStatusFilters,
            'ticketStatusSummary' => $ticketStatusSummary,
            'tickets' => $tickets,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function ticketQueryParams(string $ticketStatus, string $ticketFilter, ?int $ticketSite, string $ticketPriority, string $ticketCategory, string $ticketLabel, string $ticketAttention, string $ticketExternalIssue, string $ticketSearch): array
    {
        $params = [];

        if ($ticketStatus !== 'open') {
            $params['ticket_status'] = $ticketStatus;
        }

        if ($ticketFilter !== 'all') {
            $params['ticket_filter'] = $ticketFilter;
        }

        if ($ticketSite) {
            $params['ticket_site'] = $ticketSite;
        }

        if ($ticketPriority !== 'all') {
            $params['ticket_priority'] = $ticketPriority;
        }

        if ($ticketCategory !== 'all') {
            $params['ticket_category'] = $ticketCategory;
        }

        if ($ticketLabel !== 'all') {
            $params['ticket_label'] = $ticketLabel;
        }

        if ($ticketAttention !== 'all') {
            $params['ticket_attention'] = $ticketAttention;
        }

        if ($ticketExternalIssue !== 'all') {
            $params['ticket_external'] = $ticketExternalIssue;
        }

        if ($ticketSearch !== '') {
            $params['ticket_search'] = $ticketSearch;
        }

        return $params;
    }

    private function ticketReferenceId(string $ticketSearch): ?int
    {
        $ticketSearch = trim($ticketSearch);

        if ($ticketSearch === '') {
            return null;
        }

        if (ctype_digit($ticketSearch)) {
            return (int) $ticketSearch;
        }

        if (preg_match('/^(?:ticket\s*)?#\s*(\d+)$/i', $ticketSearch, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^ticket\s+(\d+)$/i', $ticketSearch, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, string|int>  $ticketQuery
     * @param  array<string, string>  $ticketStatusFilters
     * @param  array<string, string>  $ticketFilters
     * @param  Collection<int, Site>  $sites
     * @param  array<string, string>  $ticketPriorityFilters
     * @param  array<string, string>  $ticketCategoryFilters
     * @param  array<string, string>  $ticketLabelFilters
     * @param  array<string, string>  $ticketAttentionFilters
     * @return array<int, array{label: string, href: string}>
     */
    private function activeTicketFilters(
        array $ticketQuery,
        string $ticketStatus,
        array $ticketStatusFilters,
        string $ticketFilter,
        array $ticketFilters,
        ?int $ticketSite,
        Collection $sites,
        string $ticketPriority,
        array $ticketPriorityFilters,
        string $ticketCategory,
        array $ticketCategoryFilters,
        string $ticketLabel,
        array $ticketLabelFilters,
        string $ticketAttention,
        array $ticketAttentionFilters,
        string $ticketExternalIssue,
        array $ticketExternalIssueFilters,
        string $ticketSearch,
    ): array {
        $filters = [];

        if ($ticketStatus !== 'open') {
            $filters[] = $this->ticketFilterChip('ticket_status', 'Status: '.$ticketStatusFilters[$ticketStatus], $ticketQuery);
        }

        if ($ticketFilter !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_filter', 'Assignee: '.$ticketFilters[$ticketFilter], $ticketQuery);
        }

        if ($ticketSite) {
            $site = $sites->firstWhere('id', $ticketSite);

            if ($site) {
                $filters[] = $this->ticketFilterChip('ticket_site', 'Site: '.$site->name, $ticketQuery);
            }
        }

        if ($ticketPriority !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_priority', 'Priority: '.$ticketPriorityFilters[$ticketPriority], $ticketQuery);
        }

        if ($ticketCategory !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_category', 'Category: '.$ticketCategoryFilters[$ticketCategory], $ticketQuery);
        }

        if ($ticketLabel !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_label', 'Label: '.$ticketLabelFilters[$ticketLabel], $ticketQuery);
        }

        if ($ticketAttention !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_attention', 'Next step: '.$ticketAttentionFilters[$ticketAttention], $ticketQuery);
        }

        if ($ticketExternalIssue !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_external', 'External issue: '.$ticketExternalIssueFilters[$ticketExternalIssue], $ticketQuery);
        }

        if ($ticketSearch !== '') {
            $filters[] = $this->ticketFilterChip('ticket_search', 'Search: '.$ticketSearch, $ticketQuery);
        }

        return $filters;
    }

    /**
     * @param  array<string, string|int>  $ticketQuery
     * @return array{label: string, href: string}
     */
    private function ticketFilterChip(string $queryKey, string $label, array $ticketQuery): array
    {
        unset($ticketQuery[$queryKey]);

        return [
            'label' => $label,
            'href' => route('dashboard.tickets.index', $ticketQuery),
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @param  array<string, string|int>  $ticketQuery
     * @param  array<string, string>  $ticketAttentionFilters
     * @return array<int, array{state: string, label: string, count: int, href: string}>
     */
    private function ticketQueueSummary(Collection $tickets, array $ticketQuery, array $ticketAttentionFilters): array
    {
        $counts = $tickets->countBy(fn (Ticket $ticket): string => $this->ticketDashboardAttentionState($ticket));

        return collect(['escalated', 'needs_reply', 'needs_owner', 'needs_agent', 'waiting_on_customer', 'resolved'])
            ->map(function (string $state) use ($counts, $ticketAttentionFilters, $ticketQuery): array {
                $query = $ticketQuery;
                $query['ticket_attention'] = $state;

                if ($state === 'resolved' && ! in_array($query['ticket_status'] ?? 'open', ['closed', 'all'], true)) {
                    $query['ticket_status'] = 'closed';
                }

                return [
                    'state' => $state,
                    'label' => $ticketAttentionFilters[$state],
                    'count' => (int) ($counts[$state] ?? 0),
                    'href' => route('dashboard.tickets.index', $query),
                ];
            })
            ->all();
    }

    private function ticketDashboardAttentionState(Ticket $ticket): string
    {
        return $ticket->hasRecentEscalation()
            ? 'escalated'
            : $ticket->attentionState();
    }

    private function ticketDashboardAttentionSortRank(Ticket $ticket): int
    {
        return $this->ticketDashboardAttentionState($ticket) === 'escalated'
            ? 5
            : $ticket->attentionSortRank();
    }

    private function ticketExternalIssueState(Ticket $ticket): string
    {
        $externalLinks = $ticket->externalLinks
            ->filter(fn ($externalLink): bool => (int) $externalLink->account_id === (int) $ticket->account_id
                && (int) $externalLink->ticket_id === (int) $ticket->id);

        $auditEvents = $ticket->auditEvents
            ->where('account_id', $ticket->account_id);
        $successfulIssueCreations = $auditEvents
            ->where('action', 'ticket.external_issue_created')
            ->values();
        $removedExternalLinks = $auditEvents
            ->where('action', 'ticket.external_link_removed')
            ->values();
        $currentSuccessfulIssueCreations = $successfulIssueCreations
            ->reject(fn (AuditEvent $event): bool => $this->externalIssueCreationWasRemoved($event, $removedExternalLinks))
            ->values();
        $failedEvents = $auditEvents
            ->where('action', 'ticket.external_sync_failed')
            ->reject(fn (AuditEvent $event): bool => $this->externalIssueFailureWasResolved($event, $successfulIssueCreations));

        if ($externalLinks->where('sync_status', ExternalIssueSyncStatus::FAILED)->isNotEmpty() || $failedEvents->isNotEmpty()) {
            return self::EXTERNAL_ISSUE_FAILED;
        }

        if ($externalLinks->where('sync_status', ExternalIssueSyncStatus::PENDING)->isNotEmpty()) {
            return self::EXTERNAL_ISSUE_PENDING;
        }

        if ($externalLinks->isNotEmpty() || $currentSuccessfulIssueCreations->isNotEmpty()) {
            return self::EXTERNAL_ISSUE_LINKED;
        }

        return self::EXTERNAL_ISSUE_NONE;
    }

    /**
     * @return array{label: string, tone: string, detail: string}
     */
    private function ticketExternalIssueStateCue(string $state): array
    {
        return match ($state) {
            self::EXTERNAL_ISSUE_FAILED => [
                'label' => 'Needs attention',
                'tone' => 'attention',
                'detail' => 'Open the ticket to review safe retry options.',
            ],
            self::EXTERNAL_ISSUE_PENDING => [
                'label' => 'Sync pending',
                'tone' => 'manual',
                'detail' => 'Waiting for external tracker confirmation.',
            ],
            self::EXTERNAL_ISSUE_LINKED => [
                'label' => 'Linked',
                'tone' => 'ready',
                'detail' => 'External tracker reference is attached.',
            ],
            default => [
                'label' => 'No external issue',
                'tone' => 'manual',
                'detail' => 'Wayfindr is the only tracker for this ticket.',
            ],
        };
    }

    /**
     * @param  Collection<int, AuditEvent>  $successfulIssueCreations
     */
    private function externalIssueFailureWasResolved(AuditEvent $failure, Collection $successfulIssueCreations): bool
    {
        $failedProjectId = data_get($failure->metadata, 'site_external_issue_project_id');
        $failedProvider = data_get($failure->metadata, 'provider');

        if (! is_numeric($failedProjectId) || ! is_string($failedProvider)) {
            return false;
        }

        return $successfulIssueCreations->contains(function (AuditEvent $success) use ($failure, $failedProjectId, $failedProvider): bool {
            return (int) data_get($success->metadata, 'site_external_issue_project_id') === (int) $failedProjectId
                && data_get($success->metadata, 'provider') === $failedProvider
                && $this->externalIssueEventIsAfter($success, $failure);
        });
    }

    /**
     * @param  Collection<int, AuditEvent>  $removedExternalLinks
     */
    private function externalIssueCreationWasRemoved(AuditEvent $creation, Collection $removedExternalLinks): bool
    {
        return $removedExternalLinks->contains(function (AuditEvent $removal) use ($creation): bool {
            return $this->externalIssueEventsReferenceSameLink($creation, $removal)
                && $this->externalIssueEventIsAfter($removal, $creation);
        });
    }

    private function externalIssueEventsReferenceSameLink(AuditEvent $left, AuditEvent $right): bool
    {
        $leftProvider = data_get($left->metadata, 'provider');
        $rightProvider = data_get($right->metadata, 'provider');

        if (! is_string($leftProvider) || $leftProvider !== $rightProvider) {
            return false;
        }

        $leftReference = $this->externalIssueEventReference($left);
        $rightReference = $this->externalIssueEventReference($right);

        if ($leftReference !== null && $rightReference !== null) {
            return $leftReference === $rightReference
                && $this->externalIssueEventsReferenceSameProject($left, $right, true);
        }

        return $this->externalIssueEventsReferenceSameProject($left, $right, false);
    }

    private function externalIssueEventsReferenceSameProject(AuditEvent $left, AuditEvent $right, bool $allowMissingProject): bool
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

    private function externalIssueEventReference(AuditEvent $event): ?string
    {
        $reference = data_get($event->metadata, 'external_key')
            ?: data_get($event->metadata, 'external_id');

        return is_string($reference) && trim($reference) !== ''
            ? trim($reference)
            : null;
    }

    private function externalIssueEventIsAfter(AuditEvent $candidate, AuditEvent $reference): bool
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
