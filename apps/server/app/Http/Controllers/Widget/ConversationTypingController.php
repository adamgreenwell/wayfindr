<?php

namespace App\Http\Controllers\Widget;

use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationTypingUpdated;
use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorConversationWriteAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationTypingController extends Controller
{
    public function __invoke(
        Request $request,
        string $supportCode,
        VisitorConversationResolver $conversations,
        VisitorConversationWriteAuthorization $conversationWrites,
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'is_typing' => ['required', 'boolean'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        $conversation = DB::transaction(function () use ($conversation, $conversationWrites, $validated) {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $visitor = $conversation->visitor;
            abort_unless($visitor instanceof Visitor, 404, 'Conversation not found.');
            $visitor->forceFill(['last_web_seen_at' => now()])->save();

            $metadata = $conversation->metadata ?? [];

            if ((bool) $validated['is_typing']) {
                $metadata['visitor_typing_at'] = now()->toJSON();
            } else {
                unset($metadata['visitor_typing_at']);
            }

            $conversation->forceFill(['metadata' => $metadata])->save();
            $conversation->refresh();
            $conversation->load('visitor');

            return $conversation;
        });

        event(new ConversationPresenceUpdated($conversation));
        event(new ConversationTypingUpdated($conversation));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'typing' => $conversation->visitorTypingPayload(),
                'visitor_presence' => $conversation->visitorPresencePayload(),
            ],
        ]);
    }
}
