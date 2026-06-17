<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationTypingUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Conversation $conversation) {}

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
        return 'conversation.typing.updated';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, agent_typing: array{state: string, label: string|null, updated_at: string|null}, visitor_typing: array{state: string, label: string, updated_at: string|null}}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->conversation->support_code,
                'status' => $this->conversation->status,
            ],
            'agent_typing' => $this->conversation->agentTypingPayload(),
            'visitor_typing' => $this->conversation->visitorTypingPayload(),
        ];
    }
}
