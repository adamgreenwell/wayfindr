<?php

namespace App\Http\Controllers;

use App\Events\ConversationTypingUpdated;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Conversations\ConversationWriteAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AgentConversationTypingController extends Controller
{
    public function __construct(
        private readonly ConversationWriteAuthorization $conversationWriteAuthorization,
    ) {}

    public function __invoke(Request $request, string $supportCode): JsonResponse
    {
        /** @var User $agent */
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $validated = $request->validate([
            'is_typing' => ['required', 'boolean'],
        ]);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        [$conversation, $agent] = DB::transaction(function () use ($agent, $conversation, $validated): array {
            [$agent, $conversation] = $this->conversationWriteAuthorization->lock($agent, $conversation, 'reply');
            $metadata = $conversation->metadata ?? [];
            $typingSignals = $metadata['agent_typing'] ?? [];

            if (! is_array($typingSignals)) {
                $typingSignals = [];
            }

            if ((bool) $validated['is_typing']) {
                $typingSignals[(string) $agent->id] = [
                    'at' => now()->toJSON(),
                    'name' => $agent->name,
                ];
            } else {
                unset($typingSignals[(string) $agent->id]);
            }

            if ($typingSignals === []) {
                unset($metadata['agent_typing']);
            } else {
                $metadata['agent_typing'] = $typingSignals;
            }

            $conversation->forceFill(['metadata' => $metadata])->save();

            return [$conversation, $agent];
        });

        event(new ConversationTypingUpdated($conversation));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                    'status' => $conversation->status,
                ],
                'agent_typing' => $conversation->agentTypingPayload(),
            ],
        ]);
    }
}
