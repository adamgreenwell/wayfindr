<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\User;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Queue and render the latest agent-reviewed reply suggestion for a conversation. */
final class AgentConversationCopilotReplyDraftController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        AgentCopilotConfiguration $configuration,
        ConversationReturnPath $returnPath,
    ): RedirectResponse {
        abort_unless($configuration->isReady(), 404);

        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode);
        abort_unless($conversation->messages()->whereNotNull('body')->whereRaw("TRIM(body) <> ''")->exists(), 404);

        [$draft, $shouldDispatch] = DB::transaction(function () use ($agent, $conversation): array {
            // The conversation lock serializes the first request, when no draft
            // row exists yet and therefore cannot itself be locked.
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $draft = ConversationCopilotReplyDraft::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($draft?->hasFreshPendingRequest()) {
                return [$draft, false];
            }

            $generation = (string) Str::uuid();
            $values = [
                'requested_by_id' => $agent->id,
                'generation' => $generation,
                'status' => ConversationCopilotReplyDraft::STATUS_PENDING,
                'draft' => null,
                'source_last_message_id' => null,
                'source_message_count' => 0,
                'provider' => null,
                'model' => null,
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'failure_code' => null,
                'requested_at' => now(),
                'started_at' => null,
                'completed_at' => null,
            ];

            if ($draft === null) {
                $draft = ConversationCopilotReplyDraft::query()->create([
                    'conversation_id' => $conversation->id,
                    ...$values,
                ]);
            } else {
                $draft->forceFill($values)->save();
            }

            AuditEvent::query()->create([
                'account_id' => $conversation->site->account_id,
                'site_id' => $conversation->site_id,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'subject_type' => $conversation->getMorphClass(),
                'subject_id' => $conversation->id,
                'action' => 'conversation.ai_reply_draft.requested',
                'metadata' => [
                    'draft_id' => $draft->id,
                    'generation' => $draft->generation,
                ],
                'occurred_at' => now(),
            ]);

            return [$draft, true];
        });

        if ($shouldDispatch) {
            try {
                GenerateConversationCopilotReplyDraft::dispatch($draft->id, $draft->generation);
            } catch (Throwable $exception) {
                Log::warning('Conversation copilot reply draft could not be queued.', [
                    'exception_type' => $exception::class,
                    'draft_id' => $draft->id,
                    'conversation_id' => $conversation->id,
                ]);
                DB::transaction(function () use ($agent, $conversation, $draft): void {
                    $markedFailed = ConversationCopilotReplyDraft::query()
                        ->whereKey($draft->id)
                        ->where('generation', $draft->generation)
                        ->where('status', ConversationCopilotReplyDraft::STATUS_PENDING)
                        ->update([
                            'status' => ConversationCopilotReplyDraft::STATUS_FAILED,
                            'failure_code' => 'queue',
                            'completed_at' => now(),
                        ]);

                    if ($markedFailed === 1) {
                        AuditEvent::query()->create([
                            'account_id' => $conversation->site->account_id,
                            'site_id' => $conversation->site_id,
                            'actor_type' => $agent->getMorphClass(),
                            'actor_id' => $agent->id,
                            'subject_type' => $conversation->getMorphClass(),
                            'subject_id' => $conversation->id,
                            'action' => 'conversation.ai_reply_draft.failed',
                            'metadata' => [
                                'draft_id' => $draft->id,
                                'generation' => $draft->generation,
                                'failure_code' => 'queue',
                            ],
                            'occurred_at' => now(),
                        ]);
                    }
                });

                return redirect()
                    ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
                    ->with('status', 'conversations.flash.ai_reply_draft_failed');
            }
        }

        return redirect()
            ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
            ->with('status', $shouldDispatch
                ? 'conversations.flash.ai_reply_draft_queued'
                : 'conversations.flash.ai_reply_draft_pending');
    }

    public function show(
        Request $request,
        string $supportCode,
        AgentCopilotConfiguration $configuration,
        ConversationReturnPath $returnPath,
    ): Response {
        abort_unless($configuration->isReady(), 404);

        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        $latestMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        return response()->view('agent.conversations.partials.copilot-reply-draft', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotReplyDraft' => $conversation->copilotReplyDraft()->first(),
            'latestSummarizableMessageId' => (int) $latestMessageId,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function conversationForAgent(User $agent, string $supportCode): Conversation
    {
        abort_unless($agent->account_id, 403);

        $conversation = Conversation::query()
            ->with('site')
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        return $conversation;
    }
}
