<?php

namespace App\Enums;

enum AutomationRuleEvent: string
{
    case ConversationCreated = 'conversation.created';
    case VisitorMessageCreated = 'conversation.visitor_message_created';
    case TicketCreated = 'ticket.created';
    case TicketUpdated = 'ticket.updated';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
