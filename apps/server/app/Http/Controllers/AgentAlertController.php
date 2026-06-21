<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AgentAlertController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $alertFilter = $request->query('alert_filter') === 'unread' ? 'unread' : 'all';
        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
        $unreadNotificationCount = $visibleUnreadNotifications->count();
        $notifications = $alertFilter === 'unread'
            ? $visibleUnreadNotifications->take(30)->values()
            : $this->visibleRecentNotifications($agent, $visibleUnreadNotifications);

        return view('agent.alerts.index', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'alertFilter' => $alertFilter,
            'alertSnapshot' => $this->alertSnapshot($notifications, $unreadNotificationCount),
            'notifications' => $notifications,
            'notificationCount' => $notifications->count(),
            'unreadNotificationCount' => $unreadNotificationCount,
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $agent = $request->user();

        abort_unless(Gate::forUser($agent)->allows('markRead', $notification), 404);

        $notification->markAsRead();

        return $this->redirectAfterAlertAction($request);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $agent = $request->user();

        $agent
            ->unreadNotifications()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('markRead', $notification))
            ->each
            ->markAsRead();

        return $this->redirectAfterAlertAction($request);
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function visibleRecentNotifications(User $agent, ?Collection $visibleUnreadNotifications = null): Collection
    {
        $visibleUnreadNotifications ??= $this->visibleUnreadNotifications($agent);
        $visibleUnreadNotificationIds = $visibleUnreadNotifications->pluck('id');
        $visibleRecentNotifications = $agent
            ->notifications()
            ->latest()
            ->take(60)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->reject(fn (DatabaseNotification $notification): bool => $visibleUnreadNotificationIds->contains($notification->id));

        return $visibleUnreadNotifications
            ->merge($visibleRecentNotifications)
            ->take(30)
            ->values();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function visibleUnreadNotifications(User $agent): Collection
    {
        return $agent
            ->unreadNotifications()
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->values();
    }

    /**
     * @return array<int, array{label: string, value: string, detail: string}>
     */
    private function alertSnapshot(Collection $visibleNotifications, int $visibleUnreadNotificationCount): array
    {
        $conversationAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'kind') === 'conversation_needs_reply')
            ->count();
        $ticketAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'kind') === 'ticket_assigned')
            ->count();

        return [
            [
                'label' => 'Visible alerts',
                'value' => $visibleNotifications->count().' visible',
                'detail' => 'Current alerts you can still open.',
            ],
            [
                'label' => 'Unread alerts',
                'value' => $visibleUnreadNotificationCount.' unread',
                'detail' => $visibleUnreadNotificationCount > 0
                    ? 'Still waiting for review or mark-read.'
                    : 'No visible unread alerts.',
            ],
            [
                'label' => 'Conversation alerts',
                'value' => $conversationAlertCount.' '.Str::plural('conversation', $conversationAlertCount),
                'detail' => 'Visitor replies and chat follow-up.',
            ],
            [
                'label' => 'Ticket alerts',
                'value' => $ticketAlertCount.' '.Str::plural('ticket', $ticketAlertCount),
                'detail' => 'Ticket assignments and durable work.',
            ],
        ];
    }

    private function redirectAfterAlertAction(Request $request): RedirectResponse
    {
        if ($request->input('return_to') === 'alerts') {
            return redirect()->route('dashboard.alerts.index', [
                'alert_filter' => $request->input('alert_filter') === 'unread' ? 'unread' : null,
            ]);
        }

        return redirect()->to(route('dashboard').'#alerts');
    }
}
