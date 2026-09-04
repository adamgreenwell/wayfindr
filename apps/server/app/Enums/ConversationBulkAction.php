<?php

namespace App\Enums;

enum ConversationBulkAction: string
{
    case AssignAgent = 'assign_agent';
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
            self::SetPriority => AutomationRuleActionType::SetPriority,
            self::SetStatus, self::Close => AutomationRuleActionType::SetStatus,
        };
    }
}
