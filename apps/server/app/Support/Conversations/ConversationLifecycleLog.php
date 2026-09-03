<?php

namespace App\Support\Conversations;

use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of a conversation opening and closing over time.
 *
 * `conversations.closed_at` is a current-state field wearing a history field's
 * name: it says when the close you are presently in began, and is nulled the
 * moment anything reopens. It cannot answer how long resolution took, or how
 * often a resolution did not hold, because the previous answer is destroyed
 * each time.
 *
 * So transitions are recorded as audit events instead, the way tickets already
 * record theirs (ADR 0015). Events keep the sequence; a column would keep only
 * a summary, and "closed twice, reopened by the visitor both times" is a
 * different story from a count.
 *
 * Nothing here can be backfilled, which is why it exists before the reporting
 * surface that will read it.
 */
class ConversationLifecycleLog
{
    public const CLOSED = 'conversation.closed';

    public const REOPENED = 'conversation.reopened';

    /**
     * Record a close, if it was one.
     *
     * A double-click, a retry, or a stale page submits close twice. Recording
     * both writes consecutive closes with no reopen between them, which
     * corrupts the close count and every interval derived from it. Only a
     * transition is an event -- the same rule reopens already follow.
     */
    public function closed(Conversation $conversation, ?Model $actor, string $previousStatus): void
    {
        if ($previousStatus === 'closed') {
            return;
        }

        $this->record($conversation, $actor, self::CLOSED, $previousStatus);
    }

    /**
     * Record a reopen, including the silent ones.
     *
     * An agent reply and a visitor message both flip a closed conversation back
     * to open. A visitor reopening one is arguably the most interesting event
     * in the product -- it means the resolution did not hold -- and until now it
     * left no trace distinguishable from any other message.
     */
    public function reopened(Conversation $conversation, ?Model $actor, string $previousStatus): void
    {
        $this->record($conversation, $actor, self::REOPENED, $previousStatus);
    }

    /**
     * Record a transition that a reply caused, if it caused one.
     *
     * Replying to an already-open conversation is not an event. Only the
     * transition is, which is what keeps this from writing a row per message.
     */
    public function replyReopenedIfClosed(Conversation $conversation, ?Model $actor, string $previousStatus): void
    {
        if ($previousStatus === 'closed') {
            $this->reopened($conversation, $actor, $previousStatus);
        }
    }

    private function record(Conversation $conversation, ?Model $actor, string $action, string $previousStatus): void
    {
        $conversation->auditEvents()->create([
            // Conversations carry no account_id of their own; the site is the
            // only route to one, which is also how every scoped query reaches
            // them.
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => [
                'previous_status' => $previousStatus,
                // Named so a reader can tell an agent closing a thread from a
                // visitor's reply dragging it back open, without resolving the
                // actor morph.
                'actor' => match (true) {
                    $actor instanceof Visitor => 'visitor',
                    $actor instanceof ApiToken => 'integration',
                    $actor === null => 'system',
                    default => 'agent',
                },
            ],
            'occurred_at' => now(),
        ]);
    }
}
