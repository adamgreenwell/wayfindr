<?php

namespace App\Jobs;

use App\Support\AgentRealtimeSessions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvictAgentRealtimeSessions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A permission eviction remains actionable until Reverb accepts it. The
    // final backoff value is reused for later attempts, so a sustained outage
    // does not create a tight retry loop.
    public int $tries = 0;

    public int $timeout = 30;

    // Let the scheduler recover a lost queue reservation after an hour. A
    // duplicate termination is harmless; a permanently stale unique lock is
    // not.
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $agentId) {}

    public function uniqueId(): string
    {
        return (string) $this->agentId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(AgentRealtimeSessions $sessions): void
    {
        $sessions->disconnectPending($this->agentId);
    }
}
