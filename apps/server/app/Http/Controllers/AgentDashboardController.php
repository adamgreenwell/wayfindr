<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\RealtimeHealth;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AgentDashboardController extends Controller
{
    public function __invoke(Request $request, RealtimeHealth $realtimeHealth): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('latestVisitor')
            ->orderBy('name')
            ->get();
        $agents = $account->agents()
            ->withCount([
                'assignedConversations as open_assigned_conversations_count' => fn ($query) => $query
                    ->where('status', 'open')
                    ->whereHas('site', fn ($query) => $query->visibleToAgent($agent)),
                'assignedTickets as open_assigned_tickets_count' => fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
                    ->where('status', 'open'),
            ])
            ->orderBy('name')
            ->get();

        $conversationFilters = [
            'all' => 'All open',
            'new_activity' => 'New activity',
            'needs_reply' => 'Needs reply',
            'assigned_to_me' => 'Assigned to me',
            'unassigned' => 'Unassigned',
        ];
        $conversationFilter = $request->query('conversation_filter', 'all');
        $conversationFilter = is_string($conversationFilter) && array_key_exists($conversationFilter, $conversationFilters)
            ? $conversationFilter
            : 'all';
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
        $ticketAttentionFilters = [
            'all' => 'Any next step',
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
            || $ticketAttention !== 'all'
            || $ticketSearch !== '';
        $ticketEmptyMessage = $ticketHasActiveRefinement
            ? 'No tickets match those filters.'
            : match ($ticketStatus) {
                'all' => 'No tickets yet.',
                'pending' => 'No pending tickets yet.',
                'closed' => 'No closed tickets yet.',
                default => 'No open tickets yet.',
            };
        $conversationEmptyMessage = $conversationFilter === 'new_activity'
            ? 'No conversations need attention.'
            : 'No active conversations yet.';
        $newActivityConversationCount = Conversation::query()
            ->where('status', 'open')
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->withNewActivityFor($agent)
            ->count();

        $conversations = Conversation::query()
            ->with([
                'assignedAgent',
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
        $tickets = Ticket::query()
            ->with(['assignee', 'conversation.latestMessage', 'site'])
            ->where('account_id', $account->id)
            ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
            ->when($ticketStatus !== 'all', fn ($query) => $query->where('status', $ticketStatus))
            ->when($ticketFilter === 'assigned_to_me', fn ($query) => $query->where('assignee_id', $agent->id))
            ->when($ticketFilter === 'unassigned', fn ($query) => $query->whereNull('assignee_id'))
            ->when($ticketSite, fn ($query) => $query->where('site_id', $ticketSite))
            ->when($ticketPriority !== 'all', fn ($query) => $query->where('priority', $ticketPriority))
            ->when($ticketCategory === 'uncategorized', fn ($query) => $query->whereNull('category'))
            ->when($ticketCategory !== 'all' && $ticketCategory !== 'uncategorized', fn ($query) => $query->where('category', $ticketCategory))
            ->when($ticketSearch !== '', function ($query) use ($ticketSearch): void {
                $searchPattern = '%'.$ticketSearch.'%';

                $query->where(function ($query) use ($searchPattern): void {
                    $query
                        ->whereLike('subject', $searchPattern)
                        ->orWhereLike('description', $searchPattern)
                        ->orWhereHas('conversation', fn ($query) => $query->whereLike('support_code', $searchPattern));
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Ticket $ticket): bool => $ticketAttention === 'all' || $ticket->attentionState() === $ticketAttention)
            ->sortBy(fn (Ticket $ticket): array => [
                $ticket->attentionSortRank(),
                -$ticket->updated_at->getTimestamp(),
                -$ticket->created_at->getTimestamp(),
            ])
            ->values();
        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
        $unreadNotificationCount = $visibleUnreadNotifications->count();
        $unreadNotifications = $visibleUnreadNotifications->take(5);

        $ticketQuery = $this->ticketQueryParams($ticketStatus, $ticketFilter, $ticketSite, $ticketPriority, $ticketCategory, $ticketAttention, $ticketSearch);

        return view('agent.dashboard', [
            'account' => $account,
            'agent' => $agent,
            'agents' => $agents,
            'adminShortcuts' => $this->adminShortcuts($agent),
            'conversationEmptyMessage' => $conversationEmptyMessage,
            'conversationFilter' => $conversationFilter,
            'conversationFilters' => $conversationFilters,
            'conversations' => $conversations,
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'newActivityConversationCount' => $newActivityConversationCount,
            'realtimeHealth' => $realtimeHealth->summary(),
            'sites' => $sites,
            'ticketAttention' => $ticketAttention,
            'ticketAttentionFilters' => $ticketAttentionFilters,
            'ticketCategory' => $ticketCategory,
            'ticketCategoryFilters' => $ticketCategoryFilters,
            'ticketEmptyMessage' => $ticketEmptyMessage,
            'ticketFilter' => $ticketFilter,
            'ticketFilters' => $ticketFilters,
            'ticketPriority' => $ticketPriority,
            'ticketPriorityFilters' => $ticketPriorityFilters,
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
                $ticketAttention,
                $ticketAttentionFilters,
                $ticketSearch,
            ),
            'ticketQuery' => $ticketQuery,
            'ticketSearch' => $ticketSearch,
            'ticketSite' => $ticketSite,
            'ticketStatus' => $ticketStatus,
            'ticketStatusFilters' => $ticketStatusFilters,
            'ticketStatusSummary' => $ticketStatusSummary,
            'tickets' => $tickets,
            'unreadNotificationCount' => $unreadNotificationCount,
            'unreadNotifications' => $unreadNotifications,
        ]);
    }

    private function visibleUnreadNotifications(User $agent): Collection
    {
        return $agent->unreadNotifications()
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->values();
    }

    /**
     * @return array<int, array{label: string, description: string, href: string, action: string}>
     */
    private function adminShortcuts(User $agent): array
    {
        if (! $agent->isAdmin()) {
            return [];
        }

        return [
            [
                'label' => 'Team and roles',
                'description' => 'Create agents, adjust roles, and suspend or restore account access.',
                'href' => route('dashboard.account.show').'#agents',
                'action' => 'Manage',
            ],
            [
                'label' => 'Site access',
                'description' => 'Scope which agents can support each connected site.',
                'href' => route('dashboard.account.show').'#site-access-matrix',
                'action' => 'Review',
            ],
            [
                'label' => 'Audit log',
                'description' => 'Search account activity and export safe audit records.',
                'href' => route('dashboard.account.audit.index'),
                'action' => 'Open',
            ],
            [
                'label' => 'Readiness checks',
                'description' => 'Check app key, database, queues, realtime, storage, and scheduler setup.',
                'href' => route('dashboard.readiness.show'),
                'action' => 'Check',
            ],
            [
                'label' => 'Add site',
                'description' => 'Connect another website and prepare its widget install.',
                'href' => route('dashboard.sites.create'),
                'action' => 'Add',
            ],
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private function ticketQueryParams(string $ticketStatus, string $ticketFilter, ?int $ticketSite, string $ticketPriority, string $ticketCategory, string $ticketAttention, string $ticketSearch): array
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

        if ($ticketAttention !== 'all') {
            $params['ticket_attention'] = $ticketAttention;
        }

        if ($ticketSearch !== '') {
            $params['ticket_search'] = $ticketSearch;
        }

        return $params;
    }

    /**
     * @param  array<string, string|int>  $ticketQuery
     * @param  array<string, string>  $ticketStatusFilters
     * @param  array<string, string>  $ticketFilters
     * @param  Collection<int, Site>  $sites
     * @param  array<string, string>  $ticketPriorityFilters
     * @param  array<string, string>  $ticketCategoryFilters
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
        string $ticketAttention,
        array $ticketAttentionFilters,
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

        if ($ticketAttention !== 'all') {
            $filters[] = $this->ticketFilterChip('ticket_attention', 'Next step: '.$ticketAttentionFilters[$ticketAttention], $ticketQuery);
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
            'href' => route('dashboard', $ticketQuery).'#tickets',
        ];
    }
}
