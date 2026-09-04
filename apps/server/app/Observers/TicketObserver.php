<?php

namespace App\Observers;

use App\Models\Ticket;
use App\Support\Sla\SlaClockManager;
use App\Support\Webhooks\OutboundWebhookPublisher;

class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        app(SlaClockManager::class)->startTicket($ticket);
        app(OutboundWebhookPublisher::class)->ticketCreated($ticket);
    }

    public function updated(Ticket $ticket): void
    {
        app(SlaClockManager::class)->ticketUpdated($ticket);

        if ($ticket->wasChanged('status') && $ticket->status === 'closed') {
            app(OutboundWebhookPublisher::class)->ticketClosed($ticket);
        }
    }
}
