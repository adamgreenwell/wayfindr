<?php

namespace App\Listeners;

use App\Events\ConversationMessageCreated;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\Visitor;

class ReopenPendingTicketsForVisitorReply
{
    /**
     * Handle the event.
     */
    public function handle(ConversationMessageCreated $event): void
    {
        $message = $event->message;

        if ($message->sender_type !== Visitor::class) {
            return;
        }

        $message->loadMissing('conversation');

        $conversation = $message->conversation;

        if (! $conversation) {
            return;
        }

        $conversation->tickets()
            ->where('status', 'pending')
            ->get()
            ->each(function (Ticket $ticket) use ($conversation, $message): void {
                $previousStatus = $ticket->status;

                $ticket->forceFill([
                    'status' => 'open',
                    'closed_at' => null,
                ])->save();

                $ticket->auditEvents()->create([
                    'account_id' => $ticket->account_id,
                    'site_id' => $ticket->site_id,
                    'actor_type' => Visitor::class,
                    'actor_id' => $message->sender_id,
                    'action' => 'ticket.visitor_replied',
                    'metadata' => [
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'previous_status' => $previousStatus,
                    ],
                    'occurred_at' => $message->created_at,
                ]);
                event(new TicketUpdated($ticket));
            });
    }
}
