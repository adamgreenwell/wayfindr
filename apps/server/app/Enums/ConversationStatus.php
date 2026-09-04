<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
