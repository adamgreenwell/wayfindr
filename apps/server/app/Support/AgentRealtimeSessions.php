<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\EvictAgentRealtimeSessions;
use App\Models\AgentRealtimeEviction;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function requestMany(iterable $agentIds): void
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return;
        }

        $now = now();
        $requests = $this->agentIds($agentIds)
            ->map(fn (int $agentId): array => [
                'agent_id' => $agentId,
                'request_id' => (string) Str::uuid(),
                'attempts' => 0,
                'requested_at' => $now,
                'last_attempted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($requests === []) {
            return;
        }

        // This is called inside the same account transaction as the role or
        // access change. Either both the revocation and its eviction request
        // commit, or neither does.
        AgentRealtimeEviction::query()->upsert(
            $requests,
            ['agent_id'],
            ['request_id', 'attempts', 'requested_at', 'last_attempted_at', 'updated_at'],
        );
    }

    /** @param iterable<int, int|string> $agentIds */
    public function disconnectMany(iterable $agentIds): void
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return;
        }

        $this->agentIds($agentIds)
            ->each(function (int $agentId): void {
                try {
                    $this->disconnectPending($agentId);
                } catch (Throwable $exception) {
                    // Permissions are already committed and stay revoked even
                    // when Reverb is unavailable. The durable request remains,
                    // while both this job and the scheduled recovery pass keep
                    // retrying until the server accepts the termination.
                    report($exception);

                    try {
                        EvictAgentRealtimeSessions::dispatch($agentId);
                    } catch (Throwable $dispatchException) {
                        // The database row is the source of truth. A queue or
                        // cache outage cannot strand it; the scheduler will try
                        // this handoff again after those services recover.
                        report($dispatchException);
                    }
                }
            });
    }

    public function disconnectPending(int $agentId): void
    {
        $eviction = AgentRealtimeEviction::query()
            ->where('agent_id', $agentId)
            ->first();

        if (! $eviction instanceof AgentRealtimeEviction) {
            return;
        }

        $requestId = $eviction->request_id;
        $claimed = AgentRealtimeEviction::query()
            ->whereKey($eviction->id)
            ->where('request_id', $requestId)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_attempted_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $this->disconnect($agentId);

        // A newer access change may have replaced the request while the network
        // call was in flight. Delete only the request this call satisfied; the
        // newer one remains for its own termination attempt.
        AgentRealtimeEviction::query()
            ->whereKey($eviction->id)
            ->where('request_id', $requestId)
            ->delete();
    }

    /** @param iterable<int, int|string> $agentIds */
    private function agentIds(iterable $agentIds): Collection
    {
        return collect($agentIds)
            ->map(fn (int|string $agentId): int => (int) $agentId)
            ->filter(fn (int $agentId): bool => $agentId > 0)
            ->unique()
            ->values();
    }
}
