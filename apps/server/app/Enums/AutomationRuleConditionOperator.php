<?php

namespace App\Enums;

enum AutomationRuleConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';

    public function isTextOperator(): bool
    {
        return in_array($this, [self::Contains, self::NotContains], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
