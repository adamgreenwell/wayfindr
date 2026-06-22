<?php

namespace App\Support;

final class ExternalIssueSyncStatus
{
    public const string LINKED = 'linked';

    public const string PENDING = 'sync_pending';

    public const string FAILED = 'sync_failed';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::LINKED => 'Linked',
            self::PENDING => 'Sync pending',
            self::FAILED => 'Sync failed',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::options());
    }

    public static function label(?string $status): string
    {
        return self::options()[$status ?? ''] ?? 'Unknown';
    }
}
