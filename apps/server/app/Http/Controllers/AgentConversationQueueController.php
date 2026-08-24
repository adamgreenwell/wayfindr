<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Support\CobrowseConsentState;
use App\Support\Conversations\ConversationQueueQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AgentConversationQueueController extends Controller
{
    public function __invoke(Request $request, CobrowseConsentState $cobrowseConsentState): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->orderBy('name')
            ->get();

        return view('agent.conversations.index', [
            'account' => $account,
            'agent' => $agent,
            'sites' => $sites,
            ...$this->conversationQueueData($agent, $sites, $request, $cobrowseConsentState),
        ]);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{
     *     activeConversationFilters: array<int, array{label: string, href: string}>,
     *     cobrowseTransportByConversationId: Collection<int, array{label: string, message: string, last_report: string, pressure: string, guidance: string, tone: string}>,
     *     cobrowseAttentionConversationCount: int,
     *     conversationEmptyMessage: string,
     *     conversationEmptyState: array{actions: array<int, array{href: string, label: string}>, detail: string, heading: string},
     *     conversationFilter: string,
     *     conversationFilters: array<string, string>,
     *     conversationPresence: string,
     *     conversationPresenceFilters: array<string, string>,
     *     conversationQuery: array<string, string|int>,
     *     conversationQueueCountSummary: array{heading: string, detail: string},
     *     conversationQueueSummary: array<int, array{state: string, label: string, count: int, href: string, active: bool}>,
     *     conversationSearch: string,
     *     conversationSite: int|null,
     *     conversations: Collection<int, Conversation>,
     *     newActivityConversationCount: int
     * }
     */
    private function conversationQueueData(User $agent, Collection $sites, Request $request, CobrowseConsentState $cobrowseConsentState): array
    {
        // Keyed by the query-string value, which is the contract with the URL
        // and must not move when the label does.
        $conversationFilters = [];

        foreach (['all', 'new_activity', 'needs_reply', 'assigned_to_me', 'unassigned', 'cobrowse_attention', 'closed'] as $key) {
            $conversationFilters[$key] = __('conversations.filters.'.$key);
        }

        $conversationPresenceFilters = [];

        foreach (['all', 'active', 'recent', 'quiet', 'not_reported'] as $key) {
            $conversationPresenceFilters[$key] = __('conversations.presence.'.$key);
        }
        $conversationFilter = $request->query('conversation_filter', 'all');
        $conversationFilter = is_string($conversationFilter) && array_key_exists($conversationFilter, $conversationFilters)
            ? $conversationFilter
            : 'all';
        $conversationPresence = $request->query('conversation_presence', 'all');
        $conversationPresence = is_string($conversationPresence) && array_key_exists($conversationPresence, $conversationPresenceFilters)
            ? $conversationPresence
            : 'all';
        $conversationSearch = $request->query('conversation_search', '');
        $conversationSearch = is_string($conversationSearch)
            ? mb_substr(trim($conversationSearch), 0, 120)
            : '';
        $requestedConversationSite = $request->query('conversation_site');
        $conversationSite = is_string($requestedConversationSite) && ctype_digit($requestedConversationSite) && $sites->contains('id', (int) $requestedConversationSite)
            ? (int) $requestedConversationSite
            : null;
        $conversationStatus = $conversationFilter === 'closed' ? 'closed' : 'open';
        $conversationHasActiveRefinement = $conversationSearch !== '' || $conversationSite || $conversationPresence !== 'all';
        $conversationEmptyMessage = $conversationHasActiveRefinement
            ? __('conversations.empty.no_match_filters')
            : match ($conversationFilter) {
                'new_activity' => __('conversations.empty.no_new_activity'),
                'cobrowse_attention' => __('conversations.empty.no_cobrowse_attention'),
                'closed' => __('conversations.empty.no_closed'),
                default => __('conversations.empty.no_active'),
            };
        $conversationQuery = $this->conversationQueryParams($conversationFilter, $conversationSearch, $conversationSite, $conversationPresence);
        $newActivityConversationCount = Conversation::query()
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->withNewActivityFor($agent)
            ->count();
        $cobrowseAttentionConversationCount = Conversation::query()
            ->with('latestCobrowseSession')
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->withActiveCobrowseSession()
            ->get()
            ->map(fn (Conversation $conversation): array => $cobrowseConsentState->queueTransportForConversation($conversation))
            ->filter(fn (array $transport): bool => $cobrowseConsentState->transportNeedsAttention($transport))
            ->count();
        $conversationQueueSummary = $this->conversationQueueSummary(
            $agent,
            $conversationFilter,
            $conversationPresence,
            $conversationQuery,
            $conversationSite,
            $conversationSearch,
        );
        $matchingConversationCount = $this->matchingConversationCount(
            $agent,
            $conversationStatus,
            $conversationPresence,
            $conversationSite,
            $conversationSearch,
        );

        $conversations = Conversation::query()
            ->with([
                'assignedAgent',
                'latestCobrowseSession',
                'latestAgentMessage',
                'latestMessage',
                'readStates' => fn ($query) => $query->where('user_id', $agent->id),
                'site',
                'visitor',
            ])
            ->where('status', $conversationStatus)
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->when($conversationSite, fn ($query) => $query->where('site_id', $conversationSite))
            ->when($conversationPresence !== 'all', fn ($query) => $this->applyConversationPresenceFilter($query, $conversationPresence))
            ->when($conversationSearch !== '', fn ($query) => ConversationQueueQuery::applySearch($query, $conversationSearch))
            ->tap(fn ($query) => ConversationQueueQuery::applyLane($query, $conversationFilter, $agent))
            ->tap(fn ($query) => ConversationQueueQuery::ordered($query))
            ->get();

        $cobrowseTransportByConversationId = $conversations
            ->mapWithKeys(fn (Conversation $conversation): array => [
                $conversation->id => $cobrowseConsentState->queueTransportForConversation($conversation),
            ]);

        if ($conversationFilter === 'cobrowse_attention') {
            $conversations = $conversations
                ->filter(fn (Conversation $conversation): bool => $cobrowseConsentState->transportNeedsAttention(
                    $cobrowseTransportByConversationId->get($conversation->id, [])
                ))
                ->values();
        }
        $conversationQueueCountSummary = $this->conversationQueueCountSummary(
            $conversations,
            $matchingConversationCount,
            $conversationFilter,
            $conversationFilters,
            $conversationHasActiveRefinement,
            $newActivityConversationCount,
            $cobrowseAttentionConversationCount,
        );
        $conversationEmptyState = $this->conversationEmptyState(
            $conversationEmptyMessage,
            $conversationFilter,
            $conversationHasActiveRefinement,
            $conversationQuery,
            $conversationSearch,
            $matchingConversationCount,
        );

        return [
            'activeConversationFilters' => $this->activeConversationFilters($conversationQuery, $conversationSite, $sites, $conversationSearch, $conversationPresence, $conversationPresenceFilters),
            'cobrowseAttentionConversationCount' => $cobrowseAttentionConversationCount,
            'cobrowseTransportByConversationId' => $cobrowseTransportByConversationId,
            'conversationEmptyMessage' => $conversationEmptyMessage,
            'conversationEmptyState' => $conversationEmptyState,
            'conversationFilter' => $conversationFilter,
            'conversationFilters' => $conversationFilters,
            'conversationPresence' => $conversationPresence,
            'conversationPresenceFilters' => $conversationPresenceFilters,
            'conversationQuery' => $conversationQuery,
            'conversationQueueCountSummary' => $conversationQueueCountSummary,
            'conversationQueueSummary' => $conversationQueueSummary,
            'conversationSearch' => $conversationSearch,
            'conversationSite' => $conversationSite,
            'conversations' => $conversations,
            'newActivityConversationCount' => $newActivityConversationCount,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function conversationQueryParams(string $conversationFilter, string $conversationSearch, ?int $conversationSite, string $conversationPresence): array
    {
        $params = [];

        if ($conversationFilter !== 'all') {
            $params['conversation_filter'] = $conversationFilter;
        }

        if ($conversationSearch !== '') {
            $params['conversation_search'] = $conversationSearch;
        }

        if ($conversationSite) {
            $params['conversation_site'] = $conversationSite;
        }

        if ($conversationPresence !== 'all') {
            $params['conversation_presence'] = $conversationPresence;
        }

        return $params;
    }

    /**
     * @param  array<string, string|int>  $conversationQuery
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{label: string, href: string}>
     */
    private function activeConversationFilters(array $conversationQuery, ?int $conversationSite, Collection $sites, string $conversationSearch, string $conversationPresence, array $conversationPresenceFilters): array
    {
        $filters = [];

        if ($conversationSite) {
            $site = $sites->firstWhere('id', $conversationSite);

            if ($site) {
                $filters[] = $this->conversationFilterChip('conversation_site', __('conversations.chips.site', ['name' => $site->name]), $conversationQuery);
            }
        }

        if ($conversationSearch !== '') {
            $filters[] = $this->conversationFilterChip('conversation_search', __('conversations.chips.search', ['term' => $conversationSearch]), $conversationQuery);
        }

        if ($conversationPresence !== 'all' && isset($conversationPresenceFilters[$conversationPresence])) {
            $filters[] = $this->conversationFilterChip('conversation_presence', __('conversations.chips.presence', ['label' => $conversationPresenceFilters[$conversationPresence]]), $conversationQuery);
        }

        return $filters;
    }

    /**
     * @param  array<string, string|int>  $conversationQuery
     * @return array{label: string, href: string}
     */
    private function conversationFilterChip(string $queryKey, string $label, array $conversationQuery): array
    {
        unset($conversationQuery[$queryKey]);

        return [
            'label' => $label,
            'href' => route('dashboard.conversations.index', $conversationQuery),
        ];
    }

    /**
     * @param  array<string, string|int>  $conversationQuery
     * @return array<int, array{state: string, label: string, count: int, href: string, active: bool}>
     */
    private function conversationQueueSummary(
        User $agent,
        string $conversationFilter,
        string $conversationPresence,
        array $conversationQuery,
        ?int $conversationSite,
        string $conversationSearch,
    ): array {
        if ($conversationFilter === 'closed') {
            return [];
        }

        $laneQuery = function () use ($agent, $conversationPresence, $conversationSearch, $conversationSite): Builder {
            $query = $this->visibleOpenConversationQuery($agent, $conversationSite, $conversationSearch);

            if ($conversationPresence !== 'all') {
                $this->applyConversationPresenceFilter($query, $conversationPresence);
            }

            return $query;
        };
        $presenceQuery = fn (): Builder => $this->visibleOpenConversationQuery($agent, $conversationSite, $conversationSearch);

        $needsReplyQuery = $laneQuery();
        $needsReplyQuery->where(function (Builder $query): void {
            $query->whereDoesntHave('messages')
                ->orWhereHas('latestMessage', fn (Builder $query) => $query->where('sender_type', '!=', User::class));
        });

        $activeVisitorsQuery = $presenceQuery();
        $this->applyConversationPresenceFilter($activeVisitorsQuery, 'active');

        $recentVisitorsQuery = $presenceQuery();
        $this->applyConversationPresenceFilter($recentVisitorsQuery, 'recent');

        return [
            $this->conversationQueueSummaryChip(
                'new_activity',
                __('conversations.lanes.new_activity'),
                $laneQuery()->withNewActivityFor($agent)->count(),
                ['conversation_filter' => 'new_activity'],
                $conversationQuery,
                $conversationFilter === 'new_activity',
            ),
            $this->conversationQueueSummaryChip(
                'needs_reply',
                __('conversations.lanes.needs_reply'),
                $needsReplyQuery->count(),
                ['conversation_filter' => 'needs_reply'],
                $conversationQuery,
                $conversationFilter === 'needs_reply',
            ),
            $this->conversationQueueSummaryChip(
                'assigned_to_me',
                __('conversations.lanes.assigned_to_me'),
                $laneQuery()->where('assigned_agent_id', $agent->id)->count(),
                ['conversation_filter' => 'assigned_to_me'],
                $conversationQuery,
                $conversationFilter === 'assigned_to_me',
            ),
            $this->conversationQueueSummaryChip(
                'unassigned',
                __('conversations.lanes.unassigned'),
                $laneQuery()->whereNull('assigned_agent_id')->count(),
                ['conversation_filter' => 'unassigned'],
                $conversationQuery,
                $conversationFilter === 'unassigned',
            ),
            $this->conversationQueueSummaryChip(
                'active',
                __('conversations.lanes.active'),
                $activeVisitorsQuery->count(),
                ['conversation_filter' => null, 'conversation_presence' => 'active'],
                $conversationQuery,
                $conversationPresence === 'active',
            ),
            $this->conversationQueueSummaryChip(
                'recent',
                __('conversations.lanes.recent'),
                $recentVisitorsQuery->count(),
                ['conversation_filter' => null, 'conversation_presence' => 'recent'],
                $conversationQuery,
                $conversationPresence === 'recent',
            ),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $overrides
     * @param  array<string, string|int>  $conversationQuery
     * @return array{state: string, label: string, count: int, href: string, active: bool}
     */
    private function conversationQueueSummaryChip(string $state, string $label, int $count, array $overrides, array $conversationQuery, bool $active): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($conversationQuery[$key]);

                continue;
            }

            $conversationQuery[$key] = $value;
        }

        return [
            'active' => $active,
            'count' => $count,
            'href' => route('dashboard.conversations.index', $conversationQuery),
            'label' => $label,
            'state' => $state,
        ];
    }

    private function matchingConversationCount(User $agent, string $conversationStatus, string $conversationPresence, ?int $conversationSite, string $conversationSearch): int
    {
        $query = Conversation::query()
            ->where('status', $conversationStatus)
            ->whereHas('site', fn (Builder $query) => $query->visibleToAgent($agent))
            ->when($conversationSite, fn (Builder $query) => $query->where('site_id', $conversationSite))
            ->when($conversationSearch !== '', function (Builder $query) use ($conversationSearch): void {
                $searchPattern = $this->conversationSearchPattern($conversationSearch);

                $query->where(function (Builder $query) use ($searchPattern): void {
                    $this->whereLiteralLike($query, 'subject', $searchPattern);
                    $this->whereLiteralLike($query, 'support_code', $searchPattern, 'or');
                    $query->orWhereHas('visitor', function (Builder $query) use ($searchPattern): void {
                        $this->whereLiteralLike($query, 'anonymous_id', $searchPattern);
                        $this->whereLiteralLike($query, 'external_id', $searchPattern, 'or');
                        $this->whereLiteralLike($query, 'name', $searchPattern, 'or');
                        $this->whereLiteralLike($query, 'email', $searchPattern, 'or');
                    });
                });
            });

        if ($conversationPresence !== 'all') {
            $this->applyConversationPresenceFilter($query, $conversationPresence);
        }

        return $query->count();
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @param  array<string, string>  $conversationFilters
     * @return array{heading: string, detail: string}
     */
    private function conversationQueueCountSummary(Collection $conversations, int $matchingConversationCount, string $conversationFilter, array $conversationFilters, bool $conversationHasActiveRefinement, int $newActivityConversationCount, int $cobrowseAttentionConversationCount): array
    {
        $shownCount = $conversations->count();
        $supportLaneNarrowed = ! in_array($conversationFilter, ['all', 'closed'], true)
            && $shownCount !== $matchingConversationCount;

        if ($supportLaneNarrowed) {
            return [
                'detail' => __('conversations.summary.lane_narrowed_detail', [
                    'shown' => $this->conversationCountLabel($shownCount),
                    'lane' => $conversationFilters[$conversationFilter],
                    'matching' => $this->conversationCountLabel($matchingConversationCount),
                ]),
                'heading' => __('conversations.summary.lane_narrowed_heading', [
                    // The attention lane counts in its own words, because
                    // "3 need attention shown of 9" says what the lane is for
                    // in a way that a bare "3" does not.
                    'shown' => $conversationFilter === 'new_activity'
                        ? trans_choice('conversations.counts.needs_attention', $shownCount, ['count' => $shownCount])
                        : (string) $shownCount,
                    'matching' => (string) $matchingConversationCount,
                ]),
            ];
        }

        $filteredDetail = __('conversations.summary.filtered_detail', [
            'shown' => $this->conversationCountLabel($shownCount),
        ]);

        if ($conversationFilter === 'closed') {
            return [
                'detail' => $filteredDetail,
                'heading' => trans_choice('conversations.counts.closed', $shownCount, ['count' => $shownCount]),
            ];
        }

        if ($conversationHasActiveRefinement) {
            return [
                'detail' => $filteredDetail,
                'heading' => trans_choice('conversations.counts.open_matching', $shownCount, ['count' => $shownCount]),
            ];
        }

        return [
            'detail' => $filteredDetail,
            'heading' => __('conversations.summary.open_heading', [
                'open' => (string) $shownCount,
                'attention' => trans_choice('conversations.counts.needs_attention', $newActivityConversationCount, ['count' => $newActivityConversationCount]),
                'cobrowse' => trans_choice('conversations.counts.cobrowse_attention', $cobrowseAttentionConversationCount, ['count' => $cobrowseAttentionConversationCount]),
            ]),
        ];
    }

    /**
     * @param  array<string, string|int>  $conversationQuery
     * @return array{actions: array<int, array{href: string, label: string}>, detail: string, heading: string}
     */
    private function conversationEmptyState(
        string $conversationEmptyMessage,
        string $conversationFilter,
        bool $conversationHasActiveRefinement,
        array $conversationQuery,
        string $conversationSearch,
        int $matchingConversationCount,
    ): array {
        $state = [
            'actions' => [],
            'detail' => match ($conversationFilter) {
                'closed' => __('conversations.empty.closed_detail'),
                default => __('conversations.empty.default_detail'),
            },
            'heading' => $conversationEmptyMessage,
        ];

        if ($conversationSearch !== '') {
            $state['heading'] = __('conversations.empty.no_search_match', ['term' => $conversationSearch]);
            $state['detail'] = __('conversations.empty.search_covers');
            $state['actions'][] = $this->conversationEmptyAction('conversation_search', __('conversations.actions.clear_search'), $conversationQuery);
            $state['actions'][] = [
                'href' => route('dashboard.conversations.index'),
                'label' => __('conversations.actions.clear_all'),
            ];

            return $state;
        }

        if ($conversationHasActiveRefinement) {
            $clearRefinementsQuery = $conversationQuery;
            unset($clearRefinementsQuery['conversation_site'], $clearRefinementsQuery['conversation_presence']);

            $state['detail'] = __('conversations.empty.refine_detail');
            $state['actions'][] = [
                'href' => route('dashboard.conversations.index', $clearRefinementsQuery),
                'label' => __('conversations.actions.clear_filters'),
            ];
            $state['actions'][] = [
                'href' => route('dashboard.conversations.index'),
                'label' => __('conversations.actions.clear_all'),
            ];

            return $state;
        }

        $supportLaneIsEmpty = ! in_array($conversationFilter, ['all', 'closed'], true)
            && $matchingConversationCount > 0;

        if ($supportLaneIsEmpty) {
            $state['heading'] = match ($conversationFilter) {
                'assigned_to_me' => __('conversations.empty.lane_assigned_to_me'),
                'cobrowse_attention' => __('conversations.empty.lane_cobrowse_attention'),
                'needs_reply' => __('conversations.empty.lane_needs_reply'),
                'new_activity' => __('conversations.empty.lane_new_activity'),
                'unassigned' => __('conversations.empty.lane_unassigned'),
                default => $conversationEmptyMessage,
            };
            $state['detail'] = __('conversations.empty.lane_detail', [
                'matching' => $this->conversationCountMatchLabel($matchingConversationCount),
            ]);
            $state['actions'][] = $this->conversationEmptyAction('conversation_filter', __('conversations.actions.clear_support_lane'), $conversationQuery);
            $state['actions'][] = [
                'href' => route('dashboard.conversations.index'),
                'label' => __('conversations.actions.clear_all'),
            ];

            return $state;
        }

        if ($conversationFilter === 'closed') {
            $state['actions'][] = [
                'href' => route('dashboard.conversations.index'),
                'label' => __('conversations.actions.show_active'),
            ];
        } else {
            // True first-run: nothing filtered this queue empty — there are no
            // conversations yet. Point at what creates them.
            $state['detail'] = __('conversations.empty.first_run_detail');
            $state['actions'][] = [
                'href' => route('dashboard.sites.index'),
                'label' => __('conversations.actions.check_installs'),
            ];
        }

        return $state;
    }

    private function conversationCountLabel(int $count): string
    {
        return trans_choice('conversations.counts.conversations', $count, ['count' => $count]);
    }

    private function conversationCountMatchLabel(int $count): string
    {
        // Not the count label plus a verb. English inflects the verb for number
        // and German does not, so the whole phrase is one plural form rather
        // than a noun glued to a separately chosen verb.
        return trans_choice('conversations.counts.matches', $count, ['count' => $count]);
    }

    /**
     * @param  array<string, string|int>  $conversationQuery
     * @return array{href: string, label: string}
     */
    private function conversationEmptyAction(string $queryKey, string $label, array $conversationQuery): array
    {
        unset($conversationQuery[$queryKey]);

        return [
            'href' => route('dashboard.conversations.index', $conversationQuery),
            'label' => $label,
        ];
    }

    /**
     * @return Builder<Conversation>
     */
    private function visibleOpenConversationQuery(User $agent, ?int $conversationSite, string $conversationSearch): Builder
    {
        return ConversationQueueQuery::visibleTo($agent, 'open', $conversationSite, $conversationSearch);
    }

    private function conversationSearchPattern(string $conversationSearch): string
    {
        return ConversationQueueQuery::searchPattern($conversationSearch);
    }

    private function whereLiteralLike($query, string $column, string $pattern, string $boolean = 'and'): void
    {
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        $query->whereRaw(
            'LOWER('.$wrappedColumn.') LIKE LOWER(?) ESCAPE ?',
            [$pattern, '\\'],
            $boolean,
        );
    }

    private function applyConversationPresenceFilter($query, string $conversationPresence): void
    {
        ConversationQueueQuery::applyPresence($query, $conversationPresence);
    }
}
