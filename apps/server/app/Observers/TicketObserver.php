<?php

namespace App\Observers;

use App\Enums\AutomationRuleEvent;
use App\Models\Ticket;
use App\Support\Automation\AutomationExecutionGuard;
use App\Support\Automation\AutomationRuleEngine;
use App\Support\Routing\AutomaticAssignmentRouter;
use App\Support\Sla\SlaClockManager;
use App\Support\Webhooks\OutboundWebhookPublisher;

class TicketObserver
{
    public function created(Ticket $ticket): void
    {
        app(SlaClockManager::class)->startTicket($ticket);
        app(AutomationExecutionGuard::class)->suppress(
            $ticket,
            fn () => app(AutomaticAssignmentRouter::class)->assignTicket($ticket),
        );
        app(OutboundWebhookPublisher::class)->ticketCreated($ticket);
    }

    public function updated(Ticket $ticket): void
    {
        app(SlaClockManager::class)->ticketUpdated($ticket);

        if ($ticket->wasChanged('status') && $ticket->status === 'closed') {
            app(OutboundWebhookPublisher::class)->ticketClosed($ticket);
        }

        app(AutomationRuleEngine::class)->handle(AutomationRuleEvent::TicketUpdated, $ticket);
    }
}
