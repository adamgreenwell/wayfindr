<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountPermission;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/** Build the browser-safe settings for one agent's dashboard alert stream. */
final class AgentAlertRealtimeConfig
{
    private const RECONCILIATION_OVERLAP_SECONDS = 30;

    /**
     * @return array{
     *     appKey: string,
     *     authEndpoint: string,
     *     channelName: string,
     *     eventName: string,
     *     host: string,
     *     identityChannelName: string,
     *     knownAlerts: list<array{updatedAt: string, version: string}>,
     *     port: string,
     *     reconcileEndpoint: string,
     *     reconcileOverlapSeconds: int,
     *     reconcileSince: string,
     *     scheme: string,
     *     soundEnabled: bool
     * }|null
     */
    public static function forAgent(User $agent): ?array
    {
        if ($agent->account_id === null
            || $agent->isDeactivated()
            || ! $agent->hasAccountPermission(AccountPermission::ViewAlerts)) {
            return null;
        }

        $reverb = WidgetRealtimeConfig::public();

        if ($reverb === null) {
            return null;
        }

        // Reconciliation begins at a deliberately overlapping whole-second
        // boundary. Remember anything already present there so a database
        // whose timestamps have second precision does not turn the overlap
        // into an old-alert cue.
        $reconcileSince = now()
            ->subSeconds(self::RECONCILIATION_OVERLAP_SECONDS)
            ->startOfSecond();
        $knownAlerts = $agent->notifications()
            ->where('updated_at', '>=', $reconcileSince)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->map(fn (DatabaseNotification $notification): array => [
                'updatedAt' => $notification->updated_at->toJSON(),
                'version' => AgentAlertPayload::version($notification),
            ])
            ->values()
            ->all();

        return [
            'appKey' => $reverb['app_key'],
            'authEndpoint' => url('/broadcasting/auth'),
            'channelName' => sprintf(
                'private-accounts.%d.agents.%d.alerts',
                $agent->account_id,
                $agent->id,
            ),
            'eventName' => 'agent.alert.stored',
            'host' => $reverb['host'],
            'identityChannelName' => 'presence-agents.'.$agent->id,
            'knownAlerts' => $knownAlerts,
            'port' => $reverb['port'],
            'reconcileEndpoint' => route('dashboard.alerts.reconcile'),
            'reconcileOverlapSeconds' => self::RECONCILIATION_OVERLAP_SECONDS,
            'reconcileSince' => $reconcileSince->toJSON(),
            'scheme' => $reverb['scheme'],
            'soundEnabled' => $agent->alertSoundEnabled(),
        ];
    }
}
