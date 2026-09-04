<?php

namespace App\Listeners;

use App\Models\SlaAlertDelivery;
use App\Notifications\SlaDeadlineAlert;
use Illuminate\Mail\Events\MessageSending;
use LogicException;

/** Place the durable ambiguity boundary immediately before mail transport. */
class MarkSlaMailTransportStarted
{
    public function handle(MessageSending $event): void
    {
        $header = $event->message->getHeaders()->get(SlaDeadlineAlert::DELIVERY_HEADER);

        if ($header === null) {
            return;
        }

        $deliveryId = trim($header->getBodyAsString());
        $started = SlaAlertDelivery::query()
            ->where('public_id', $deliveryId)
            ->where('channel', 'mail')
            ->whereNotNull('claimed_at')
            ->whereNull('started_at')
            ->whereNull('accepted_at')
            ->whereNull('cancelled_at')
            ->update(['started_at' => now()]);

        if ($started !== 1) {
            throw new LogicException('The SLA mail delivery is no longer eligible for transport.');
        }
    }
}
