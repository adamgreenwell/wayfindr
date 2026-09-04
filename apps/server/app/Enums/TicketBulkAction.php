<?php

namespace App\Enums;

enum TicketBulkAction: string
{
    case AssignAgent = 'assign_agent';
    case AddLabel = 'add_label';
    case SetPriority = 'set_priority';
    case SetStatus = 'set_status';
    case Close = 'close';

    public function requiresValue(): bool
    {
        return $this !== self::Close;
    }

    public function automationAction(): AutomationRuleActionType
    {
        return match ($this) {
            self::AssignAgent => AutomationRuleActionType::AssignAgent,
            self::AddLabel => AutomationRuleActionType::AddLabel,
            self::SetPriority => AutomationRuleActionType::SetPriority,
            self::SetStatus, self::Close => AutomationRuleActionType::SetStatus,
        };
    }
}
