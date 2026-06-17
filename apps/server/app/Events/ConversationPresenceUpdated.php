<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Conversation $conversation)
    {
        $this->conversation->loadMissing('visitor');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->conversation->support_code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.presence.updated';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, visitor_presence: array{state: string, label: string, detail: string, last_seen_at: string|null}}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->conversation->support_code,
                'status' => $this->conversation->status,
            ],
            'visitor_presence' => $this->conversation->visitorPresencePayload(),
        ];
    }
}
