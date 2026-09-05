<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

/** Detect a foreground dashboard through its dedicated Reverb presence channel. */
final class AgentVisibleRealtimePresence
{
    public function hasVisibleClient(User|int $agent): bool
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return false;
        }

        $agentId = (int) ($agent instanceof User ? $agent->id : $agent);

        if ($agentId <= 0) {
            return false;
        }

        try {
            $broadcaster = Broadcast::connection('reverb');

            if (! $broadcaster instanceof PusherBroadcaster) {
                return false;
            }

            $presence = $broadcaster->getPusher()->getPresenceUsers(self::channelName($agentId));
            $users = is_array($presence->users ?? null) ? $presence->users : [];

            return collect($users)->contains(function (mixed $user) use ($agentId): bool {
                $observedId = is_object($user)
                    ? ($user->id ?? null)
                    : (is_array($user) ? ($user['id'] ?? null) : null);

                return is_scalar($observedId) && (string) $observedId === (string) $agentId;
            });
        } catch (Throwable) {
            // Realtime visibility is an optimization, not a delivery gate. If
            // Reverb cannot answer, send Web Push so a closed dashboard still
            // receives the alert.
            return false;
        }
    }

    public static function channelName(User|int $agent): string
    {
        return 'presence-visible-agents.'.(int) ($agent instanceof User ? $agent->id : $agent);
    }
}
