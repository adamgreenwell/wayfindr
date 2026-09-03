<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Support\Webhooks\OutboundWebhookPublisher;

class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        app(OutboundWebhookPublisher::class)->ticketCreated($ticket);
    }

    public function updated(Ticket $ticket): void
    {
        if ($ticket->wasChanged('status') && $ticket->status === 'closed') {
            app(OutboundWebhookPublisher::class)->ticketClosed($ticket);
        }
    }
}
