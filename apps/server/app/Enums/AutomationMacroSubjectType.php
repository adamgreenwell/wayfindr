<?php

namespace App\Enums;

use App\Models\Conversation;
use App\Models\Ticket;

enum AutomationMacroSubjectType: string
{
    case Ticket = 'ticket';
    case Conversation = 'conversation';

    public function event(): AutomationRuleEvent
    {
        return match ($this) {
            self::Ticket => AutomationRuleEvent::TicketUpdated,
            self::Conversation => AutomationRuleEvent::ConversationCreated,
        };
    }

    public function matches(Ticket|Conversation $subject): bool
    {
        return match ($this) {
            self::Ticket => $subject instanceof Ticket,
            self::Conversation => $subject instanceof Conversation,
        };
    }
}
