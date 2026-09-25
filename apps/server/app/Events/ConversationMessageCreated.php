<?php

namespace App\Events;

use App\Events\Concerns\NotBroadcastForArchivedSites;
use App\Models\ApiToken;
use App\Models\ConversationMessage;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldRescue because Laravel broadcasts a ShouldBroadcastNow event BEFORE it
 * runs the listeners. Without it an unreachable Reverb threw past the commit and
 * skipped NotifyAgentsOfVisitorMessage: the message was stored, nobody was
 * alerted, and the retry took the idempotent branch that never announces. The
 * live update is the only thing an outage may cost; the failure is still
 * reported. AgentAlertStored is deliberately NOT rescued -- its caller relies on
 * the throw to leave the claim open for a retry.
 */
class ConversationMessageCreated implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use NotBroadcastForArchivedSites;
    use SerializesModels;

    public function __construct(public ConversationMessage $message)
    {
        $this->message->loadMissing(['conversation', 'sender', 'attachments']);
    }

    protected function broadcastSite(): ?Site
    {
        return $this->message->conversation?->site;
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
     * @return array{kind: string, name: string, automated?: true}
     */
    private function senderPayload(): array
    {
        if ($this->message->sender_type === User::class) {
            return [
                'kind' => 'agent',
                'name' => $this->message->sender?->name ?? 'Agent',
            ];
        }

        if ($this->message->sender_type === ApiToken::class) {
            return [
                // Support-side to the visitor, but deliberately not called an
                // agent in storage or reporting. The site's own name is safe
                // visitor-facing copy; an internal token name is not.
                'kind' => 'agent',
                'name' => $this->message->conversation?->site?->name ?? 'Support',
            ];
        }

        if ($this->message->sender_type === ProactiveMessageRule::class) {
            return [
                'kind' => 'agent',
                'name' => $this->message->conversation?->site?->name ?? 'Support',
                // The same flag the widget's own transcript carries, so a live
                // delivery and the next poll describe the sender alike. Not on
                // the API-token branch above: an integration may relay a person.
                'automated' => true,
            ];
        }

        return [
            'kind' => 'visitor',
            'name' => 'Visitor',
        ];
    }
}
