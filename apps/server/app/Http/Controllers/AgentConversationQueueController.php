<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Support\CobrowseConsentState;
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
        $conversationFilters = [
            'all' => 'All open',
            'new_activity' => 'New activity',
            'needs_reply' => 'Needs reply',
            'assigned_to_me' => 'Assigned to me',
            'unassigned' => 'Unassigned',
            'cobrowse_attention' => 'Cobrowse attention',
            'closed' => 'Closed',
        ];
        $conversationPresenceFilters = [
            'all' => 'Any presence',
            'active' => 'Active recently',
            'recent' => 'Recently active',
            'quiet' => 'Quiet',
            'not_reported' => 'Not reported',
        ];
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
            ? 'No conversations match those filters.'
            : match ($conversationFilter) {
                'new_activity' => 'No conversations need attention.',
                'cobrowse_attention' => 'No active cobrowse sessions need attention.',
                'closed' => 'No closed conversations yet.',
                default => 'No active conversations yet.',
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
            ->when($conversationSearch !== '', function ($query) use ($conversationSearch): void {
                $searchPattern = $this->conversationSearchPattern($conversationSearch);

                $query->where(function ($query) use ($searchPattern): void {
                    $this->whereLiteralLike($query, 'subject', $searchPattern);
                    $this->whereLiteralLike($query, 'support_code', $searchPattern, 'or');
                    $query->orWhereHas('visitor', function ($query) use ($searchPattern): void {
                        $this->whereLiteralLike($query, 'anonymous_id', $searchPattern);
                        $this->whereLiteralLike($query, 'external_id', $searchPattern, 'or');
                        $this->whereLiteralLike($query, 'name', $searchPattern, 'or');
                        $this->whereLiteralLike($query, 'email', $searchPattern, 'or');
                    });
                });
            })
            ->when($conversationFilter === 'new_activity', fn ($query) => $query->withNewActivityFor($agent))
            ->when($conversationFilter === 'needs_reply', function ($query): void {
                $query->where(function ($query): void {
                    $query->whereDoesntHave('messages')
                        ->orWhereHas('latestMessage', fn ($query) => $query->where('sender_type', '!=', User::class));
                });
            })
            ->when($conversationFilter === 'assigned_to_me', fn ($query) => $query->where('assigned_agent_id', $agent->id))
            ->when($conversationFilter === 'unassigned', fn ($query) => $query->whereNull('assigned_agent_id'))
            ->when($conversationFilter === 'cobrowse_attention', fn ($query) => $query->withActiveCobrowseSession())
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
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

        return [
            'activeConversationFilters' => $this->activeConversationFilters($conversationQuery, $conversationSite, $sites, $conversationSearch, $conversationPresence, $conversationPresenceFilters),
            'cobrowseAttentionConversationCount' => $cobrowseAttentionConversationCount,
            'cobrowseTransportByConversationId' => $cobrowseTransportByConversationId,
            'conversationEmptyMessage' => $conversationEmptyMessage,
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
                $filters[] = $this->conversationFilterChip('conversation_site', 'Site: '.$site->name, $conversationQuery);
            }
        }

        if ($conversationSearch !== '') {
            $filters[] = $this->conversationFilterChip('conversation_search', 'Search: '.$conversationSearch, $conversationQuery);
        }

        if ($conversationPresence !== 'all' && isset($conversationPresenceFilters[$conversationPresence])) {
            $filters[] = $this->conversationFilterChip('conversation_presence', 'Presence: '.$conversationPresenceFilters[$conversationPresence], $conversationQuery);
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
                'Needs attention',
                $laneQuery()->withNewActivityFor($agent)->count(),
                ['conversation_filter' => 'new_activity'],
                $conversationQuery,
                $conversationFilter === 'new_activity',
            ),
            $this->conversationQueueSummaryChip(
                'needs_reply',
                'Needs reply',
                $needsReplyQuery->count(),
                ['conversation_filter' => 'needs_reply'],
                $conversationQuery,
                $conversationFilter === 'needs_reply',
            ),
            $this->conversationQueueSummaryChip(
                'assigned_to_me',
                'Assigned to me',
                $laneQuery()->where('assigned_agent_id', $agent->id)->count(),
                ['conversation_filter' => 'assigned_to_me'],
                $conversationQuery,
                $conversationFilter === 'assigned_to_me',
            ),
            $this->conversationQueueSummaryChip(
                'unassigned',
                'Unassigned',
                $laneQuery()->whereNull('assigned_agent_id')->count(),
                ['conversation_filter' => 'unassigned'],
                $conversationQuery,
                $conversationFilter === 'unassigned',
            ),
            $this->conversationQueueSummaryChip(
                'active',
                'Active visitors',
                $activeVisitorsQuery->count(),
                ['conversation_filter' => null, 'conversation_presence' => 'active'],
                $conversationQuery,
                $conversationPresence === 'active',
            ),
            $this->conversationQueueSummaryChip(
                'recent',
                'Recently active',
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
            $heading = $shownCount.' shown of '.$matchingConversationCount.' matching conversations';

            if ($conversationFilter === 'new_activity') {
                $heading = ($shownCount === 1 ? '1 needs attention' : $shownCount.' need attention')
                    .' shown of '.$matchingConversationCount.' matching conversations';
            }

            return [
                'detail' => 'Showing '.$this->conversationCountLabel($shownCount).' after the '.$conversationFilters[$conversationFilter].' support-lane filter. '.$this->conversationCountLabel($matchingConversationCount).' match the other queue filters.',
                'heading' => $heading,
            ];
        }

        if ($conversationFilter === 'closed') {
            return [
                'detail' => 'Showing '.$this->conversationCountLabel($shownCount).' matching the current queue filters.',
                'heading' => $shownCount === 1 ? '1 closed' : $shownCount.' closed',
            ];
        }

        if ($conversationHasActiveRefinement) {
            return [
                'detail' => 'Showing '.$this->conversationCountLabel($shownCount).' matching the current queue filters.',
                'heading' => $shownCount === 1 ? '1 open matching' : $shownCount.' open matching',
            ];
        }

        return [
            'detail' => 'Showing '.$this->conversationCountLabel($shownCount).' matching the current queue filters.',
            'heading' => $shownCount.' open · '
                .($newActivityConversationCount === 1 ? '1 needs attention' : $newActivityConversationCount.' need attention')
                .' · '
                .($cobrowseAttentionConversationCount === 1 ? '1 cobrowse session needs attention' : $cobrowseAttentionConversationCount.' cobrowse sessions need attention'),
        ];
    }

    private function conversationCountLabel(int $count): string
    {
        return $count.' '.($count === 1 ? 'conversation' : 'conversations');
    }

    /**
     * @return Builder<Conversation>
     */
    private function visibleOpenConversationQuery(User $agent, ?int $conversationSite, string $conversationSearch): Builder
    {
        return Conversation::query()
            ->where('status', 'open')
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
    }

    private function conversationSearchPattern(string $conversationSearch): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $conversationSearch).'%';
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
        $activeCutoff = now()->subMinutes(2);
        $recentCutoff = now()->subMinutes(15);

        $query->whereHas('visitor', function ($query) use ($activeCutoff, $conversationPresence, $recentCutoff): void {
            match ($conversationPresence) {
                'active' => $query->where('last_seen_at', '>=', $activeCutoff),
                'recent' => $query->where('last_seen_at', '<', $activeCutoff)
                    ->where('last_seen_at', '>=', $recentCutoff),
                'quiet' => $query->where('last_seen_at', '<', $recentCutoff),
                'not_reported' => $query->whereNull('last_seen_at'),
                default => null,
            };
        });
    }
}
