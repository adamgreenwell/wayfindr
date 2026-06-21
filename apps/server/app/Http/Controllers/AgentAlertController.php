<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

class AgentAlertController extends Controller
{
    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $agent = $request->user();

        abort_unless(Gate::forUser($agent)->allows('markRead', $notification), 404);

        $notification->markAsRead();

        return redirect()->to(route('dashboard').'#alerts');
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

        return redirect()->to(route('dashboard').'#alerts');
    }
}
