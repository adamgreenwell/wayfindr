<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Support\Sla\SlaClockManager;
use App\Support\UnattendedConversationAlertCollector;
use App\Support\Webhooks\OutboundWebhookPublisher;

class ConversationObserver
{
    public function created(Conversation $conversation): void
    {
        app(SlaClockManager::class)->startConversation($conversation);
        app(OutboundWebhookPublisher::class)->conversationOpened($conversation);
    }

    public function updated(Conversation $conversation): void
    {
        app(UnattendedConversationAlertCollector::class)->conversationUpdated($conversation);
        app(SlaClockManager::class)->conversationUpdated($conversation);
    }
}
