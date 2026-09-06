<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProactiveMessageDelivery;
use Illuminate\Console\Command;

/** Delete bounded delivery evidence once it can no longer affect any cap. */
final class PruneProactiveMessageDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:prune-proactive-message-deliveries {--days= : Override the retention window}';

    protected $description = 'Delete proactive-message delivery evidence past the retention window';

    public function handle(): int
    {
        $days = $this->retentionDays();
        $cutoff = now()->subDays($days);
        $deleted = 0;

        ProactiveMessageDelivery::query()
            ->where('claimed_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(500, function ($deliveries) use (&$deleted): void {
                $ids = $deliveries->modelKeys();

                $deleted += ProactiveMessageDelivery::query()
                    ->whereKey($ids)
                    ->delete();
            });

        $this->info(sprintf(
            'Pruned %d proactive-message %s older than %d days.',
            $deleted,
            $deleted === 1 ? 'delivery' : 'deliveries',
            $days,
        ));

        return self::SUCCESS;
    }

    private function retentionDays(): int
    {
        $configured = (int) ($this->option('days')
            ?: config('wayfindr.proactive_messages.retention_days', ProactiveMessageDelivery::RETENTION_DAYS));

        return max(1, min($configured, ProactiveMessageDelivery::RETENTION_DAYS));
    }
}
