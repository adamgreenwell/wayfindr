<?php

namespace App\Listeners;

use App\Events\ConversationMessageCreated;
use App\Events\TicketUpdated;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Facades\DB;

class ReopenPendingTicketsForVisitorReply
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    /**
     * Handle the event.
     */
    public function handle(ConversationMessageCreated $event): void
    {
        $message = $event->message;

        if ($message->sender_type !== Visitor::class) {
            return;
        }

        $message->loadMissing('conversation.site');

        $conversation = $message->conversation;
        $observedSite = $conversation?->site;

        if (! $conversation instanceof Conversation || ! $observedSite instanceof Site) {
            return;
        }

        /** @var list<Ticket> $updatedTickets */
        $updatedTickets = DB::transaction(function () use ($conversation, $message, $observedSite): array {
            // Message-created listeners run after the widget write commits. A
            // merge can finish in that gap, so join its account -> site lock
            // order and re-read the actor from the durable message row.
            $this->siteManagerCoverage->shareAccount((int) $observedSite->account_id);
            $site = Site::query()
                ->whereKey($observedSite->id)
                ->where('account_id', $observedSite->account_id)
                ->sharedLock()
                ->first();

            if (! $site instanceof Site) {
                return [];
            }

            $freshMessage = ConversationMessage::query()
                ->whereKey($message->id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if (! $freshMessage instanceof ConversationMessage || $freshMessage->sender_type !== Visitor::class) {
                return [];
            }

            $visitor = Visitor::query()
                ->whereKey($freshMessage->sender_id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->first();

            if (! $visitor instanceof Visitor) {
                return [];
            }

            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->where('site_id', $site->id)
                ->where('visitor_id', $visitor->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof Conversation) {
                return [];
            }

            $freshMessage = ConversationMessage::query()
                ->whereKey($freshMessage->id)
                ->where('conversation_id', $lockedConversation->id)
                ->where('sender_type', Visitor::class)
                ->where('sender_id', $visitor->id)
                ->lockForUpdate()
                ->first();

            if (! $freshMessage instanceof ConversationMessage) {
                return [];
            }

            $tickets = $lockedConversation->tickets()
                ->where('status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $tickets->each(function (Ticket $ticket) use ($lockedConversation, $freshMessage, $visitor): void {
                $previousStatus = $ticket->status;

                $ticket->forceFill([
                    'status' => 'open',
                    'closed_at' => null,
                ])->save();

                $ticket->auditEvents()->create([
                    'account_id' => $ticket->account_id,
                    'site_id' => $ticket->site_id,
                    'actor_type' => Visitor::class,
                    'actor_id' => $visitor->id,
                    'action' => 'ticket.visitor_replied',
                    'metadata' => [
                        'conversation_id' => $lockedConversation->id,
                        'message_id' => $freshMessage->id,
                        'previous_status' => $previousStatus,
                    ],
                    'occurred_at' => $freshMessage->created_at,
                ]);
            });

            return $tickets->all();
        });

        foreach ($updatedTickets as $ticket) {
            event(new TicketUpdated($ticket));
        }
    }
}
