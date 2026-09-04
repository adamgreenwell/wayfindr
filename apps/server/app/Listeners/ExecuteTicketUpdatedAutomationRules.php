<?php

namespace App\Listeners;

use App\Enums\AutomationRuleEvent;
use App\Events\TicketUpdated;
use App\Support\Automation\AutomationRuleEngine;

final readonly class ExecuteTicketUpdatedAutomationRules
{
    public function __construct(private AutomationRuleEngine $automationRules) {}

    public function handle(TicketUpdated $event): void
    {
        $this->automationRules->handle(AutomationRuleEvent::TicketUpdated, $event->ticket);
    }
}
