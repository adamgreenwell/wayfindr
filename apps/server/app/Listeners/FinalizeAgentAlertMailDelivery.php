<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Notifications\Events\NotificationSent;

/** Finalize the durable version claim only after the mail channel succeeds. */
final readonly class FinalizeAgentAlertMailDelivery
{
    public function __construct(private AgentAlertDeliveryCoordinator $deliveries) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail'
            || ! in_array(CoordinatesAgentAlertMail::class, class_uses_recursive($event->notification), true)) {
            return;
        }

        $claim = $event->notification->agentAlertMailClaim();

        if ($claim !== null) {
            $this->deliveries->acceptMailClaim($claim);
        }
    }
}
