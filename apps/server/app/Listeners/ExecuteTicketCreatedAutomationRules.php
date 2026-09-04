<?php

namespace App\Listeners;

use App\Enums\AutomationRuleEvent;
use App\Events\TicketCreated;
use App\Support\Automation\AutomationRuleEngine;

final readonly class ExecuteTicketCreatedAutomationRules
{
    public function __construct(private AutomationRuleEngine $automationRules) {}

    public function handle(TicketCreated $event): void
    {
        $this->automationRules->handle(AutomationRuleEvent::TicketCreated, $event->ticket);
    }
}
