<?php

namespace App\Observers;

use App\Models\ConversationMessage;
use App\Support\Sla\SlaClockManager;
use App\Support\UnattendedConversationAlertCollector;
use App\Support\Webhooks\OutboundWebhookPublisher;

class ConversationMessageObserver
{
    public function created(ConversationMessage $message): void
    {
        app(UnattendedConversationAlertCollector::class)->conversationMessageCreated($message);
        app(SlaClockManager::class)->conversationMessageCreated($message);
        app(OutboundWebhookPublisher::class)->messageCreated($message);
    }
}
