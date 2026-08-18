<?php

namespace App\Events;

use App\Events\Concerns\NotBroadcastForArchivedSites;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationReadReceiptUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use NotBroadcastForArchivedSites;
    use SerializesModels;

    public function __construct(public Conversation $conversation)
    {
        $this->conversation->loadMissing('latestAgentMessage');
    }

    protected function broadcastSite(): ?Site
    {
        return $this->conversation->site;
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
        return 'conversation.read.updated';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, visitor_read: array{message_id: int|null, state: string, label: string, detail: string, seen_at: string|null, seen_label: string|null}}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->conversation->support_code,
                'status' => $this->conversation->status,
            ],
            'visitor_read' => $this->conversation->visitorReadPayload(),
        ];
    }
}
