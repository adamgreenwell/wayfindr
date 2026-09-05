<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Support\AgentAlertBroadcaster;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/** Turn every successfully stored agent notification into one alert event. */
final readonly class BroadcastStoredAgentAlert
{
    public function __construct(private AgentAlertBroadcaster $broadcaster) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database'
            || ! $event->notifiable instanceof User
            || ! $event->response instanceof DatabaseNotification) {
            return;
        }

        $this->broadcaster->stored($event->notifiable, $event->response);
    }
}
