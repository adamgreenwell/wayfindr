<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CobrowseConsentState;
use App\Support\RealtimeHealth;
use App\Support\VisitorSupportReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AgentDashboardController extends Controller
{
    public function __invoke(Request $request, RealtimeHealth $realtimeHealth, VisitorSupportReadiness $visitorSupportReadiness, CobrowseConsentState $cobrowseConsentState): View|RedirectResponse
    {
        if ($redirect = $this->legacyQueueRedirect($request)) {
            return $redirect;
        }

        // The home leads with the work: queues and next steps. The at-a-glance
        // panels (team, sites, alerts, realtime, admin shortcuts) moved to
        // their own surfaces — Account is the management hub (#555).
        [$agent, $account, $sites] = $this->dashboardContext($request);
        $realtimeHealthSummary = $realtimeHealth->summary();

        return view('agent.dashboard', [
            'account' => $account,
            'agent' => $agent,
            'conversationNextSteps' => $this->conversationNextSteps($agent),
            'supportQueues' => $this->supportQueues($agent, $cobrowseConsentState),
            'ticketNextSteps' => $this->ticketNextSteps($agent),
            'visitorSupportReadiness' => $visitorSupportReadiness->summary(
                sites: $sites,
                realtimeHealth: $realtimeHealthSummary,
                canViewReadiness: $agent->isAdmin(),
                canManagePrivacy: $agent->isAdmin(),
            ),
        ]);
    }

    /**
     * @return array{0: User, 1: Account, 2: Collection<int, Site>}
     */
    private function dashboardContext(Request $request): array
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('latestVisitor')
            ->orderBy('name')
            ->get();

        return [$agent, $account, $sites];
    }

    /**
     * @return array{
     *     open_conversations_count: int,
     *     new_activity_conversations_count: int,
     *     cobrowse_attention_conversations_count: int,
     *     open_tickets_count: int,
     *     unassigned_tickets_count: int
     * }
     */
    private function supportQueues(User $agent, CobrowseConsentState $cobrowseConsentState): array
    {
        $visibleOpenConversations = Conversation::query()
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent));

        $visibleOpenTickets = Ticket::query()
            ->where('status', 'open')
            ->where('account_id', $agent->account_id)
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent));

        return [
            'open_conversations_count' => (clone $visibleOpenConversations)->count(),
            'new_activity_conversations_count' => (clone $visibleOpenConversations)->withNewActivityFor($agent)->count(),
            'cobrowse_attention_conversations_count' => (clone $visibleOpenConversations)
                ->withActiveCobrowseSession()
                ->with('latestCobrowseSession')
                ->get()
                ->map(fn (Conversation $conversation): array => $cobrowseConsentState->queueTransportForConversation($conversation))
                ->filter(fn (array $transport): bool => $cobrowseConsentState->transportNeedsAttention($transport))
                ->count(),
            'open_tickets_count' => (clone $visibleOpenTickets)->count(),
            'unassigned_tickets_count' => (clone $visibleOpenTickets)->whereNull('assignee_id')->count(),
        ];
    }

    /**
     * @return array{
     *     open_count: int,
     *     queue_href: string,
     *     items: array<int, array{
     *         action: string,
     *         detail: string,
     *         href: string,
     *         label: string,
     *         state: string,
     *         title: string,
     *         count: int
     *     }>
     * }
     */
    private function conversationNextSteps(User $agent): array
    {
        $visibleOpenConversations = Conversation::query()
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent));

        $definitions = $this->conversationNextStepDefinitions();
        $counts = [
            'new_activity' => (clone $visibleOpenConversations)->withNewActivityFor($agent)->count(),
            'needs_reply' => (clone $visibleOpenConversations)
                ->where(function ($query): void {
                    $query->whereDoesntHave('messages')
                        ->orWhereHas('latestMessage', fn ($query) => $query->where('sender_type', '!=', User::class));
                })
                ->count(),
            'assigned_to_me' => (clone $visibleOpenConversations)->where('assigned_agent_id', $agent->id)->count(),
            'unassigned' => (clone $visibleOpenConversations)->whereNull('assigned_agent_id')->count(),
        ];

        return [
            'items' => collect(array_keys($definitions))
                ->map(function (string $state) use ($counts, $definitions): array {
                    return [
                        ...$definitions[$state],
                        'count' => (int) ($counts[$state] ?? 0),
                        'href' => route('dashboard.conversations.index', ['conversation_filter' => $state]),
                        'state' => $state,
                    ];
                })
                ->filter(fn (array $item): bool => $item['count'] > 0)
                ->values()
                ->all(),
            'open_count' => (clone $visibleOpenConversations)->count(),
            'queue_href' => route('dashboard.conversations.index'),
        ];
    }

    /**
     * @return array<string, array{action: string, detail: string, label: string, title: string}>
     */
    private function conversationNextStepDefinitions(): array
    {
        return [
            'new_activity' => [
                'action' => 'Open attention queue',
                'detail' => 'Unread visitor activity is waiting for review.',
                'label' => 'new activity',
                'title' => 'Needs attention',
            ],
            'needs_reply' => [
                'action' => 'Open reply queue',
                'detail' => 'Visitor-started or visitor-latest conversations need a response.',
                'label' => 'need reply',
                'title' => 'Reply to visitor',
            ],
            'assigned_to_me' => [
                'action' => 'Open my conversations',
                'detail' => 'Conversations assigned to you stay close without filling the dashboard.',
                'label' => 'assigned conversation',
                'title' => 'Assigned to you',
            ],
            'unassigned' => [
                'action' => 'Open unassigned',
                'detail' => 'Unassigned conversations need someone to take ownership.',
                'label' => 'unassigned conversation',
                'title' => 'Claim unassigned',
            ],
        ];
    }

    /**
     * @return array{
     *     open_count: int,
     *     queue_href: string,
     *     items: array<int, array{
     *         action: string,
     *         detail: string,
     *         href: string,
     *         label: string,
     *         state: string,
     *         title: string,
     *         count: int
     *     }>
     * }
     */
    private function ticketNextSteps(User $agent): array
    {
        $visibleOpenTickets = Ticket::query()
            ->where('status', 'open')
            ->where('account_id', $agent->account_id)
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent));

        $openTicketCount = (clone $visibleOpenTickets)->count();
        $tickets = (clone $visibleOpenTickets)
            ->with(['conversation.latestMessage', 'latestEscalationEvent', 'site'])
            ->latest('updated_at')
            ->latest('id')
            ->get();
        $counts = $tickets->countBy(fn (Ticket $ticket): string => $this->ticketNextStepState($ticket));
        $definitions = $this->ticketNextStepDefinitions();

        return [
            'items' => collect(array_keys($definitions))
                ->map(function (string $state) use ($counts, $definitions): array {
                    return [
                        ...$definitions[$state],
                        'count' => (int) ($counts[$state] ?? 0),
                        'href' => route('dashboard.tickets.index', ['ticket_attention' => $state]),
                        'state' => $state,
                    ];
                })
                ->filter(fn (array $item): bool => $item['count'] > 0)
                ->take(4)
                ->values()
                ->all(),
            'open_count' => $openTicketCount,
            'queue_href' => route('dashboard.tickets.index'),
        ];
    }

    private function ticketNextStepState(Ticket $ticket): string
    {
        return $ticket->hasRecentEscalation()
            ? 'escalated'
            : $ticket->attentionState();
    }

    /**
     * @return array<string, array{action: string, detail: string, label: string, title: string}>
     */
    private function ticketNextStepDefinitions(): array
    {
        return [
            'escalated' => [
                'action' => 'Review escalations',
                'detail' => 'Recently escalated tickets should get eyes before routine queue work.',
                'label' => 'recent escalation',
                'title' => 'Recently escalated',
            ],
            'needs_reply' => [
                'action' => 'Open replies',
                'detail' => 'Visitors replied last and are waiting for an agent response.',
                'label' => 'needs reply',
                'title' => 'Reply to visitor',
            ],
            'needs_owner' => [
                'action' => 'Assign owners',
                'detail' => 'Unassigned tickets need an owner before the next step gets lost.',
                'label' => 'needs owner',
                'title' => 'Assign an owner',
            ],
            'needs_agent' => [
                'action' => 'Review updates',
                'detail' => 'Assigned tickets are ready for an agent update, note, or status change.',
                'label' => 'needs agent',
                'title' => 'Add the next update',
            ],
            'waiting_on_customer' => [
                'action' => 'Check waiting tickets',
                'detail' => 'Agent replied last. Keep these visible until the visitor answers.',
                'label' => 'waiting on customer',
                'title' => 'Wait on customer',
            ],
        ];
    }

    private function legacyQueueRedirect(Request $request): ?RedirectResponse
    {
        $ticketQuery = collect($request->query())
            ->filter(fn (mixed $_value, string|int $key): bool => str_starts_with((string) $key, 'ticket_'))
            ->all();

        if ($ticketQuery !== []) {
            return redirect()->route('dashboard.tickets.index', $ticketQuery);
        }

        $conversationQuery = collect($request->query())
            ->filter(fn (mixed $_value, string|int $key): bool => str_starts_with((string) $key, 'conversation_'))
            ->all();

        if ($conversationQuery !== []) {
            return redirect()->route('dashboard.conversations.index', $conversationQuery);
        }

        return null;
    }
}
