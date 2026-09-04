<?php

namespace App\Support\Conversations;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Model;

final class ConversationPriorityLog
{
    public const UPDATED = 'conversation.priority_updated';

    /** @param array<string, mixed> $metadata */
    public function updated(
        Conversation $conversation,
        ?Model $actor,
        string $previousPriority,
        string $newPriority,
        string $source,
        array $metadata = [],
    ): void {
        if ($previousPriority === $newPriority) {
            return;
        }

        $conversation->auditEvents()->create([
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => self::UPDATED,
            'metadata' => [
                ...$metadata,
                'previous_priority' => $previousPriority,
                'new_priority' => $newPriority,
                'source' => $source,
            ],
            'occurred_at' => now(),
        ]);
    }
}
