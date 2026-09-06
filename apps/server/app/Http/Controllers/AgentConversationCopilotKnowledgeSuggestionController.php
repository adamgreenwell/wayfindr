<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\User;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Queue and render agent-reviewed published-article suggestions. */
final class AgentConversationCopilotKnowledgeSuggestionController extends Controller
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
        abort_unless($conversation->site->account->articles()->published()->exists(), 404);
        abort_unless($conversation->messages()->whereNotNull('body')->whereRaw("TRIM(body) <> ''")->exists(), 404);

        [$suggestion, $shouldDispatch] = DB::transaction(function () use ($agent, $conversation): array {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if (! $conversation->site->account->articles()->published()->exists()) {
                abort(404);
            }

            $suggestion = ConversationCopilotKnowledgeSuggestion::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($suggestion?->hasFreshPendingRequest()) {
                return [$suggestion, false];
            }

            $generation = (string) Str::uuid();
            $values = [
                'requested_by_id' => $agent->id,
                'generation' => $generation,
                'status' => ConversationCopilotKnowledgeSuggestion::STATUS_PENDING,
                'article_ids' => null,
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

            if ($suggestion === null) {
                $suggestion = ConversationCopilotKnowledgeSuggestion::query()->create([
                    'conversation_id' => $conversation->id,
                    ...$values,
                ]);
            } else {
                $suggestion->forceFill($values)->save();
            }

            AuditEvent::query()->create([
                'account_id' => $conversation->site->account_id,
                'site_id' => $conversation->site_id,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'subject_type' => $conversation->getMorphClass(),
                'subject_id' => $conversation->id,
                'action' => 'conversation.ai_knowledge_suggestion.requested',
                'metadata' => [
                    'suggestion_id' => $suggestion->id,
                    'generation' => $suggestion->generation,
                ],
                'occurred_at' => now(),
            ]);

            return [$suggestion, true];
        });

        if ($shouldDispatch) {
            try {
                GenerateConversationCopilotKnowledgeSuggestion::dispatch($suggestion->id, $suggestion->generation);
            } catch (Throwable $exception) {
                Log::warning('Conversation copilot knowledge suggestion could not be queued.', [
                    'exception_type' => $exception::class,
                    'suggestion_id' => $suggestion->id,
                    'conversation_id' => $conversation->id,
                ]);
                DB::transaction(function () use ($agent, $conversation, $suggestion): void {
                    $markedFailed = ConversationCopilotKnowledgeSuggestion::query()
                        ->whereKey($suggestion->id)
                        ->where('generation', $suggestion->generation)
                        ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_PENDING)
                        ->update([
                            'status' => ConversationCopilotKnowledgeSuggestion::STATUS_FAILED,
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
                            'action' => 'conversation.ai_knowledge_suggestion.failed',
                            'metadata' => [
                                'suggestion_id' => $suggestion->id,
                                'generation' => $suggestion->generation,
                                'failure_code' => 'queue',
                            ],
                            'occurred_at' => now(),
                        ]);
                    }
                });

                return redirect()
                    ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
                    ->with('status', 'conversations.flash.ai_knowledge_suggestion_failed');
            }
        }

        return redirect()
            ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
            ->with('status', $shouldDispatch
                ? 'conversations.flash.ai_knowledge_suggestion_queued'
                : 'conversations.flash.ai_knowledge_suggestion_pending');
    }

    public function show(
        Request $request,
        string $supportCode,
        AgentCopilotConfiguration $configuration,
        ConversationReturnPath $returnPath,
        KnowledgeArticleSuggestionMatcher $articleMatcher,
    ): Response {
        abort_unless($configuration->isReady(), 404);

        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        $account = $conversation->site->account;

        if (! $account->articles()->published()->exists()) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $latestTextMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestTextMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $latestConversationMessageId = $conversation->messages()->max('id');
        $suggestion = $conversation->copilotKnowledgeSuggestion()->first();
        $suggestedKnowledgeArticles = $suggestion?->status === ConversationCopilotKnowledgeSuggestion::STATUS_READY
            ? $articleMatcher->present($account, $suggestion->suggestedArticleIds())
            : collect();

        return response()->view('agent.conversations.partials.copilot-knowledge-suggestion', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotKnowledgeSuggestion' => $suggestion,
            'latestConversationMessageId' => (int) $latestConversationMessageId,
            'suggestedKnowledgeArticles' => $suggestedKnowledgeArticles,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function conversationForAgent(User $agent, string $supportCode): Conversation
    {
        abort_unless($agent->account_id, 403);

        $conversation = Conversation::query()
            ->with('site.account')
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        return $conversation;
    }
}
