<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Exceptions\AgentAlertDeliveryPendingException;
use App\Models\User;
use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Notifications\Events\NotificationSending;

/** Claim the exact alert version before Laravel hands its email to the channel. */
final readonly class ClaimAgentAlertMailDelivery
{
    public function __construct(private AgentAlertDeliveryCoordinator $deliveries) {}

    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail'
            || ! $event->notifiable instanceof User
            || ! in_array(CoordinatesAgentAlertMail::class, class_uses_recursive($event->notification), true)) {
            return null;
        }

        if ($event->notification->agentAlertMailClaim() !== null) {
            return null;
        }

        $decision = $this->deliveries->claimNotificationMail(
            $event->notifiable,
            $event->notification,
        );

        if ($decision['status'] === 'covered') {
            return false;
        }

        if ($decision['status'] === 'pending') {
            throw new AgentAlertDeliveryPendingException;
        }

        if ($decision['status'] === 'claimed') {
            $event->notification->useAgentAlertMailClaim($decision['claim']);
        }

        // A missing companion database notification is a rolling-deploy seam.
        // Keep mail available rather than turning deduplication into alert loss.
        return null;
    }
}
