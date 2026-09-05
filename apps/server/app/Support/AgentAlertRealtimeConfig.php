<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountPermission;
use App\Models\User;

/** Build the browser-safe settings for one agent's dashboard alert stream. */
final class AgentAlertRealtimeConfig
{
    /**
     * @return array{
     *     appKey: string,
     *     authEndpoint: string,
     *     channelName: string,
     *     eventName: string,
     *     host: string,
     *     identityChannelName: string,
     *     port: string,
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
            'port' => $reverb['port'],
            'scheme' => $reverb['scheme'],
            'soundEnabled' => $agent->alertSoundEnabled(),
        ];
    }
}
