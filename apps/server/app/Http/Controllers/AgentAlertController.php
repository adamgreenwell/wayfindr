<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AgentAlertController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $notifications = $this->visibleRecentNotifications($agent);
        $unreadNotificationCount = $this->visibleUnreadNotifications($agent)->count();

        return view('agent.alerts.index', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
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
    private function visibleRecentNotifications(User $agent): Collection
    {
        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
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

    private function redirectAfterAlertAction(Request $request): RedirectResponse
    {
        if ($request->input('return_to') === 'alerts') {
            return redirect()->route('dashboard.alerts.index');
        }

        return redirect()->to(route('dashboard').'#alerts');
    }
}
