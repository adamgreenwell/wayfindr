<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use RuntimeException;
use Throwable;

class AgentRealtimeSessions
{
    public function disconnect(User|int $agent): void
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return;
        }

        $broadcaster = Broadcast::connection('reverb');

        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new RuntimeException('The Reverb broadcaster cannot terminate agent connections.');
        }

        // Reverb implements the Pusher terminate-user endpoint. Every agent
        // socket first joins its private identity presence channel, so this
        // closes every tab belonging to the affected account user.
        $broadcaster->getPusher()->terminateUserConnections((string) ($agent instanceof User ? $agent->id : $agent));
    }

    /** @param iterable<int, int|string> $agentIds */
    public function disconnectMany(iterable $agentIds): void
    {
        collect($agentIds)
            ->map(fn (int|string $agentId): int => (int) $agentId)
            ->unique()
            ->each(function (int $agentId): void {
                try {
                    $this->disconnect($agentId);
                } catch (Throwable $exception) {
                    // Permissions are already committed and stay revoked even
                    // when Reverb is unavailable. A failed disconnect is
                    // reported independently so one socket cannot roll back
                    // access control or block the remaining users.
                    report($exception);
                }
            });
    }
}
