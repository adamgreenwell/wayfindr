<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\DatabaseNotification;

/** Build the one browser alert payload shared by live and catch-up delivery. */
final class AgentAlertPayload
{
    /**
     * @return array{
     *     id: string,
     *     data: array<string, mixed>,
     *     created_at: string|null,
     *     alerted_at: string|null,
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
            'alerted_at' => self::alertedAt($alert)?->toJSON(),
            'updated_at' => $alert->updated_at?->toJSON(),
            'version' => self::version($alert),
        ];
    }

    public static function version(DatabaseNotification $alert): string
    {
        $version = $alert->getAttribute('agent_alert_version');

        // The migration backfills every existing row. The ID fallback keeps a
        // directly constructed notification payload safe during rolling code
        // updates or isolated tests without treating unrelated writes as new.
        return is_string($version) && $version !== ''
            ? $version
            : (string) $alert->id;
    }

    public static function alertedAt(DatabaseNotification $alert): ?CarbonImmutable
    {
        $value = $alert->getAttribute('agent_alerted_at');

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)
            : null;
    }
}
