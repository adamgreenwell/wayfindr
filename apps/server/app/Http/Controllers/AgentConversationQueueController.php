<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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

        return view('agent.conversations.index', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            ...$this->conversationQueueData($agent, $request, $cobrowseConsentState),
        ]);
    }

    /**
     * @return array{
     *     cobrowseTransportByConversationId: Collection<int, array{label: string, message: string, last_report: string, tone: string}>,
     *     cobrowseAttentionConversationCount: int,
     *     conversationEmptyMessage: string,
     *     conversationFilter: string,
     *     conversationFilters: array<string, string>,
     *     conversationQuery: array<string, string>,
     *     conversationSearch: string,
     *     conversations: Collection<int, Conversation>,
     *     newActivityConversationCount: int
     * }
     */
    private function conversationQueueData(User $agent, Request $request, CobrowseConsentState $cobrowseConsentState): array
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
        $conversationFilter = $request->query('conversation_filter', 'all');
        $conversationFilter = is_string($conversationFilter) && array_key_exists($conversationFilter, $conversationFilters)
            ? $conversationFilter
            : 'all';
        $conversationSearch = $request->query('conversation_search', '');
        $conversationSearch = is_string($conversationSearch)
            ? mb_substr(trim($conversationSearch), 0, 120)
            : '';
        $conversationStatus = $conversationFilter === 'closed' ? 'closed' : 'open';
        $conversationEmptyMessage = $conversationSearch !== ''
            ? 'No conversations match that search.'
            : match ($conversationFilter) {
                'new_activity' => 'No conversations need attention.',
                'cobrowse_attention' => 'No active cobrowse sessions need attention.',
                'closed' => 'No closed conversations yet.',
                default => 'No active conversations yet.',
            };
        $conversationQuery = $this->conversationQueryParams($conversationFilter, $conversationSearch);
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
            'cobrowseAttentionConversationCount' => $cobrowseAttentionConversationCount,
            'cobrowseTransportByConversationId' => $cobrowseTransportByConversationId,
            'conversationEmptyMessage' => $conversationEmptyMessage,
            'conversationFilter' => $conversationFilter,
            'conversationFilters' => $conversationFilters,
            'conversationQuery' => $conversationQuery,
            'conversationSearch' => $conversationSearch,
            'conversations' => $conversations,
            'newActivityConversationCount' => $newActivityConversationCount,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function conversationQueryParams(string $conversationFilter, string $conversationSearch): array
    {
        $params = [];

        if ($conversationFilter !== 'all') {
            $params['conversation_filter'] = $conversationFilter;
        }

        if ($conversationSearch !== '') {
            $params['conversation_search'] = $conversationSearch;
        }

        return $params;
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
}
