<?php

namespace App\Listeners;

use App\Enums\AutomationRuleEvent;
use App\Events\ConversationCreated;
use App\Support\Automation\AutomationRuleEngine;

final readonly class ExecuteConversationCreatedAutomationRules
{
    public function __construct(private AutomationRuleEngine $automationRules) {}

    public function handle(ConversationCreated $event): void
    {
        $this->automationRules->handle(AutomationRuleEvent::ConversationCreated, $event->conversation);
    }
}
