<?php

namespace App\Support\Webhooks;

use App\Jobs\DeliverOutboundWebhook;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OutboundWebhookEndpoint;
use App\Models\Ticket;
use App\Support\Api\V1\Payload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns domain writes into a durable, thin webhook outbox.
 *
 * This runs from model observers so widget, dashboard, email and public API
 * writes all take the same path. When the resource is inside a transaction the
 * delivery rows join it; queue handoff waits until the outermost commit.
 */
final class OutboundWebhookPublisher
{
    public function conversationOpened(Conversation $conversation): void
    {
        $this->publish(
            OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
            $conversation,
            (int) $conversation->site_id,
            Carbon::instance($conversation->created_at ?? now()),
        );
    }

    public function messageCreated(ConversationMessage $message): void
    {
        $message->loadMissing('conversation');

        $this->publish(
            OutboundWebhookEndpoint::EVENT_CONVERSATION_MESSAGE_CREATED,
            $message,
            (int) $message->conversation->site_id,
            Carbon::instance($message->created_at ?? now()),
        );
    }

    public function ticketCreated(Ticket $ticket): void
    {
        $this->publish(
            OutboundWebhookEndpoint::EVENT_TICKET_CREATED,
            $ticket,
            (int) $ticket->site_id,
            Carbon::instance($ticket->created_at ?? now()),
        );
    }

    public function ticketClosed(Ticket $ticket): void
    {
        $this->publish(
            OutboundWebhookEndpoint::EVENT_TICKET_CLOSED,
            $ticket,
            (int) $ticket->site_id,
            Carbon::instance($ticket->closed_at ?? $ticket->updated_at ?? now()),
        );
    }

    private function publish(string $event, Model $resource, int $siteId, Carbon $occurredAt): void
    {
        $deliveryIds = DB::transaction(function () use ($event, $resource, $siteId, $occurredAt): array {
            $endpoints = OutboundWebhookEndpoint::query()
                ->where('account_id', $this->accountId($resource))
                ->whereNull('disabled_at')
                ->whereHas('sites', fn ($query) => $query->whereKey($siteId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (OutboundWebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event));

            $ids = [];

            foreach ($endpoints as $endpoint) {
                $sequence = (int) $endpoint->next_sequence;
                $publicId = (string) Str::uuid();
                $payload = $this->payload($publicId, $event, $sequence, $occurredAt, $resource);

                $delivery = $endpoint->deliveries()->create([
                    'public_id' => $publicId,
                    'site_id' => $siteId,
                    'event' => $event,
                    'sequence' => $sequence,
                    'payload' => $payload,
                ]);

                $endpoint->forceFill(['next_sequence' => $sequence + 1])->save();
                $ids[] = (int) $delivery->id;
            }

            return $ids;
        });

        if ($deliveryIds === []) {
            return;
        }

        DB::afterCommit(function () use ($deliveryIds): void {
            foreach ($deliveryIds as $deliveryId) {
                try {
                    DeliverOutboundWebhook::dispatchPending($deliveryId);
                } catch (Throwable $exception) {
                    // The database outbox is the acceptance boundary. A queue
                    // outage must not invite a caller to repeat the core write;
                    // the minutely recovery command will hand this row off.
                    Log::error('Outbound webhook stored, but its immediate queue handoff failed.', [
                        'outbound_webhook_delivery_id' => $deliveryId,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    private function accountId(Model $resource): int
    {
        return match (true) {
            $resource instanceof Conversation => (int) $resource->site()->value('account_id'),
            $resource instanceof ConversationMessage => (int) $resource->conversation->site()->value('account_id'),
            $resource instanceof Ticket => (int) $resource->site()->value('account_id'),
        };
    }

    /** @return array<string, mixed> */
    private function payload(
        string $publicId,
        string $event,
        int $sequence,
        Carbon $occurredAt,
        Model $resource,
    ): array {
        return match (true) {
            $resource instanceof Conversation => Payload::webhookConversation($publicId, $event, $sequence, $occurredAt, $resource),
            $resource instanceof ConversationMessage => Payload::webhookMessage($publicId, $event, $sequence, $occurredAt, $resource),
            $resource instanceof Ticket => Payload::webhookTicket($publicId, $event, $sequence, $occurredAt, $resource),
        };
    }
}
