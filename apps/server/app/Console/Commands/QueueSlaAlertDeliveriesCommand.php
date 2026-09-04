<?php

namespace App\Console\Commands;

use App\Jobs\SendSlaDeadlineAlertDelivery;
use App\Models\SlaAlertDelivery;
use Illuminate\Console\Command;
use Throwable;

class QueueSlaAlertDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:queue-sla-alert-deliveries';

    protected $description = 'Recover durable SLA alerts whose worker handoff or delivery did not complete.';

    public function handle(): int
    {
        $queued = 0;
        $failed = 0;

        SlaAlertDelivery::query()
            ->awaitingDispatch()
            ->orderBy('id')
            ->chunkById(100, function ($deliveries) use (&$failed, &$queued): void {
                foreach ($deliveries as $delivery) {
                    try {
                        SendSlaDeadlineAlertDelivery::dispatchPending((int) $delivery->id, $delivery->channel);
                        $queued++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            });

        $this->info(sprintf('Queued %d pending SLA alert %s.', $queued, $queued === 1 ? 'delivery' : 'deliveries'));

        if ($failed > 0) {
            $this->error(sprintf('Could not queue %d SLA alert %s.', $failed, $failed === 1 ? 'delivery' : 'deliveries'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
