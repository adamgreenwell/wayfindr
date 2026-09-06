<?php

declare(strict_types=1);

namespace App\Support\ProactiveMessages;

use App\Models\Conversation;
use App\Models\ProactiveMessageDelivery;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\Visitor;

/** Carries an engaged invitation into the ordinary conversation transcript. */
final class ProactiveConversationOpening
{
    public function lockForVisitor(
        ?string $deliveryPublicId,
        Site $site,
        Visitor $visitor,
    ): ?ProactiveMessageDelivery {
        if ($deliveryPublicId === null) {
            return null;
        }

        $delivery = ProactiveMessageDelivery::query()
            ->where('public_id', $deliveryPublicId)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $delivery instanceof ProactiveMessageDelivery
            && $delivery->shown_at !== null
            && $delivery->engaged_at !== null
            && $delivery->dismissed_at === null
            && $delivery->conversation_id === null,
            404,
        );

        return $delivery;
    }

    public function reserve(ProactiveMessageDelivery $delivery, Conversation $conversation): void
    {
        $delivery->forceFill(['conversation_id' => $conversation->id])->save();
    }

    /** Attach the reserved opener only inside the first successful send. */
    public function attachReserved(Conversation $conversation): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $delivery = ProactiveMessageDelivery::query()
            ->where('conversation_id', $conversation->id)
            ->lockForUpdate()
            ->first();

        if (! $delivery instanceof ProactiveMessageDelivery) {
            return;
        }

        $conversation->messages()->create([
            // A real support-side opening, but not credited to a human agent.
            // The default API presentation already calls an unknown sender
            // `system`; the dashboard and widget give this specific class a
            // clearer label.
            'sender_type' => ProactiveMessageRule::class,
            'sender_id' => $delivery->proactive_message_rule_id,
            'type' => 'text',
            'body' => $delivery->message,
            'metadata' => ['proactive_delivery_id' => $delivery->public_id],
        ]);
    }
}
