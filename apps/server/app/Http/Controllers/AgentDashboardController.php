<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\RealtimeHealth;
use App\Support\VisitorSupportReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AgentDashboardController extends Controller
{
    public function __invoke(Request $request, RealtimeHealth $realtimeHealth, VisitorSupportReadiness $visitorSupportReadiness): View|RedirectResponse
    {
        if ($redirect = $this->legacyQueueRedirect($request)) {
            return $redirect;
        }

        [$agent, $account, $sites] = $this->dashboardContext($request);
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

        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
        $unreadNotificationCount = $visibleUnreadNotifications->count();
        $unreadNotifications = $visibleUnreadNotifications->take(5);

        $realtimeHealthSummary = $realtimeHealth->summary();

        return view('agent.dashboard', [
            'account' => $account,
            'agent' => $agent,
            'agents' => $agents,
            'adminShortcuts' => $this->adminShortcuts($agent),
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'realtimeHealth' => $realtimeHealthSummary,
            'sites' => $sites,
            'supportQueues' => $this->supportQueues($agent),
            'unreadNotificationCount' => $unreadNotificationCount,
            'unreadNotifications' => $unreadNotifications,
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
     *     open_tickets_count: int,
     *     unassigned_tickets_count: int
     * }
     */
    private function supportQueues(User $agent): array
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
            'open_tickets_count' => (clone $visibleOpenTickets)->count(),
            'unassigned_tickets_count' => (clone $visibleOpenTickets)->whereNull('assignee_id')->count(),
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
                'label' => 'Ticket labels',
                'description' => 'Rename or retire account-wide ticket triage labels.',
                'href' => route('dashboard.account.labels.index'),
                'action' => 'Manage',
            ],
            [
                'label' => 'Reply templates',
                'description' => 'Create reusable visitor replies agents can customize before sending.',
                'href' => route('dashboard.account.reply-templates.index'),
                'action' => 'Manage',
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
}
