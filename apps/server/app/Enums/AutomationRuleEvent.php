<?php

namespace App\Enums;

enum AutomationRuleEvent: string
{
    case ConversationCreated = 'conversation.created';
    case VisitorMessageCreated = 'conversation.visitor_message_created';
    case TicketCreated = 'ticket.created';
    case TicketUpdated = 'ticket.updated';

    public function isTicketEvent(): bool
    {
        return in_array($this, [self::TicketCreated, self::TicketUpdated], true);
    }

    public function isConversationEvent(): bool
    {
        return ! $this->isTicketEvent();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
