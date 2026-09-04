<?php

namespace App\Console\Commands;

use App\Jobs\DeliverTicketExternalComment;
use App\Models\TicketExternalCommentDelivery;
use Illuminate\Console\Command;

class QueueTicketExternalCommentDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:queue-ticket-external-comments';

    protected $description = 'Queue pending external ticket comments whose initial handoff did not complete.';

    public function handle(): int
    {
        $queued = 0;

        TicketExternalCommentDelivery::query()
            ->awaitingDispatch()
            ->orderBy('id')
            ->chunkById(100, function ($deliveries) use (&$queued): void {
                foreach ($deliveries as $delivery) {
                    DeliverTicketExternalComment::dispatchPending($delivery->id);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued %d pending external comment %s.', $queued, $queued === 1 ? 'delivery' : 'deliveries'));

        return self::SUCCESS;
    }
}
