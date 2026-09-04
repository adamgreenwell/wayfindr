<?php

namespace App\Console\Commands;

use App\Jobs\EvictAgentRealtimeSessions;
use App\Models\AgentRealtimeEviction;
use Illuminate\Console\Command;
use Throwable;

class QueueAgentRealtimeEvictionsCommand extends Command
{
    protected $signature = 'wayfindr:queue-agent-realtime-evictions';

    protected $description = 'Queue durable agent realtime evictions whose immediate handoff did not complete.';

    public function handle(): int
    {
        $queued = 0;
        $failed = 0;

        AgentRealtimeEviction::query()
            ->orderBy('id')
            ->chunkById(100, function ($evictions) use (&$queued, &$failed): void {
                foreach ($evictions as $eviction) {
                    try {
                        EvictAgentRealtimeSessions::dispatch((int) $eviction->agent_id);
                        $queued++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        $this->info(sprintf('Queued %d agent realtime %s.', $queued, $queued === 1 ? 'eviction' : 'evictions'));

        if ($failed > 0) {
            $this->error(sprintf('Could not queue %d agent realtime %s.', $failed, $failed === 1 ? 'eviction' : 'evictions'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
