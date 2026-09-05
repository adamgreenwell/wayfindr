<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Notifications\DatabaseNotification;

/** Build the one browser alert payload shared by live and catch-up delivery. */
final class AgentAlertPayload
{
    /**
     * @return array{
     *     id: string,
     *     data: array<string, mixed>,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     version: string
     * }
     */
    public static function for(DatabaseNotification $alert): array
    {
        return [
            'id' => (string) $alert->id,
            'data' => $alert->data,
            'created_at' => $alert->created_at?->toJSON(),
            'updated_at' => $alert->updated_at?->toJSON(),
            'version' => self::version($alert),
        ];
    }

    public static function version(DatabaseNotification $alert): string
    {
        // A notification can be refreshed in place when another visitor
        // message joins the same pending alert. updated_at alone may have only
        // second precision on an install, so include the stored payload too.
        return hash('sha256', implode("\0", [
            (string) $alert->id,
            $alert->updated_at?->toJSON() ?? '',
            serialize($alert->data),
        ]));
    }
}
