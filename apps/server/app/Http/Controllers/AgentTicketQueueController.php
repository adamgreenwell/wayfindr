<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketCategory;
use App\Support\TicketExternalIssueAttempt;
use App\Support\TicketExternalIssueState;
use App\Support\TicketPriority;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AgentTicketQueueController extends Controller
{
    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id && $agent->hasAccountPermission(AccountPermission::ManageTickets), 403);

        $account = $agent->account()->firstOrFail();
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('latestVisitor')
            ->orderBy('name')
            ->get();

        return view('agent.tickets.index', [
            'account' => $account,
            'agent' => $agent,
            'canViewTicketConversations' => $agent->hasAccountPermission(AccountPermission::ViewConversations),
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
        $canViewTicketConversations = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        // Keyed by the query-string value, which is the contract with the
        // URL and must not move when the label does.
        $ticketFilters = $this->translatedOptions('tickets.filters.assignee', ['all', 'assigned_to_me', 'unassigned']);
        $ticketFilter = $request->query('ticket_filter', 'all');
        $ticketFilter = is_string($ticketFilter) && array_key_exists($ticketFilter, $ticketFilters)
            ? $ticketFilter
            : 'all';
        $ticketStatusFilters = $this->translatedOptions('tickets.filters.status', ['open', 'pending', 'closed', 'all']);
        $ticketStatus = $request->query('ticket_status', 'open');
        $ticketStatus = is_string($ticketStatus) && array_key_exists($ticketStatus, $ticketStatusFilters)
            ? $ticketStatus
            : 'open';
        // Display names come from the catalogue rather than TicketPriority,
        // whose descriptions and guidance belong to the ticket forms and
        // extract with that surface.
        $ticketPriorityFilters = [
            'all' => __('tickets.filters.priority_any'),
            ...$this->translatedOptions('tickets.priorities', array_keys(TicketPriority::guidanceOptions())),
        ];
        $ticketPriority = $request->query('ticket_priority', 'all');
        $ticketPriority = is_string($ticketPriority) && array_key_exists($ticketPriority, $ticketPriorityFilters)
            ? $ticketPriority
            : 'all';
        $ticketCategoryFilters = [
            'all' => __('tickets.filters.category_any'),
            'uncategorized' => __('tickets.filters.category_uncategorized'),
            ...$this->translatedOptions('tickets.categories', array_keys(TicketCategory::options())),
        ];
        $ticketCategory = $request->query('ticket_category', 'all');
        $ticketCategory = is_string($ticketCategory) && array_key_exists($ticketCategory, $ticketCategoryFilters)
            ? $ticketCategory
            : 'all';
        $ticketLabels = $account->ticketLabels()
            ->orderBy('name')
            ->get();
        // Label names are the account's own words and are never translated.
        $ticketLabelFilters = [
            'all' => __('tickets.filters.label_any'),
            ...$ticketLabels->pluck('name', 'slug')->all(),
        ];
        $ticketLabel = $request->query('ticket_label', 'all');
        $ticketLabel = is_string($ticketLabel) && array_key_exists($ticketLabel, $ticketLabelFilters)
            ? $ticketLabel
            : 'all';
        $ticketAttentionFilters = $this->translatedOptions('tickets.filters.attention', [
            'all', 'escalated', 'needs_reply', 'needs_owner', 'needs_agent', 'waiting_on_customer', 'resolved',
        ]);
        $ticketAttention = $request->query('ticket_attention', 'all');
        $ticketAttention = is_string($ticketAttention) && array_key_exists($ticketAttention, $ticketAttentionFilters)
            ? $ticketAttention
            : 'all';
        $ticketExternalIssueFilters = [
            'all' => __('tickets.filters.external.all'),
            TicketExternalIssueState::FAILED => __('tickets.filters.external.failed'),
            TicketExternalIssueState::PENDING => __('tickets.filters.external.pending'),
            TicketExternalIssueState::LINKED => __('tickets.filters.external.linked'),
            TicketExternalIssueState::NONE => __('tickets.filters.external.none'),
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
        // The catalogue KEY for the heading's noun, not the noun itself:
        // "1 open" is a count and a word agreeing with it, which German
        // inflects and English does not.
        $ticketStatusSummary = $ticketStatus === 'all' ? 'total' : $ticketStatus;
        $ticketHasActiveRefinement = $ticketFilter !== 'all'
            || $ticketSite
            || $ticketPriority !== 'all'
            || $ticketCategory !== 'all'
            || $ticketLabel !== 'all'
            || $ticketAttention !== 'all'
            || $ticketExternalIssue !== 'all'
            || $ticketSearch !== '';
        $ticketEmptyMessage = $ticketHasActiveRefinement
            ? __('tickets.empty.no_match_filters')
            : match ($ticketStatus) {
                'all' => __('tickets.empty.none_yet'),
                'pending' => __('tickets.empty.no_pending'),
                'closed' => __('tickets.empty.no_closed'),
                default => __('tickets.empty.no_open'),
            };
        $ticketQuery = $this->ticketQueryParams($ticketStatus, $ticketFilter, $ticketSite, $ticketPriority, $ticketCategory, $ticketLabel, $ticketAttention, $ticketExternalIssue, $ticketSearch);
        $ticketEmptyState = $this->ticketEmptyState(
            $ticketEmptyMessage,
            $ticketHasActiveRefinement,
            $ticketQuery,
            $ticketAttention,
            $ticketExternalIssue,
            $ticketSearch,
            // First-run guidance only when the visible scope has no tickets
            // at all — a status-only empty view (no closed tickets yet, say)
            // must not tell agents to go create tickets they already have.
            accountHasNoTickets: ! Ticket::query()
                ->where('account_id', $account->id)
                ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
                ->exists(),
        );
        $ticketResults = Ticket::query()
            ->with([
                'assignee',
                'auditEvents' => fn ($query) => $query->whereIn('action', TicketExternalIssueState::trackedAuditActions()),
                'conversation.latestAgentMessage',
                'conversation.latestMessage',
                'conversation.latestNonIntegrationMessage',
                'externalLinks',
                'labels',
                'latestEscalationEvent.actor',
                'latestLifecycleEvent.actor',
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
            ->when($ticketSearch !== '', function ($query) use ($ticketSearch, $canViewTicketConversations): void {
                $searchPattern = '%'.$ticketSearch.'%';
                $ticketReferenceId = $this->ticketReferenceId($ticketSearch);

                $query->where(function ($query) use ($searchPattern, $ticketReferenceId, $canViewTicketConversations): void {
                    $query
                        ->whereLike('subject', $searchPattern)
                        ->orWhereLike('description', $searchPattern)
                        ->orWhereHas('requester', fn ($query) => $query
                            ->whereLike('external_id', $searchPattern)
                            ->orWhereLike('anonymous_id', $searchPattern)
                            ->orWhereLike('name', $searchPattern)
                            ->orWhereLike('email', $searchPattern));

                    if ($canViewTicketConversations) {
                        $query->orWhereHas('conversation', fn ($query) => $query->whereLike('support_code', $searchPattern));
                    }

                    if ($ticketReferenceId) {
                        $query->orWhere('id', $ticketReferenceId);
                    }
                });
            });
        // The attention state and the queue's ordering are decided in SQL
        // now. They were computed per ticket in PHP and then sorted in PHP,
        // which is why this page cannot be capped: a limit would take an
        // arbitrary window and sort within it (#847). Moving them into the
        // query is the prerequisite, and `TicketAttentionStateParityTest`
        // holds the SQL and the PHP rule in step ticket by ticket.

        // External-issue state is queryable now too. It pairs creation/removal
        // and failure/success audit events in correlated subqueries, preserving
        // the PHP state machine while letting every refinement happen before
        // rows are hydrated. `TicketExternalIssueStateParityTest` is the guard
        // against the two implementations drifting.
        if ($ticketExternalIssue !== 'all') {
            TicketExternalIssueState::whereState($ticketResults, $ticketExternalIssue);
        }

        // The chips need EVERY state's count, so they are taken before the
        // attention filter narrows the list to one. On the default path that is
        // one grouped query rather than a tally over every hydrated row, which
        // is part of what made this page unbounded.
        //
        // From the base query, BEFORE the row selects: cloning a query that
        // already selects `attention_state` and selecting it again leaves
        // PostgreSQL with two columns of that name and an ambiguous `group by`.
        $ticketAttentionCounts = (clone $ticketResults)->attentionStateCounts();
        $matchingTicketCount = array_sum($ticketAttentionCounts);
        $ticketLaneCount = $ticketAttention === 'all'
            ? $matchingTicketCount
            : (int) ($ticketAttentionCounts[$ticketAttention] ?? 0);

        $ticketResults = $ticketResults
            ->select(['tickets.*'])
            ->selectAttentionState()
            ->when(
                $ticketAttention !== 'all',
                fn ($query) => $query->whereAttentionState($ticketAttention)
            )
            ->orderByAttention()
            // The count above stays uncapped. Only the row graphs and HTML are
            // bounded, so a busy lane reads as 200 of 12,431 rather than as
            // 200 tickets that appear to be the entire desk (#847).
            ->limit(Ticket::QUEUE_DISPLAY_LIMIT)
            ->get();
        $ticketQueueSummary = $this->ticketQueueSummary($ticketAttentionCounts, $ticketQuery, $ticketAttentionFilters);
        $tickets = $ticketResults;

        // The rows are already narrowed by attention in SQL, so the "matching"
        // half of this sentence has to come from the grouped counts rather than
        // from the list -- they are the same collection now, and comparing it
        // with itself reports nothing as narrowed.
        //
        $ticketQueueCountSummary = $this->ticketQueueCountSummary(
            $ticketLaneCount,
            $tickets->count(),
            $matchingTicketCount,
            $ticketStatusSummary,
            $ticketAttention,
            $ticketAttentionFilters,
        );

        return [
            'ticketAttention' => $ticketAttention,
            'ticketAttentionFilters' => $ticketAttentionFilters,
            'ticketCategory' => $ticketCategory,
            'ticketCategoryFilters' => $ticketCategoryFilters,
            'ticketEmptyMessage' => $ticketEmptyMessage,
            'ticketEmptyState' => $ticketEmptyState,
            'ticketFilter' => $ticketFilter,
            'ticketFilters' => $ticketFilters,
            'ticketLabel' => $ticketLabel,
            'ticketLabelFilters' => $ticketLabelFilters,
            'ticketPriority' => $ticketPriority,
            'ticketPriorityFilters' => $ticketPriorityFilters,
            'ticketQueueCountSummary' => $ticketQueueCountSummary,
            'ticketQueueShownOf' => $ticketLaneCount,
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
                    $ticket->id => $this->ticketExternalIssueStateCueForTicket($ticket),
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
            $filters[] = $this->ticketFilterChip('ticket_status', __('tickets.chips.status', ['value' => $ticketStatusFilters[$ticketStatus]]), $ticketQuery);
        }

        if ($ticketFilter !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_filter', __('tickets.chips.assignee', ['value' => $ticketFilters[$ticketFilter]]), $ticketQuery);
        }

        if ($ticketSite) {
            $site = $sites->firstWhere('id', $ticketSite);

            if ($site) {
                $filters[] = $this->ticketFilterChip('ticket_site', __('tickets.chips.site', ['value' => $site->name]), $ticketQuery);
            }
        }

        if ($ticketPriority !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_priority', __('tickets.chips.priority', ['value' => $ticketPriorityFilters[$ticketPriority]]), $ticketQuery);
        }

        if ($ticketCategory !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_category', __('tickets.chips.category', ['value' => $ticketCategoryFilters[$ticketCategory]]), $ticketQuery);
        }

        if ($ticketLabel !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_label', __('tickets.chips.label', ['value' => $ticketLabelFilters[$ticketLabel]]), $ticketQuery);
        }

        if ($ticketAttention !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_attention', __('tickets.chips.next_step', ['value' => $ticketAttentionFilters[$ticketAttention]]), $ticketQuery);
        }

        if ($ticketExternalIssue !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_external', __('tickets.chips.external', ['value' => $ticketExternalIssueFilters[$ticketExternalIssue]]), $ticketQuery);
        }

        if ($ticketSearch !== '') {
            $filters[] = $this->ticketFilterChip('ticket_search', __('tickets.chips.search', ['value' => $ticketSearch]), $ticketQuery);
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
    /**
     * @param  array<string, int>  $counts
     */
    private function ticketQueueSummary(array $counts, array $ticketQuery, array $ticketAttentionFilters): array
    {

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

    /**
     * @param  array<string, string>  $ticketAttentionFilters
     * @return array{heading: string, detail: string}
     */
    private function ticketQueueCountSummary(
        int $laneCount,
        int $renderedCount,
        int $matchingCount,
        string $ticketStatusSummary,
        string $ticketAttention,
        array $ticketAttentionFilters,
    ): array {
        // The row cap makes these different facts. `$laneCount` is how many
        // tickets exist in this lane; `$renderedCount` is how many rows this
        // response actually contains. Headings describe the lane while
        // "Showing ..." sentences describe the page.
        $nextStepNarrowed = $ticketAttention !== 'all' && $laneCount !== $matchingCount;

        if (! $nextStepNarrowed) {
            return [
                'detail' => trans_choice('tickets.summary.filtered_detail', $renderedCount, [
                    'shown' => $this->ticketCountLabel($renderedCount),
                ]),
                'heading' => trans_choice('tickets.summary.heading.'.$ticketStatusSummary, $laneCount, [
                    'count' => $laneCount,
                ]),
            ];
        }

        return [
            // `trans_choice` on the SHOWN count, because that is the number
            // the sentence's own verb agrees with. The second clause takes its
            // verb from `:matching`, which carries one -- it used to pick a
            // verb separately from the count it agreed with, which is right in
            // English by luck and wrong in German.
            'detail' => trans_choice('tickets.summary.lane_narrowed_detail', $renderedCount, [
                'shown' => $this->ticketCountLabel($renderedCount),
                'lane' => $ticketAttentionFilters[$ticketAttention],
                'matching' => trans_choice('tickets.counts.matches', $matchingCount, ['count' => $matchingCount]),
            ]),
            'heading' => __('tickets.summary.lane_narrowed_heading', [
                'shown' => (string) $renderedCount,
                'matching' => trans_choice('tickets.counts.matching_tickets', $matchingCount, ['count' => $matchingCount]),
            ]),
        ];
    }

    /**
     * A filter map: query-string value => translated label, in the order given.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function translatedOptions(string $catalogue, array $keys): array
    {
        $options = [];

        foreach ($keys as $key) {
            $options[$key] = __($catalogue.'.'.$key);
        }

        return $options;
    }

    private function ticketCountLabel(int $count): string
    {
        return trans_choice('tickets.counts.tickets', $count, ['count' => $count]);
    }

    /**
     * @param  array<string, string|int>  $ticketQuery
     * @return array{heading: string, detail: string, actions: array<int, array{label: string, href: string}>}
     */
    private function ticketEmptyState(string $ticketEmptyMessage, bool $ticketHasActiveRefinement, array $ticketQuery, string $ticketAttention, string $ticketExternalIssue, string $ticketSearch, bool $accountHasNoTickets = false): array
    {
        $clearAllAction = [
            'href' => route('dashboard.tickets.index'),
            'label' => __('tickets.actions.clear_all'),
        ];

        if (! $ticketHasActiveRefinement) {
            if ($accountHasNoTickets) {
                // True first-run: there are no tickets at all. Point at what
                // creates them.
                return [
                    'actions' => [
                        [
                            'href' => route('dashboard.conversations.index'),
                            'label' => __('tickets.actions.open_conversations'),
                        ],
                    ],
                    'detail' => __('tickets.empty.first_run_detail'),
                    'heading' => $ticketEmptyMessage,
                ];
            }

            // Tickets exist — this view is empty because of its status alone
            // (no closed tickets yet, say).
            return [
                'actions' => [
                    [
                        'href' => route('dashboard.tickets.index', ['ticket_status' => 'all']),
                        'label' => __('tickets.actions.show_all'),
                    ],
                ],
                'detail' => __('tickets.empty.waiting_detail'),
                'heading' => $ticketEmptyMessage,
            ];
        }

        if ($ticketSearch !== '') {
            $query = $ticketQuery;
            unset($query['ticket_search']);

            return [
                'actions' => [
                    [
                        'href' => route('dashboard.tickets.index', $query),
                        'label' => __('tickets.actions.clear_search'),
                    ],
                    $clearAllAction,
                ],
                'detail' => __('tickets.empty.search_detail'),
                'heading' => __('tickets.empty.search_heading', ['term' => $ticketSearch]),
            ];
        }

        if ($ticketAttention !== 'all') {
            $query = $ticketQuery;
            unset($query['ticket_attention']);

            return [
                'actions' => [
                    [
                        'href' => route('dashboard.tickets.index', $query),
                        'label' => __('tickets.actions.clear_next_step'),
                    ],
                    $clearAllAction,
                ],
                'detail' => __('tickets.empty.next_step_detail'),
                'heading' => __('tickets.empty.next_step_heading', [
                    'phrase' => $this->ticketEmptyAttentionPhrase($ticketAttention),
                ]),
            ];
        }

        if ($ticketExternalIssue !== 'all') {
            $query = $ticketQuery;
            unset($query['ticket_external']);

            return [
                'actions' => [
                    [
                        'href' => route('dashboard.tickets.index', $query),
                        'label' => __('tickets.actions.clear_external'),
                    ],
                    $clearAllAction,
                ],
                'detail' => __('tickets.empty.external_detail'),
                'heading' => __('tickets.empty.external_heading'),
            ];
        }

        return [
            'actions' => [$clearAllAction],
            'detail' => __('tickets.empty.refine_detail'),
            'heading' => $ticketEmptyMessage,
        ];
    }

    private function ticketEmptyAttentionPhrase(string $ticketAttention): string
    {
        return match ($ticketAttention) {
            'escalated', 'needs_reply', 'needs_owner', 'needs_agent', 'waiting_on_customer', 'resolved' => __('tickets.attention_phrase.'.$ticketAttention),
            default => __('tickets.attention_phrase.default'),
        };
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

    /**
     * @return array{label: string, tone: string, detail: string, attempt: array{label: string, body: string, occurred_at: CarbonInterface|null}|null}
     */
    private function ticketExternalIssueStateCueForTicket(Ticket $ticket): array
    {
        return [
            ...$this->ticketExternalIssueStateCue(TicketExternalIssueState::forTicket($ticket)),
            // The eager loads from this controller's own query, handed over
            // explicitly. Without them the helper queried per ticket -- 12,499
            // queries on a desk with 12,500 tickets, and the reason this page
            // was slower than the conversation queue on a quarter of the rows.
            //
            // Passed from HERE rather than picked up inside the helper, because
            // this is the only place that knows the load above used the tracked
            // actions the helper wants. The ticket detail page loads the same
            // relation constrained to `ticket.note_added`, and a helper that
            // reused whatever was present would answer that page wrongly.
            'attempt' => TicketExternalIssueAttempt::latestCueForTicket(
                $ticket,
                $ticket->externalLinks,
                $ticket->auditEvents,
            ),
        ];
    }

    /**
     * @return array{label: string, tone: string, detail: string}
     */
    private function ticketExternalIssueStateCue(string $state): array
    {
        return match ($state) {
            TicketExternalIssueState::FAILED => [
                'label' => __('tickets.filters.external.failed'),
                'tone' => 'attention',
                'detail' => __('tickets.external_state.failed'),
            ],
            TicketExternalIssueState::PENDING => [
                'label' => __('tickets.filters.external.pending'),
                'tone' => 'manual',
                'detail' => __('tickets.external_state.pending'),
            ],
            TicketExternalIssueState::LINKED => [
                'label' => __('tickets.filters.external.linked'),
                'tone' => 'ready',
                'detail' => __('tickets.external_state.linked'),
            ],
            default => [
                'label' => __('tickets.filters.external.none'),
                'tone' => 'manual',
                'detail' => __('tickets.external_state.none'),
            ],
        };
    }
}
