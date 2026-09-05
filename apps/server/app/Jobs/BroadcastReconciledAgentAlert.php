<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Support\AgentAlertBroadcaster;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Publish one state repaired by the rolling-release compatibility sweep. */
final class BroadcastReconciledAgentAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly string $notificationId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(AgentAlertBroadcaster $broadcaster): void
    {
        $notification = DatabaseNotification::query()->find($this->notificationId);

        if (! $notification instanceof DatabaseNotification) {
            return;
        }

        $recipient = User::query()->whereKey($notification->notifiable_id)->first();

        if (! $recipient instanceof User
            || (string) $notification->notifiable_type !== $recipient->getMorphClass()) {
            return;
        }

        // The sweep established a durable version without consuming its live
        // claim. Publish that exact state; a concurrent current-release
        // listener safely wins or loses the same claim without duplicating it.
        $broadcaster->stored($recipient, $notification);
    }
}
