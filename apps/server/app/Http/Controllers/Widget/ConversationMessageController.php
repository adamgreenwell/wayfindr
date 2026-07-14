<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationMessageCreated;
use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationReadReceiptUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationMessageController extends Controller
{
    public function index(Request $request, string $supportCode, VisitorConversationResolver $conversations): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'mark_seen' => ['nullable', 'boolean'],
            'seen_message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );
        $this->recordVisitorPresence($conversation);

        if ((bool) ($validated['mark_seen'] ?? false) && $this->markAgentMessagesSeen($conversation, $validated['seen_message_id'] ?? null)) {
            event(new ConversationReadReceiptUpdated($conversation->load('latestAgentMessage')));
        }

        $messages = $conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'sender' => $this->senderPayload($message),
                'type' => $message->type,
                'body' => $message->body,
                'created_at' => $message->created_at?->toJSON(),
            ]);

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'messages' => $messages,
                'agent_typing' => $conversation->agentTypingPayload(),
                'visitor_read' => $conversation->visitorReadPayload(),
                'visitor_presence' => $conversation->visitorPresencePayload(),
            ],
        ]);
    }

    public function store(Request $request, string $supportCode, VisitorConversationResolver $conversations): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'body' => ['required', 'string', 'max:4000'],
            'client_message_id' => ['nullable', 'string', 'max:128'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );
        $this->recordVisitorPresence($conversation);

        $clientMessageId = $this->normalizeClientMessageId($validated['client_message_id'] ?? null);

        [$message, $created] = DB::transaction(function () use ($conversation, $validated, $clientMessageId) {
            // Lock the conversation row so the idempotency check and the insert
            // are atomic. Without this, two concurrent sends sharing a
            // client_message_id could both pass the lookup before either row is
            // visible and both create a message.
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            if ($clientMessageId !== null) {
                $existing = $conversation->messages()
                    ->where('sender_type', Visitor::class)
                    ->where('metadata->client_message_id', $clientMessageId)
                    ->first();

                if ($existing) {
                    // Idempotent retry: the message was already accepted, so
                    // return it without creating a second row or re-broadcasting.
                    return [$existing, false];
                }
            }

            $message = $conversation->messages()->create([
                'sender_type' => Visitor::class,
                'sender_id' => $conversation->visitor_id,
                'type' => 'text',
                'body' => $validated['body'],
                'metadata' => $clientMessageId !== null ? ['client_message_id' => $clientMessageId] : [],
            ]);

            $conversation->forceFill([
                'status' => 'open',
                'closed_at' => null,
                'last_message_at' => $message->created_at,
            ])->save();

            return [$message, true];
        });

        if ($created) {
            event(new ConversationMessageCreated($message));
        }

        return $this->storedMessageResponse($conversation, $message);
    }

    private function storedMessageResponse(Conversation $conversation, ConversationMessage $message): JsonResponse
    {
        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'message' => [
                    'id' => $message->id,
                    'sender' => [
                        'kind' => 'visitor',
                        'name' => 'Visitor',
                    ],
                    'type' => $message->type,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toJSON(),
                ],
                'visitor_presence' => $conversation->visitorPresencePayload(),
            ],
        ], 201);
    }

    private function normalizeClientMessageId(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function recordVisitorPresence(Conversation $conversation): void
    {
        $conversation->visitor()->update(['last_seen_at' => now()]);
        $conversation->load('visitor');

        event(new ConversationPresenceUpdated($conversation));
    }

    private function markAgentMessagesSeen(Conversation $conversation, ?int $seenMessageId = null): bool
    {
        $query = $conversation->messages()
            ->where('sender_type', User::class)
            ->whereNull('seen_at');

        if ($seenMessageId) {
            $seenMessage = $conversation->messages()
                ->whereKey($seenMessageId)
                ->where('sender_type', User::class)
                ->first();

            if (! $seenMessage) {
                return false;
            }

            $query->where(function ($query) use ($seenMessage): void {
                $query
                    ->where('created_at', '<', $seenMessage->created_at)
                    ->orWhere(function ($query) use ($seenMessage): void {
                        $query
                            ->where('created_at', $seenMessage->created_at)
                            ->where('id', '<=', $seenMessage->id);
                    });
            });
        }

        return $query->update(['seen_at' => now()]) > 0;
    }

    private function senderPayload($message): array
    {
        if ($message->sender_type === User::class) {
            return [
                'kind' => 'agent',
                'name' => $message->sender?->name ?? 'Agent',
            ];
        }

        return [
            'kind' => 'visitor',
            'name' => 'Visitor',
        ];
    }
}
