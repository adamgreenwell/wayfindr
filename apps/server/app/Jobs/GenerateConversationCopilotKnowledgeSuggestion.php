<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\ConversationKnowledgeSuggestionOutputParser;
use App\Support\Ai\ConversationKnowledgeSuggestionPromptBuilder;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\SharedAssistAudit;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Usage\AiUsageLedger;
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

    private const PURPOSE = 'conversation_knowledge_suggestion';

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
        AgentCopilotConnectionResolver $connections,
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

        $usageArtifact = $suggestion;
        $result = null;

        if (! $this->policyIsCurrent($suggestion)) {
            $this->cancelBeforeProvider($suggestion);
            $this->recordFailure($suggestion, 'policy_changed', $result);

            return;
        }

        $conversation = $suggestion->conversation;
        $requester = $suggestion->requestedBy;
        $account = $conversation?->site?->account;

        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
            $this->cancelBeforeProvider($suggestion);
            $this->recordFailure($suggestion, 'access_revoked', $result);

            return;
        }

        if ($account === null || ! $account->articles()->published()->exists()) {
            $this->cancelBeforeProvider($suggestion);
            $this->recordFailure($suggestion, 'no_articles', $result);

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'no_messages', $result);

                return;
            }

            $claimed = is_string($suggestion->preparation_fingerprint)
                ? app(AiSupportUsage::class)->claimPrepared($suggestion)
                : ConversationCopilotKnowledgeSuggestion::query()
                    ->whereKey($suggestion->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_PENDING)
                    ->update([
                        'status' => ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING,
                        'started_at' => AiSupportUsage::lifecycleNow($suggestion),
                    ]);

            if ($claimed !== 1) {
                return;
            }

            $suggestion = $this->runningSuggestion();
            $conversation = $suggestion?->conversation;
            $requester = $suggestion?->requestedBy;

            if ($suggestion === null || $conversation === null || $requester === null) {
                $this->cancelBeforeProvider($usageArtifact);

                return;
            }

            if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'access_revoked', $result);

                return;
            }

            if (! $this->policyIsCurrent($suggestion)) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'policy_changed', $result);

                return;
            }

            $account = $conversation->site?->account;

            if ($account === null || ! $account->articles()->published()->exists()) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'no_articles', $result);

                return;
            }

            $connection = $connections->forAccount((int) $conversation->site->account_id);
            if ($connection === null || $suggestion->connection_revision !== $connection->revision) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'connection_changed', $result);

                return;
            }

            if (! $this->policyIsCurrent($suggestion)) {
                $this->cancelBeforeProvider($suggestion);
                $this->recordFailure($suggestion, 'policy_changed', $result);

                return;
            }
            $sourceFingerprint = AiSupportUsage::sourceFingerprint($suggestion, $context->prompt);
            $result = $provider->generate($context->prompt->withExecution($connection, app(AiSupportUsage::class)->contextFor($suggestion)));
            $output = $outputParser->parse($result->text);

            if ($output === null) {
                $result->settle('invalid_output');
                $this->recordFailure($suggestion, 'invalid_output', $result);

                return;
            }

            app(AiSupportUsage::class)->publish($usageArtifact, $result, function () use ($articleMatcher, $connections, $context, $output, $result, $sourceFingerprint): void {
                // Re-read authority and publication state after the provider call.
                // The external wait must never become an authorization cache.
                $suggestion = $this->runningSuggestion();
                $conversation = $suggestion?->conversation;
                $requester = $suggestion?->requestedBy;

                if ($suggestion === null || $conversation === null || $requester === null) {
                    return;
                }

                if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                    $this->recordFailure($suggestion, 'access_revoked', $result);

                    return;
                }

                if (! $this->policyIsCurrent($suggestion)) {
                    $this->recordFailure($suggestion, 'policy_changed', $result);

                    return;
                }

                $account = $conversation->site?->account;

                if ($account === null || ! $account->articles()->published()->exists()) {
                    $this->recordFailure($suggestion, 'no_articles', $result);

                    return;
                }

                $articleIds = $articleMatcher->match($account, $output->queries);

                DB::transaction(function () use ($articleIds, $context, $result, $suggestion, $connections, $sourceFingerprint): void {
                    $accountId = (int) $suggestion->conversation->site->account_id;
                    if (! Account::query()->whereKey($accountId)->lockForUpdate()->first()) {
                        return;
                    }

                    $suggestion = ConversationCopilotKnowledgeSuggestion::query()
                        ->with(['conversation.site.account', 'requestedBy'])
                        ->whereKey($suggestion->id)
                        ->where('generation', $this->generation)
                        ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING)
                        ->lockForUpdate()
                        ->first();

                    if ($suggestion === null) {
                        return;
                    }

                    if (! $this->policyIsCurrent($suggestion)) {
                        $this->recordFailure($suggestion, 'policy_changed', $result);

                        return;
                    }

                    $conversation = $suggestion->conversation;
                    $requester = $suggestion->requestedBy;
                    if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
                        $this->recordFailure($suggestion, 'access_revoked', $result);

                        return;
                    }

                    if (! $connections->isCurrent($accountId, $suggestion->connection_revision)) {
                        $this->recordFailure($suggestion, 'connection_changed', $result);

                        return;
                    }

                    if (! $conversation->site->account->articles()->published()->exists()) {
                        $this->recordFailure($suggestion, 'no_articles', $result);

                        return;
                    }

                    $updated = ConversationCopilotKnowledgeSuggestion::query()
                        ->whereKey($suggestion->id)
                        ->where('generation', $this->generation)
                        ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_RUNNING)
                        ->update([
                            'status' => ConversationCopilotKnowledgeSuggestion::STATUS_READY,
                            'article_ids' => $articleIds,
                            'source_context_fingerprint' => $sourceFingerprint,
                            'source_last_message_id' => $context->lastMessageId,
                            'source_message_count' => $context->messageCount,
                            'provider' => mb_substr($result->provider, 0, 64),
                            'model' => mb_substr($result->model, 0, 255),
                            'prompt_tokens' => $result->promptTokens === null ? null : max(0, $result->promptTokens),
                            'completion_tokens' => $result->completionTokens === null ? null : max(0, $result->completionTokens),
                            'failure_code' => null,
                            'completed_at' => AiSupportUsage::lifecycleNow($suggestion),
                        ]);

                    if ($updated === 1) {
                        $this->audit($suggestion, 'conversation.ai_knowledge_suggestion.generated', [
                            'source_message_count' => $context->messageCount,
                            'suggested_article_count' => count($articleIds),
                            'provider' => mb_substr($result->provider, 0, 64),
                            'model' => mb_substr($result->model, 0, 255),
                            'prompt_tokens' => $result->promptTokens === null ? null : max(0, $result->promptTokens),
                            'completion_tokens' => $result->completionTokens === null ? null : max(0, $result->completionTokens),
                        ], $result);
                    }
                });
            });
        } catch (Throwable $exception) {
            try {
                $result?->settle('invalid_output');
            } catch (Throwable) {
                // Withhold output even when final usage persistence is unavailable.
            }
            if ($result === null) {
                $this->cancelBeforeProvider($usageArtifact);
            }
            Log::warning('Conversation copilot knowledge suggestion generation failed.', [
                'exception_type' => $exception::class,
                'suggestion_id' => $suggestion->id,
                'conversation_id' => $conversation->id,
            ]);
            $code = is_string($suggestion->preparation_fingerprint) && $exception instanceof AiNetworkException
                && in_array($exception->reason, ['connection_changed', 'policy_changed', 'access_revoked', 'context_changed', 'ticket_exists'], true)
                ? $exception->reason : 'provider';
            $this->recordFailure($suggestion, $code, $result);
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

    private function policyIsCurrent(ConversationCopilotKnowledgeSuggestion $suggestion): bool
    {
        try {
            return $suggestion->conversation !== null && app(AiAssistPolicyResolver::class)->isCurrent((int) $suggestion->conversation->site_id, self::PURPOSE, $suggestion->policy_revision);
        } catch (Throwable) {
            return false;
        }
    }

    private function cancelBeforeProvider(ConversationCopilotKnowledgeSuggestion $suggestion): void
    {
        try {
            app(AiSupportUsage::class)->cancelFor($suggestion, 'known_unsent');
        } catch (Throwable) {
            // Bounded expiry recovers an uncertain reservation release.
        }
    }

    private function recordFailure(ConversationCopilotKnowledgeSuggestion $suggestion, string $code, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(AiUsageLedger::class)->withAccountUsageLock((int) $suggestion->conversation?->site?->account_id, function () use ($code, $suggestion, $result): void {
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
                    'completed_at' => AiSupportUsage::lifecycleNow($suggestion),
                ]);

            if ($updated === 1) {
                app(AiAssistPreparation::class)->syncTerminal($suggestion);
                $this->audit($suggestion, 'conversation.ai_knowledge_suggestion.failed', [
                    'failure_code' => $code,
                ], $result);
            }
        });
    }

    /** @param array<string, int|string|null> $metadata */
    private function audit(#[\SensitiveParameter] ConversationCopilotKnowledgeSuggestion $suggestion, string $action, #[\SensitiveParameter] array $metadata, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(SharedAssistAudit::class)->record($suggestion, $action, $metadata, result: $result);
    }
}
