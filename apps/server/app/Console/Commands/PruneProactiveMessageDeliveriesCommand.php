<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProactiveMessageDelivery;
use Illuminate\Console\Command;

/** Delete bounded delivery evidence once it can no longer affect any cap. */
final class PruneProactiveMessageDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:prune-proactive-message-deliveries';

    protected $description = 'Delete proactive-message delivery evidence past the retention window';

    public function handle(): int
    {
        $days = ProactiveMessageDelivery::RETENTION_DAYS;
        $cutoff = now()->subDays($days);
        $deleted = 0;

        ProactiveMessageDelivery::query()
            ->where('claimed_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('shown_at')->orWhere('shown_at', '<', $cutoff))
            ->where(fn ($query) => $query->whereNull('engaged_at')->orWhere('engaged_at', '<', $cutoff))
            ->where(fn ($query) => $query->whereNull('dismissed_at')->orWhere('dismissed_at', '<', $cutoff))
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
}
