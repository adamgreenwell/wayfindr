<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationMessageCreated;
use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationReadReceiptUpdated;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentBinder;
use App\Support\Attachments\AttachmentRejected;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Sites\WidgetLanguage;
use App\Support\VisitorConversationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            ->with(['sender', 'attachments', 'conversation.site'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'sender' => $this->senderPayload($message),
                'type' => $message->type,
                'body' => $message->body,
                'attachments' => $message->attachments->map->toPayload()->all(),
                'created_at' => $message->created_at?->toJSON(),
            ]);

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                    // Whether this conversation is waiting for an answer: a
                    // RECORDED close that has not been rated. One field rather
                    // than two, because the widget's question is one question,
                    // and the two ways of answering no -- already rated, or no
                    // ratable close at all -- must both silence the prompt.
                    //
                    // The widget cannot work this out alone: its memory is lost
                    // on reload, so it would ask again about a close already
                    // rated, and it survives a genuine reopen, so it would stay
                    // silent about the next one.
                    'awaiting_rating' => $conversation->isAwaitingRating(),
                    // An opaque handle for WHICH close. The widget compares it
                    // for equality and clears its form when it changes, which
                    // is the only way to tell a new unanswered close from the
                    // previous unanswered one -- both report awaiting, so a
                    // draft would otherwise survive into different work.
                    'rating_episode' => $conversation->currentCloseEpisodeToken(),
                ],
                'messages' => $messages,
                'agent_typing' => $conversation->agentTypingPayload(),
                'visitor_read' => $conversation->visitorReadPayload(),
                'visitor_presence' => $conversation->visitorPresencePayload(),
            ],
        ]);
    }

    public function store(Request $request, string $supportCode, VisitorConversationResolver $conversations, AttachmentBinder $binder): JsonResponse
    {
        // Identity first: everything after this answers the visitor, and the
        // language they are owed comes from the site.
        $identity = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $identity['site_public_key'],
            $identity['anonymous_id'],
        );

        App::setLocale(WidgetLanguage::forVisitor($request->input('locale'), $conversation->site));

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'client_message_id' => ['nullable', 'string', 'max:128'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'min:1'],
        ]);
        $this->recordVisitorPresence($conversation);

        $body = trim((string) ($validated['body'] ?? ''));
        $attachmentIds = $validated['attachment_ids'] ?? [];

        // A message must carry something — text, a file, or both.
        if ($body === '' && $attachmentIds === []) {
            throw ValidationException::withMessages([
                'body' => 'Enter a message or attach a file.',
            ]);
        }

        $clientMessageId = $this->normalizeClientMessageId($validated['client_message_id'] ?? null);
        $visitor = $conversation->visitor;

        try {
            [$message, $created] = DB::transaction(function () use ($conversation, $body, $attachmentIds, $clientMessageId, $binder, $visitor) {
                // Lock the conversation row so the idempotency check and the insert
                // are atomic. Without this, two concurrent sends sharing a
                // client_message_id could both pass the lookup before either row is
                // visible and both create a message.
                // Keep the locked row. Reading status off the pre-lock instance means
                // two concurrent sends on a closed conversation both see "closed"
                // and both record a reopen, for one transition.
                $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

                if ($clientMessageId !== null) {
                    $existing = $conversation->messages()
                        ->where('sender_type', Visitor::class)
                        ->where('metadata->client_message_id', $clientMessageId)
                        ->first();

                    if ($existing) {
                        // Idempotent retry: the message (and any attachments bound to
                        // it on the first accepted send) already exists, so return it
                        // without creating a second row, re-binding, or re-broadcasting.
                        return [$existing, false];
                    }
                }

                $message = $conversation->messages()->create([
                    'sender_type' => Visitor::class,
                    'sender_id' => $conversation->visitor_id,
                    'type' => 'text',
                    'body' => $body === '' ? null : $body,
                    'metadata' => $clientMessageId !== null ? ['client_message_id' => $clientMessageId] : [],
                ]);

                // Bind the visitor's own pending uploads to this message. A bad
                // reference throws and rolls the whole send back.
                try {
                    $binder->bind($conversation, $message, $attachmentIds, $visitor);
                } catch (AttachmentRejected $rejected) {
                    // Answered in the site's language, not the install's, and
                    // carrying the key so a widget following the visitor's browser
                    // can say it in the language it is actually speaking.
                    //
                    // Still THROWN rather than returned: this runs inside the send
                    // transaction, and the throw is what rolls it back. The handler
                    // below turns it into the response.
                    throw $rejected;
                }

                $previousStatus = (string) ($locked?->status ?? $conversation->status);

                // Written through the LOCKED instance, exactly as the agent
                // transition path is. Eloquent compares against the attributes THIS
                // request read: a send that loaded "open", then waited behind an
                // agent's close, finds "open" unchanged and omits both status and
                // closed_at from the update -- leaving the row closed while the
                // call below records a reopen that never happened. A history that
                // reports transitions the database never made is worse than the
                // absence this PR set out to fix.
                $target = $locked ?? $conversation;

                $target->forceFill([
                    'status' => 'open',
                    'closed_at' => null,
                    'last_message_at' => $message->created_at,
                ])->save();

                // Keep the caller's instance honest: the response reports this
                // status back to the widget.
                $conversation->setRawAttributes($target->getAttributes(), true);

                // A visitor replying to a closed conversation is the reopen that
                // matters most: it means the resolution did not hold. It used to
                // leave no trace at all.
                app(ConversationLifecycleLog::class)
                    ->replyReopenedIfClosed($conversation, $visitor, $previousStatus);

                return [$message, true];
            });
        } catch (AttachmentRejected $rejected) {
            // Caught OUT here, where the transaction has already rolled back.
            // Returning from inside the closure would have committed the
            // half-written send.
            return $rejected->toWidgetResponse();
        }

        if ($created) {
            event(new ConversationMessageCreated($message));
        }

        return $this->storedMessageResponse($conversation, $message->load('attachments'));
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
                    'attachments' => $message->attachments->map->toPayload()->all(),
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
        // Saved through the model, not `visitor()->update()`. A relationship
        // update is a mass update: it dispatches no model events, so the
        // visit-boundary hook on Visitor never ran for this writer. The stock
        // widget calls refreshMessages() BEFORE bootstrap when a returning
        // visitor opens the panel, so this was frequently the first write of a
        // session -- it refreshed the sighting, bootstrap then saw a recent
        // timestamp and left `current_visit_started_at` alone, and the board
        // reported a visit still running from the previous session.
        $visitor = $conversation->visitor;

        if ($visitor !== null) {
            $visitor->forceFill(['last_web_seen_at' => now()])->save();
        }

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
                // The widget presents both people and API integrations on the
                // support side, so either can be the newest rendered boundary.
                // The update query above remains human-only: seeing an
                // automated message must not turn it into agent work.
                ->whereIn('sender_type', [User::class, ApiToken::class])
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

        if ($message->sender_type === ApiToken::class) {
            return [
                'kind' => 'agent',
                'name' => $message->conversation?->site?->name ?? 'Support',
            ];
        }

        return [
            'kind' => 'visitor',
            'name' => 'Visitor',
        ];
    }
}
