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
     *     conversationEmptyMessage: string,
     *     conversationFilter: string,
     *     conversationFilters: array<string, string>,
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
        ];
        $conversationFilter = $request->query('conversation_filter', 'all');
        $conversationFilter = is_string($conversationFilter) && array_key_exists($conversationFilter, $conversationFilters)
            ? $conversationFilter
            : 'all';
        $conversationEmptyMessage = match ($conversationFilter) {
            'new_activity' => 'No conversations need attention.',
            'cobrowse_attention' => 'No active cobrowse sessions need attention.',
            default => 'No active conversations yet.',
        };
        $newActivityConversationCount = Conversation::query()
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->withNewActivityFor($agent)
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
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->when($conversationFilter === 'new_activity', fn ($query) => $query->withNewActivityFor($agent))
            ->when($conversationFilter === 'needs_reply', function ($query): void {
                $query->where(function ($query): void {
                    $query->whereDoesntHave('messages')
                        ->orWhereHas('latestMessage', fn ($query) => $query->where('sender_type', '!=', User::class));
                });
            })
            ->when($conversationFilter === 'assigned_to_me', fn ($query) => $query->where('assigned_agent_id', $agent->id))
            ->when($conversationFilter === 'unassigned', fn ($query) => $query->whereNull('assigned_agent_id'))
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $cobrowseTransportByConversationId = $conversations
            ->mapWithKeys(fn (Conversation $conversation): array => [
                $conversation->id => $cobrowseConsentState->queueTransportForConversation($conversation),
            ]);

        if ($conversationFilter === 'cobrowse_attention') {
            $conversations = $conversations
                ->filter(fn (Conversation $conversation): bool => ($cobrowseTransportByConversationId->get($conversation->id)['tone'] ?? null) === 'attention')
                ->values();
        }

        return [
            'cobrowseTransportByConversationId' => $cobrowseTransportByConversationId,
            'conversationEmptyMessage' => $conversationEmptyMessage,
            'conversationFilter' => $conversationFilter,
            'conversationFilters' => $conversationFilters,
            'conversations' => $conversations,
            'newActivityConversationCount' => $newActivityConversationCount,
        ];
    }
}
