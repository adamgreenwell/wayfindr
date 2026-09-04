<?php

namespace App\Enums;

enum AutomationRuleConditionField: string
{
    case Subject = 'subject';
    case Description = 'description';
    case Status = 'status';
    case Priority = 'priority';
    case Category = 'category';
    case SiteId = 'site_id';
    case AssigneeId = 'assignee_id';
    case MessageBody = 'message_body';

    public function supports(AutomationRuleEvent $event): bool
    {
        return match ($this) {
            self::Description, self::Category => $event->isTicketEvent(),
            self::MessageBody => $event === AutomationRuleEvent::VisitorMessageCreated,
            default => true,
        };
    }

    public function supportsTextOperators(): bool
    {
        return in_array($this, [
            self::Subject,
            self::Description,
            self::Category,
            self::MessageBody,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
