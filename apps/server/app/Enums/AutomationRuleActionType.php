<?php

namespace App\Enums;

enum AutomationRuleActionType: string
{
    case AssignAgent = 'assign_agent';
    case AddLabel = 'add_label';
    case SetPriority = 'set_priority';
    case SetStatus = 'set_status';
    case NotifyAgent = 'notify_agent';
    case PostInternalNote = 'post_internal_note';

    public function supports(AutomationRuleEvent $event): bool
    {
        return $this->supportsSubjectType(
            $event->isTicketEvent()
                ? AutomationMacroSubjectType::Ticket
                : AutomationMacroSubjectType::Conversation,
        );
    }

    public function supportsSubjectType(AutomationMacroSubjectType $subjectType): bool
    {
        return match ($this) {
            self::AddLabel, self::PostInternalNote => $subjectType === AutomationMacroSubjectType::Ticket,
            default => true,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
