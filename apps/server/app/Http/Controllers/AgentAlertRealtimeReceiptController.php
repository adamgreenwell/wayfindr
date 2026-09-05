<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/** Record that a live dashboard actually received one alert version. */
final class AgentAlertRealtimeReceiptController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $agent = $request->user();

        abort_unless(
            $agent instanceof User
            && $agent->account_id !== null
            && ! $agent->isDeactivated()
            && $agent->hasAccountPermission(AccountPermission::ViewAlerts),
            403,
        );

        $validated = $request->validate([
            'alert_id' => ['required', 'uuid'],
            'version' => ['required', 'string', 'max:255'],
        ]);
        $alert = DatabaseNotification::query()
            ->whereKey($validated['alert_id'])
            ->where('notifiable_type', $agent->getMorphClass())
            ->where('notifiable_id', $agent->getKey())
            ->first();

        if ($alert instanceof DatabaseNotification
            && Gate::forUser($agent)->allows('view', $alert)
            && hash_equals((string) $alert->getAttribute('agent_alert_version'), $validated['version'])
            && ! hash_equals(
                (string) $alert->getAttribute('agent_alert_realtime_received_version'),
                $validated['version'],
            )) {
            DatabaseNotification::query()
                ->whereKey($alert->id)
                ->where('agent_alert_version', $validated['version'])
                ->where(function ($query) use ($validated): void {
                    $query->whereNull('agent_alert_realtime_received_version')
                        ->orWhere('agent_alert_realtime_received_version', '!=', $validated['version']);
                })
                ->update([
                    'agent_alert_realtime_received_version' => $validated['version'],
                ]);
        }

        // A receipt is intentionally non-enumerating. A stale or superseded
        // version is harmless and receives the same response as a current one.
        return response()->noContent();
    }
}
