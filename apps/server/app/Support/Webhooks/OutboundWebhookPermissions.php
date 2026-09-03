<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Enums\AccountPermission;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;

final class OutboundWebhookPermissions
{
    /** @return list<string> */
    public static function grantableEvents(User $user): array
    {
        return array_values(array_filter(
            OutboundWebhookEndpoint::EVENTS,
            fn (string $event): bool => self::allows($user, $event),
        ));
    }

    public static function allows(User $user, string $event): bool
    {
        return match ($event) {
            OutboundWebhookEndpoint::EVENT_CONVERSATION_OPENED,
            OutboundWebhookEndpoint::EVENT_CONVERSATION_MESSAGE_CREATED => $user->hasAccountPermission(AccountPermission::ViewConversations),
            OutboundWebhookEndpoint::EVENT_TICKET_CREATED,
            OutboundWebhookEndpoint::EVENT_TICKET_CLOSED => $user->hasAccountPermission(AccountPermission::ManageTickets),
            default => false,
        };
    }
}
