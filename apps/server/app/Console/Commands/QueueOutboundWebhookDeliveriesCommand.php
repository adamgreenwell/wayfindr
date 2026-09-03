<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOutboundWebhook;
use App\Models\OutboundWebhookDelivery;
use Illuminate\Console\Command;
use Throwable;

class QueueOutboundWebhookDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:queue-outbound-webhooks';

    protected $description = 'Queue durable outbound webhook deliveries whose queue handoff has not completed.';

    public function handle(): int
    {
        $queued = 0;
        $failed = 0;

        OutboundWebhookDelivery::query()
            ->awaitingDispatch()
            ->orderBy('id')
            ->chunkById(100, function ($deliveries) use (&$queued, &$failed): void {
                foreach ($deliveries as $delivery) {
                    try {
                        DeliverOutboundWebhook::dispatchPending($delivery->id);
                        $queued++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        $this->info(sprintf('Queued %d outbound webhook %s.', $queued, $queued === 1 ? 'delivery' : 'deliveries'));

        if ($failed > 0) {
            $this->error(sprintf('Could not queue %d outbound webhook %s.', $failed, $failed === 1 ? 'delivery' : 'deliveries'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
