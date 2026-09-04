<?php

namespace App\Observers;

use App\Enums\AutomationRuleEvent;
use App\Models\ConversationMessage;
use App\Models\Visitor;
use App\Support\Automation\AutomationRuleEngine;
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

        if ($message->sender_type === Visitor::class) {
            $message->loadMissing('conversation');
            app(AutomationRuleEngine::class)->handle(
                AutomationRuleEvent::VisitorMessageCreated,
                $message->conversation,
                $message,
            );
        }
    }
}
