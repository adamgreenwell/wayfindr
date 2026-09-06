<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\ConversationKnowledgeSuggestionOutputParser;
use App\Support\Ai\ConversationKnowledgeSuggestionPromptBuilder;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Generate local article suggestions without placing support text in the queue. */
final class GenerateConversationCopilotKnowledgeSuggestion implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $suggestionId,
        private readonly string $generation,
    ) {}

    public function uniqueId(): string
    {
        return $this->suggestionId.':'.$this->generation;
    }

    public function handle(
        AgentCopilotProvider $provider,
        ConversationKnowledgeSuggestionPromptBuilder $promptBuilder,
        ConversationKnowledgeSuggestionOutputParser $outputParser,
        KnowledgeArticleSuggestionMatcher $articleMatcher,
    ): void {
        $suggestion = ConversationCopilotKnowledgeSuggestion::query()
            ->with(['conversation.site.account', 'requestedBy'])
            ->find($this->suggestionId);

        if (! $this->isCurrent($suggestion)) {
            return;
        }

        $conversation = $suggestion->conversation;
        $requester = $suggestion->requestedBy;
        $account = $conversation?->site?->account;

        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
            $this->recordFailure($suggestion, 'access_revoked');

            return;
        }

        if ($account === null || ! $account->articles()->published()->exists()) {
            $this->recordFailure($suggestion, 'no_articles');

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->recordFailure($suggestion, 'no_messages');

                return;
            }

            $claimed = ConversationCopilotKnowledgeSuggestion::query()
                ->whereKey($suggestion->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_PENDING)
                ->update([
                    'status' => ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING,
                    'started_at' => now(),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $suggestion = $this->runningSuggestion();
            $conversation = $suggestion?->conversation;
            $requester = $suggestion?->requestedBy;

            if ($suggestion === null || $conversation === null || $requester === null) {
                return;
            }

            if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                $this->recordFailure($suggestion, 'access_revoked');

                return;
            }

            $account = $conversation->site?->account;

            if ($account === null || ! $account->articles()->published()->exists()) {
                $this->recordFailure($suggestion, 'no_articles');

                return;
            }

            $result = $provider->generate($context->prompt);
            $output = $outputParser->parse($result->text);

            if ($output === null) {
                $this->recordFailure($suggestion, 'invalid_output');

                return;
            }

            // Re-read authority and publication state after the provider call.
            // The external wait must never become an authorization cache.
            $suggestion = $this->runningSuggestion();
            $conversation = $suggestion?->conversation;
            $requester = $suggestion?->requestedBy;

            if ($suggestion === null || $conversation === null || $requester === null) {
                return;
            }

            if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                $this->recordFailure($suggestion, 'access_revoked');

                return;
            }

            $account = $conversation->site?->account;

            if ($account === null || ! $account->articles()->published()->exists()) {
                $this->recordFailure($suggestion, 'no_articles');

                return;
            }

            $articleIds = $articleMatcher->match($account, $output->queries);

            DB::transaction(function () use ($articleIds, $context, $result, $suggestion): void {
                $updated = ConversationCopilotKnowledgeSuggestion::query()
                    ->whereKey($suggestion->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_READY,
                        'article_ids' => $articleIds,
                        'source_last_message_id' => $context->lastMessageId,
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => max(0, $result->promptTokens),
                        'completion_tokens' => max(0, $result->completionTokens),
                        'failure_code' => null,
                        'completed_at' => now(),
                    ]);

                if ($updated === 1) {
                    $this->audit($suggestion, 'conversation.ai_knowledge_suggestion.generated', [
                        'source_message_count' => $context->messageCount,
                        'suggested_article_count' => count($articleIds),
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => max(0, $result->promptTokens),
                        'completion_tokens' => max(0, $result->completionTokens),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Conversation copilot knowledge suggestion generation failed.', [
                'exception_type' => $exception::class,
                'suggestion_id' => $suggestion->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->recordFailure($suggestion, 'provider');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $suggestion = ConversationCopilotKnowledgeSuggestion::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->suggestionId);

        if (! $suggestion instanceof ConversationCopilotKnowledgeSuggestion) {
            return;
        }

        Log::warning('Conversation copilot knowledge suggestion job failed.', [
            'exception_type' => $exception === null ? null : $exception::class,
            'suggestion_id' => $suggestion->id,
            'conversation_id' => $suggestion->conversation_id,
        ]);
        $this->recordFailure($suggestion, 'job');
    }

    private function runningSuggestion(): ?ConversationCopilotKnowledgeSuggestion
    {
        return ConversationCopilotKnowledgeSuggestion::query()
            ->with(['conversation.site.account', 'requestedBy'])
            ->whereKey($this->suggestionId)
            ->where('generation', $this->generation)
            ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING)
            ->first();
    }

    private function isCurrent(?ConversationCopilotKnowledgeSuggestion $suggestion): bool
    {
        return $suggestion !== null
            && $suggestion->generation === $this->generation
            && $suggestion->status === ConversationCopilotKnowledgeSuggestion::STATUS_PENDING;
    }

    private function recordFailure(ConversationCopilotKnowledgeSuggestion $suggestion, string $code): void
    {
        DB::transaction(function () use ($code, $suggestion): void {
            $updated = ConversationCopilotKnowledgeSuggestion::query()
                ->whereKey($suggestion->id)
                ->where('generation', $this->generation)
                ->whereIn('status', [
                    ConversationCopilotKnowledgeSuggestion::STATUS_PENDING,
                    ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ConversationCopilotKnowledgeSuggestion::STATUS_FAILED,
                    'failure_code' => $code,
                    'completed_at' => now(),
                ]);

            if ($updated === 1) {
                $this->audit($suggestion, 'conversation.ai_knowledge_suggestion.failed', [
                    'failure_code' => $code,
                ]);
            }
        });
    }

    /** @param array<string, int|string> $metadata */
    private function audit(ConversationCopilotKnowledgeSuggestion $suggestion, string $action, array $metadata): void
    {
        $conversation = $suggestion->conversation;

        if ($conversation === null) {
            return;
        }

        AuditEvent::query()->create([
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $suggestion->requestedBy?->getMorphClass(),
            'actor_id' => $suggestion->requested_by_id,
            'subject_type' => $conversation->getMorphClass(),
            'subject_id' => $conversation->id,
            'action' => $action,
            'metadata' => [
                'suggestion_id' => $suggestion->id,
                'generation' => $this->generation,
                ...$metadata,
            ],
            'occurred_at' => now(),
        ]);
    }
}
