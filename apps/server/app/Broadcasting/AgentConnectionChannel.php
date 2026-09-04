<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;

/**
 * Identifies an agent's websocket connection so Reverb can terminate it.
 *
 * Content still travels over its ordinary private channel. This separate,
 * user-specific presence subscription gives the socket a trusted user id
 * without exposing an agent roster to other subscribers.
 */
final class AgentConnectionChannel
{
    /** @return array{connected: true}|false */
    public function join(?User $agent, int|string $agentId): array|false
    {
        if (! $agent instanceof User || $agent->isDeactivated()) {
            return false;
        }

        if (! is_int($agentId) && ! ctype_digit((string) $agentId)) {
            return false;
        }

        if ((int) $agentId !== (int) $agent->id) {
            return false;
        }

        return ['connected' => true];
    }
}
