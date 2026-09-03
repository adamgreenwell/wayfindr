<?php

namespace App\Support\Api\V1;

use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * The shapes of the v1 contract, in one file (ADR 0018).
 *
 * Every response body is built here rather than in the controller that serves
 * it, because a public contract is a thing you can only change once. Spread
 * across controllers, "what does a conversation look like over the API" becomes
 * a question you answer by reading four files and hoping they agree.
 *
 * Two rules hold throughout:
 *
 * - **Nothing is exposed by accident.** Fields are listed, never spread from a
 *   model. `$model->toArray()` would publish whatever a future migration adds,
 *   which for these tables means publishing support data nobody decided to
 *   publish.
 * - **`metadata` stays private.** It is a free-form column written by the
 *   widget, the SDK and the host page, so its contents are whatever somebody's
 *   website put there. Publishing it would export data the operator never chose
 *   to expose.
 */
final class Payload
{
    /**
     * @return array<string, mixed>
     */
    public static function conversation(Conversation $conversation): array
    {
        return [
            // The support code, not the id. It is the identifier the product
            // already uses with people, it is stable, and it does not tell a
            // reader how many conversations the install has.
            'support_code' => $conversation->support_code,
            'site_id' => (int) $conversation->site_id,
            'visitor_id' => (int) $conversation->visitor_id,
            'assigned_agent_id' => $conversation->assigned_agent_id === null ? null : (int) $conversation->assigned_agent_id,
            'status' => (string) $conversation->status,
            'subject' => $conversation->subject,
            'last_message_at' => $conversation->last_message_at?->toJSON(),
            'closed_at' => $conversation->closed_at?->toJSON(),
            'created_at' => $conversation->created_at?->toJSON(),
            'updated_at' => $conversation->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(ConversationMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            // Which side said it, without publishing the internal class name.
            'sender' => self::senderKind($message),
            'sender_id' => $message->sender_id === null ? null : (int) $message->sender_id,
            'type' => (string) $message->type,
            'body' => (string) $message->body,
            'created_at' => $message->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'site_id' => (int) $ticket->site_id,
            'conversation_id' => $ticket->conversation_id === null ? null : (int) $ticket->conversation_id,
            'requester_id' => $ticket->requester_id === null ? null : (int) $ticket->requester_id,
            'assignee_id' => $ticket->assignee_id === null ? null : (int) $ticket->assignee_id,
            'status' => (string) $ticket->status,
            'priority' => (string) $ticket->priority,
            'subject' => (string) $ticket->subject,
            'description' => $ticket->description,
            'closed_at' => $ticket->closed_at?->toJSON(),
            'created_at' => $ticket->created_at?->toJSON(),
            'updated_at' => $ticket->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function visitor(Visitor $visitor): array
    {
        return [
            'id' => (int) $visitor->id,
            'site_id' => (int) $visitor->site_id,
            // The host application's own identifier, which is the one an
            // integration can actually join on.
            'external_id' => $visitor->external_id,
            'name' => $visitor->name,
            'email' => $visitor->email,
            'last_seen_at' => $visitor->last_seen_at?->toJSON(),
            'created_at' => $visitor->created_at?->toJSON(),
        ];
        // Deliberately no anonymous_id: it is the widget's browser-session
        // handle, and publishing it would hand a caller the key half of a
        // visitor session rather than a way to identify somebody.
    }

    /**
     * The receipt for opening a conversation with a write-only token.
     *
     * @param  array{site_id: int, visitor_id: int, subject?: string|null}  $input
     * @return array<string, mixed>
     */
    public static function createdConversation(Conversation $conversation, array $input): array
    {
        return [
            'support_code' => $conversation->support_code,
            'site_id' => (int) $input['site_id'],
            'visitor_id' => (int) $input['visitor_id'],
            'status' => 'open',
            'subject' => $input['subject'] ?? null,
        ];
    }

    /**
     * The receipt for a support-side integration message.
     *
     * Deliberately not `conversation()`: write does not imply read, and the
     * token supplied the only content returned here itself.
     *
     * @return array<string, mixed>
     */
    public static function createdMessage(ConversationMessage $message): array
    {
        return [
            'conversation' => [
                'support_code' => $message->conversation->support_code,
                'status' => 'open',
            ],
            'message' => self::message($message),
        ];
    }

    /**
     * @param  array{site_id: int, requester_id?: int|null, subject: string, description?: string|null, priority?: string|null}  $input
     * @return array<string, mixed>
     */
    public static function createdTicket(Ticket $ticket, array $input): array
    {
        return [
            'id' => (int) $ticket->id,
            'site_id' => (int) $input['site_id'],
            'requester_id' => isset($input['requester_id']) ? (int) $input['requester_id'] : null,
            'status' => 'open',
            'priority' => $input['priority'] ?? 'normal',
            'subject' => $input['subject'],
            'description' => $input['description'] ?? null,
        ];
    }

    /**
     * @param  array{status?: string, assignee_id?: int|null}  $input
     * @return array<string, mixed>
     */
    public static function updatedTicket(Ticket $ticket, array $input): array
    {
        $data = ['id' => (int) $ticket->id];

        foreach (['status', 'assignee_id'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $data[$field] = $field === 'assignee_id'
                ? ($ticket->assignee_id === null ? null : (int) $ticket->assignee_id)
                : (string) $ticket->status;
        }

        return $data;
    }

    /**
     * A cursor-paginated list, in the one envelope every list endpoint uses.
     *
     * Cursor rather than page numbers, because a support inbox changes while
     * you are reading it: offset pagination silently skips and repeats rows as
     * new conversations arrive, and an integration walking pages would lose
     * some of them without any error to notice.
     *
     * @param  callable(mixed): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    public static function page(CursorPaginator $paginator, callable $present): array
    {
        return [
            'data' => collect($paginator->items())->map($present)->all(),
            'meta' => [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
            ],
        ];
    }

    private static function senderKind(ConversationMessage $message): string
    {
        return match ((string) $message->sender_type) {
            Visitor::class => 'visitor',
            User::class => 'agent',
            ApiToken::class => 'integration',
            default => 'system',
        };
    }
}
