<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Models\ConversationCopilotReplyDraft;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\ConversationReplyDraftPromptBuilder;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Grounding\ConversationGroundedReplyGuard;
use App\Support\Ai\Grounding\ConversationGroundingSelector;
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

/** Generate one requested draft without placing support text in the queue payload. */
final class GenerateConversationCopilotReplyDraft implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PURPOSE = 'conversation_reply_draft';

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $draftId,
        private readonly string $generation,
    ) {}

    public function uniqueId(): string
    {
        return $this->draftId.':'.$this->generation;
    }

    public function handle(
        AgentCopilotProvider $provider,
        AgentCopilotConnectionResolver $connections,
        ConversationReplyDraftPromptBuilder $promptBuilder,
    ): void {
        $draft = ConversationCopilotReplyDraft::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->draftId);

        if (! $this->isCurrent($draft)) {
            return;
        }

        $usageArtifact = $draft;
        $result = null;

        if (! $this->policyIsCurrent($draft)) {
            $this->cancelBeforeProvider($draft);
            $this->recordFailure($draft, 'policy_changed', $result);

            return;
        }

        $conversation = $draft->conversation;
        $requester = $draft->requestedBy;

        // Reply permission, not merely transcript visibility, is required at
        // execution time because the output is intended for the composer.
        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
            $this->cancelBeforeProvider($draft);
            $this->recordFailure($draft, 'access_revoked', $result);

            return;
        }

        try {
            $context = $promptBuilder->build($conversation, $requester);

            if ($context === null) {
                $this->cancelBeforeProvider($draft);
                $this->recordFailure($draft, 'no_messages', $result);

                return;
            }

            $claimed = is_string($draft->preparation_fingerprint)
                ? app(AiSupportUsage::class)->claimPrepared($draft)
                : ConversationCopilotReplyDraft::query()
                    ->whereKey($draft->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotReplyDraft::STATUS_PENDING)
                    ->update([
                        'status' => ConversationCopilotReplyDraft::STATUS_RUNNING,
                        'started_at' => AiSupportUsage::lifecycleNow($draft),
                    ]);

            if ($claimed !== 1) {
                return;
            }

            // Do not let relations loaded before context selection become an
            // authorization cache while the provider request is waiting.
            $draft = ConversationCopilotReplyDraft::query()
                ->with(['conversation.site', 'requestedBy'])
                ->whereKey($draft->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotReplyDraft::STATUS_RUNNING)
                ->first();
            $conversation = $draft?->conversation;
            $requester = $draft?->requestedBy;

            if ($draft === null || $conversation === null || $requester === null) {
                $this->cancelBeforeProvider($usageArtifact);

                return;
            }

            if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                $this->cancelBeforeProvider($draft);
                $this->recordFailure($draft, 'access_revoked', $result);

                return;
            }

            $connection = $connections->forAccount((int) $conversation->site->account_id);
            if ($connection === null || $draft->connection_revision !== $connection->revision) {
                $this->cancelBeforeProvider($draft);
                $this->recordFailure($draft, 'connection_changed', $result);

                return;
            }

            if (! $this->policyIsCurrent($draft)) {
                $this->cancelBeforeProvider($draft);
                $this->recordFailure($draft, 'policy_changed', $result);

                return;
            }
            $sourceFingerprint = AiSupportUsage::sourceFingerprint($draft, $context->prompt);
            $result = $provider->generate($context->prompt->withExecution($connection, app(AiSupportUsage::class)->contextFor($draft)));
            $grounding = null;
            if ($context->groundingEnabled) {
                $proposal = app(ConversationGroundedReplyGuard::class)->propose($result->text, $context);
                if ($proposal === null) {
                    $result->settle('invalid_output');
                    $this->recordFailure($draft, 'invalid_output', $result);

                    return;
                }
                $output = $proposal->reply;
                $grounding = ['version' => ConversationGroundedReplyGuard::VERSION,
                    'sources' => $context->groundingSources, 'snapshot' => $context->groundingSnapshot,
                    'proposal' => ['decision' => $proposal->decision, 'citations' => $proposal->citations,
                        'limitation' => $proposal->limitation, 'missing_information' => $proposal->missingInformation],
                    'prompt_identity' => hash('sha256', $context->prompt->instructions),
                    'scorer_identity' => app(ConversationGroundedReplyGuard::class)->identity()];
            } else {
                $output = mb_substr(trim($result->text), 0, 6_000);
            }

            if ($output === '') {
                $result->settle('invalid_output');
                $this->recordFailure($draft, 'empty_output', $result);

                return;
            }

            app(AiSupportUsage::class)->publish($draft, $result, fn () => DB::transaction(function () use ($context, $draft, $output, $result, $connections, $sourceFingerprint, $grounding): void {
                $accountId = (int) $draft->conversation->site->account_id;
                if (! Account::query()->whereKey($accountId)->lockForUpdate()->first()) {
                    return;
                }

                $draft = ConversationCopilotReplyDraft::query()
                    ->with(['conversation.site.account', 'requestedBy'])
                    ->whereKey($draft->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotReplyDraft::STATUS_RUNNING)
                    ->lockForUpdate()
                    ->first();

                if ($draft === null) {
                    return;
                }

                if (! $this->policyIsCurrent($draft)) {
                    $this->recordFailure($draft, 'policy_changed', $result);

                    return;
                }

                $conversation = $draft->conversation;
                $requester = $draft->requestedBy;
                if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
                    $this->recordFailure($draft, 'access_revoked', $result);

                    return;
                }

                if (! $connections->isCurrent($accountId, $draft->connection_revision)) {
                    $this->recordFailure($draft, 'connection_changed', $result);

                    return;
                }

                if ($grounding !== null && ! app(ConversationGroundingSelector::class)->payloadStillCurrent(
                    $requester, $conversation, $context->groundingSnapshot, $context->groundingSources)) {
                    $this->recordFailure($draft, 'context_changed', $result);

                    return;
                }

                $updated = ConversationCopilotReplyDraft::query()
                    ->whereKey($draft->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotReplyDraft::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotReplyDraft::STATUS_READY,
                        'draft' => $output,
                        'grounding' => $grounding,
                        'source_context_fingerprint' => $sourceFingerprint,
                        'source_last_message_id' => $context->lastMessageId,
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => $result->promptTokens === null ? null : max(0, $result->promptTokens),
                        'completion_tokens' => $result->completionTokens === null ? null : max(0, $result->completionTokens),
                        'failure_code' => null,
                        'completed_at' => AiSupportUsage::lifecycleNow($draft),
                    ]);

                if ($updated === 1) {
                    $this->audit($draft, 'conversation.ai_reply_draft.generated', [
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => $result->promptTokens === null ? null : max(0, $result->promptTokens),
                        'completion_tokens' => $result->completionTokens === null ? null : max(0, $result->completionTokens),
                    ], $result);
                }
            }));
        } catch (Throwable $exception) {
            try {
                $result?->settle('invalid_output');
            } catch (Throwable) {
                // Withhold output even when final usage persistence is unavailable.
            }
            if ($result === null) {
                $this->cancelBeforeProvider($usageArtifact);
            }
            Log::warning('Conversation copilot reply draft generation failed.', [
                'exception_type' => $exception::class,
                'draft_id' => $draft->id,
                'conversation_id' => $conversation->id,
            ]);
            $code = is_string($draft->preparation_fingerprint) && $exception instanceof AiNetworkException
                && in_array($exception->reason, ['connection_changed', 'policy_changed', 'access_revoked', 'context_changed', 'ticket_exists'], true)
                ? $exception->reason : 'provider';
            $this->recordFailure($draft, $code, $result);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $draft = ConversationCopilotReplyDraft::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->draftId);

        if (! $draft instanceof ConversationCopilotReplyDraft) {
            return;
        }

        Log::warning('Conversation copilot reply draft job failed.', [
            'exception_type' => $exception === null ? null : $exception::class,
            'draft_id' => $draft->id,
            'conversation_id' => $draft->conversation_id,
        ]);
        $this->recordFailure($draft, 'job');
    }

    private function isCurrent(?ConversationCopilotReplyDraft $draft): bool
    {
        return $draft !== null
            && $draft->generation === $this->generation
            && $draft->status === ConversationCopilotReplyDraft::STATUS_PENDING;
    }

    private function policyIsCurrent(ConversationCopilotReplyDraft $draft): bool
    {
        try {
            return $draft->conversation !== null && app(AiAssistPolicyResolver::class)->isCurrent((int) $draft->conversation->site_id, self::PURPOSE, $draft->policy_revision);
        } catch (Throwable) {
            return false;
        }
    }

    private function cancelBeforeProvider(ConversationCopilotReplyDraft $draft): void
    {
        try {
            app(AiSupportUsage::class)->cancelFor($draft, 'known_unsent');
        } catch (Throwable) {
            // Bounded expiry recovers an uncertain reservation release.
        }
    }

    private function recordFailure(ConversationCopilotReplyDraft $draft, string $code, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(AiUsageLedger::class)->withAccountUsageLock((int) $draft->conversation?->site?->account_id, function () use ($code, $draft, $result): void {
            $updated = ConversationCopilotReplyDraft::query()
                ->whereKey($draft->id)
                ->where('generation', $this->generation)
                ->whereIn('status', [
                    ConversationCopilotReplyDraft::STATUS_PENDING,
                    ConversationCopilotReplyDraft::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ConversationCopilotReplyDraft::STATUS_FAILED,
                    'draft' => null, 'grounding' => null,
                    'failure_code' => $code,
                    'completed_at' => AiSupportUsage::lifecycleNow($draft),
                ]);

            if ($updated === 1) {
                app(AiAssistPreparation::class)->syncTerminal($draft);
                $this->audit($draft, 'conversation.ai_reply_draft.failed', [
                    'failure_code' => $code,
                ], $result);
            }
        });
    }

    /** @param  array<string, int|string|null>  $metadata */
    private function audit(#[\SensitiveParameter] ConversationCopilotReplyDraft $draft, string $action, #[\SensitiveParameter] array $metadata, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(SharedAssistAudit::class)->record($draft, $action, $metadata, result: $result);
    }
}
