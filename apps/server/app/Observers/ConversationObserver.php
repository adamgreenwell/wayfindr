<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Support\Webhooks\OutboundWebhookPublisher;

class ConversationObserver
{
    public function created(Conversation $conversation): void
    {
        app(OutboundWebhookPublisher::class)->conversationOpened($conversation);
    }
}
