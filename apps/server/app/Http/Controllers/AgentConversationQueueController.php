<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Support\CobrowseConsentState;
use Illuminate\Contracts\View\View;
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
