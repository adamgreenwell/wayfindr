<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationMessageCreated;
use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationReadReceiptUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function index(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'mark_seen' => ['nullable', 'boolean'],
            'seen_message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $conversation = $this->conversationForVisitor(
            $request,
            $visitorSessionToken,
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

    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $conversation = $this->conversationForVisitor(
            $request,
            $visitorSessionToken,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );
        $this->recordVisitorPresence($conversation);

        $message = $conversation->messages()->create([
            'sender_type' => Visitor::class,
            'sender_id' => $conversation->visitor_id,
            'type' => 'text',
            'body' => $validated['body'],
            'metadata' => [],
        ]);

        $conversation->forceFill([
            'status' => 'open',
            'closed_at' => null,
            'last_message_at' => $message->created_at,
        ])->save();

        event(new ConversationMessageCreated($message));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'message' => [
                    'type' => $message->type,
                    'body' => $message->body,
                    'created_at' => $message->created_at?->toJSON(),
                ],
                'visitor_presence' => $conversation->visitorPresencePayload(),
            ],
        ], 201);
    }

    private function conversationForVisitor(
        Request $request,
        VisitorSessionToken $visitorSessionToken,
        string $supportCode,
        string $sitePublicKey,
        string $anonymousId
    ): Conversation {
        $site = Site::query()
            ->where('public_key', $sitePublicKey)
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $anonymousId);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_unless($conversation, 404, 'Conversation not found.');

        return $conversation;
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
