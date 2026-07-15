<?php

namespace App\Events;

use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ConversationMessage $message)
    {
        $this->message->loadMissing(['conversation', 'sender', 'attachments']);
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->message->conversation->support_code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.message.created';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, message: array{id: int, sender: array{kind: string, name: string}, type: string, body: string, attachments: array<int, array<string, mixed>>, created_at: string|null}}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->message->conversation->support_code,
                'status' => $this->message->conversation->status,
            ],
            'message' => [
                'id' => $this->message->id,
                'sender' => $this->senderPayload(),
                'type' => $this->message->type,
                'body' => $this->message->body,
                // Live messages carry their attachments so a realtime delivery
                // renders them immediately, without waiting for the next poll.
                'attachments' => $this->message->attachments->map->toPayload()->all(),
                'created_at' => $this->message->created_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{kind: string, name: string}
     */
    private function senderPayload(): array
    {
        if ($this->message->sender_type === User::class) {
            return [
                'kind' => 'agent',
                'name' => $this->message->sender?->name ?? 'Agent',
            ];
        }

        return [
            'kind' => 'visitor',
            'name' => 'Visitor',
        ];
    }
}
