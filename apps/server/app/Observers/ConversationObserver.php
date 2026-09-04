<?php

namespace App\Observers;

use App\Enums\AutomationRuleEvent;
use App\Models\Conversation;
use App\Support\Automation\AutomationRuleEngine;
use App\Support\Routing\AutomaticAssignmentRouter;
use App\Support\Sla\SlaClockManager;
use App\Support\UnattendedConversationAlertCollector;
use App\Support\Webhooks\OutboundWebhookPublisher;

class ConversationObserver
{
    public function created(Conversation $conversation): void
    {
        app(SlaClockManager::class)->startConversation($conversation);
        app(AutomaticAssignmentRouter::class)->assignConversation($conversation);
        app(OutboundWebhookPublisher::class)->conversationOpened($conversation);
        app(AutomationRuleEngine::class)->handle(AutomationRuleEvent::ConversationCreated, $conversation);
    }

    public function updated(Conversation $conversation): void
    {
        app(UnattendedConversationAlertCollector::class)->conversationUpdated($conversation);
        app(SlaClockManager::class)->conversationUpdated($conversation);
    }
}
