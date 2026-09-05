<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AccountPermission;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;

/** A durable dashboard alert was created or refreshed for one agent. */
final class AgentAlertStored implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $recipient,
        public DatabaseNotification $alert,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf(
            'accounts.%d.agents.%d.alerts',
            $this->recipient->account_id,
            $this->recipient->id,
        ))];
    }

    public function broadcastAs(): string
    {
        return 'agent.alert.stored';
    }

    /**
     * Recheck the recipient boundary at the last synchronous moment before
     * broadcast. Role/access writers evict existing sockets after commit; this
     * closes the smaller race in which an event is ready while that eviction
     * is still in flight.
     */
    public function broadcastWhen(): bool
    {
        $recipient = User::query()->whereKey($this->recipient->id)->first();

        return $recipient instanceof User
            && ! $recipient->isDeactivated()
            && (int) $recipient->account_id === (int) $this->recipient->account_id
            && $recipient->hasAccountPermission(AccountPermission::ViewAlerts)
            && ($currentAlert = DatabaseNotification::query()
                ->whereKey($this->alert->id)
                ->where('notifiable_type', $recipient->getMorphClass())
                ->where('notifiable_id', $recipient->id)
                ->first()) instanceof DatabaseNotification
            && Gate::forUser($recipient)->allows('view', $currentAlert);
    }

    /** @return array{alert: array{id: string, data: array<string, mixed>, created_at: string|null, updated_at: string|null}} */
    public function broadcastWith(): array
    {
        return [
            'alert' => [
                'id' => (string) $this->alert->id,
                // Exactly the database-channel payload. Browser alerting can
                // consume what the Alerts centre already understands without
                // creating a second schema that will drift from it.
                'data' => $this->alert->data,
                'created_at' => $this->alert->created_at?->toJSON(),
                'updated_at' => $this->alert->updated_at?->toJSON(),
            ],
        ];
    }
}
