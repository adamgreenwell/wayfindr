<?php

namespace App\Console\Commands;

use App\Jobs\SendConversationReplyDelivery;
use App\Models\ConversationReplyDelivery;
use Illuminate\Console\Command;

class QueueConversationReplyDeliveriesCommand extends Command
{
    protected $signature = 'wayfindr:queue-conversation-reply-deliveries';

    protected $description = 'Queue pending or cooled-down conversation emails awaiting mail transport acceptance.';

    public function handle(): int
    {
        $queued = 0;

        ConversationReplyDelivery::query()
            ->awaitingDispatch()
            ->orderBy('id')
            ->chunkById(100, function ($deliveries) use (&$queued): void {
                foreach ($deliveries as $delivery) {
                    SendConversationReplyDelivery::dispatchPending($delivery->id);
                    $queued++;
                }
            });

        $this->info(sprintf('Queued %d pending conversation reply %s.', $queued, $queued === 1 ? 'delivery' : 'deliveries'));

        return self::SUCCESS;
    }
}
