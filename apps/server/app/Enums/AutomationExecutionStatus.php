<?php

namespace App\Enums;

enum AutomationExecutionStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
