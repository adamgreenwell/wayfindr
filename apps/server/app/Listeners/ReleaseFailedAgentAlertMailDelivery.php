<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use App\Support\AgentAlertDeliveryCoordinator;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Release only pre-transport failures; a started SMTP outcome stays claimed. */
final readonly class ReleaseFailedAgentAlertMailDelivery
{
    public function __construct(private AgentAlertDeliveryCoordinator $deliveries) {}

    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail'
            || ! in_array(CoordinatesAgentAlertMail::class, class_uses_recursive($event->notification), true)) {
            return;
        }

        $claim = $event->notification->agentAlertMailClaim();

        if ($claim === null) {
            return;
        }

        try {
            $this->deliveries->releaseUnstartedMailClaim($claim);
        } catch (Throwable $exception) {
            // Retaining a claim is the safe side of an unknown SMTP outcome.
            // Do not replace the transport's useful exception with cleanup.
            Log::critical('Agent alert mail claim could not be released after a failed send.', [
                'notification_id' => $claim['notification_id'],
                'alert_version' => $claim['alert_version'],
                'exception' => $exception,
            ]);
        }
    }
}
