<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Account;
use App\Models\ConversationCopilotSummary;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\ConversationSummaryPromptBuilder;
use App\Support\Ai\Execution\AiSupportUsage;
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

/** Generate one agent-requested summary without placing support text in the queue payload. */
final class GenerateConversationCopilotSummary implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PURPOSE = 'conversation_summary';

    public int $tries = 1;

    // The shipped default worker stops jobs at 90 seconds. Leave room for the
    // provider timeout to unwind and for the result/audit writes to complete.
    public int $timeout = 85;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly int $summaryId,
        private readonly string $generation,
    ) {}

    public function uniqueId(): string
    {
        return $this->summaryId.':'.$this->generation;
    }

    public function handle(
        AgentCopilotProvider $provider,
        AgentCopilotConnectionResolver $connections,
        ConversationSummaryPromptBuilder $promptBuilder,
    ): void {
        $summary = ConversationCopilotSummary::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->summaryId);

        if (! $this->isCurrent($summary)) {
            return;
        }

        $usageArtifact = $summary;
        $result = null;

        if (! $this->policyIsCurrent($summary)) {
            $this->cancelBeforeProvider($summary);
            $this->recordFailure($summary, 'policy_changed', $result);

            return;
        }

        $conversation = $summary->conversation;
        $requester = $summary->requestedBy;

        // Queue delay is an authority boundary. Losing access, deactivation,
        // or deleting either record before the worker starts means no text may
        // leave this installation.
        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('view', $conversation)) {
            $this->cancelBeforeProvider($summary);
            $this->recordFailure($summary, 'access_revoked', $result);

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->cancelBeforeProvider($summary);
                $this->recordFailure($summary, 'no_messages', $result);

                return;
            }

            $claimed = is_string($summary->preparation_fingerprint)
                ? app(AiSupportUsage::class)->claimPrepared($summary)
                : ConversationCopilotSummary::query()
                    ->whereKey($summary->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotSummary::STATUS_PENDING)
                    ->update([
                        'status' => ConversationCopilotSummary::STATUS_RUNNING,
                        'started_at' => AiSupportUsage::lifecycleNow($summary),
                    ]);

            // A replacement generation won while this job was waiting or
            // selecting context. Its transcript must not be sent by stale work.
            if ($claimed !== 1) {
                return;
            }

            // Refresh the authority-bearing models immediately before the
            // external call. Permission and membership relations loaded at
            // the start of a delayed job must not become an authorization
            // cache if access changes while context is being selected.
            $summary = ConversationCopilotSummary::query()
                ->with(['conversation.site', 'requestedBy'])
                ->whereKey($summary->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotSummary::STATUS_RUNNING)
                ->first();
            $conversation = $summary?->conversation;
            $requester = $summary?->requestedBy;

            if ($summary === null || $conversation === null || $requester === null) {
                $this->cancelBeforeProvider($usageArtifact);

                return;
            }

            if (! Gate::forUser($requester)->allows('view', $conversation)) {
                $this->cancelBeforeProvider($summary);
                $this->recordFailure($summary, 'access_revoked', $result);

                return;
            }

            $connection = $connections->forAccount((int) $conversation->site->account_id);
            if ($connection === null || $summary->connection_revision !== $connection->revision) {
                $this->cancelBeforeProvider($summary);
                $this->recordFailure($summary, 'connection_changed', $result);

                return;
            }

            if (! $this->policyIsCurrent($summary)) {
                $this->cancelBeforeProvider($summary);
                $this->recordFailure($summary, 'policy_changed', $result);

                return;
            }
            $sourceFingerprint = AiSupportUsage::sourceFingerprint($summary, $context->prompt);
            $result = $provider->generate($context->prompt->withExecution($connection, app(AiSupportUsage::class)->contextFor($summary)));
            $output = mb_substr(trim($result->text), 0, 4_000);

            if ($output === '') {
                $result->settle('invalid_output');
                $this->recordFailure($summary, 'empty_output', $result);

                return;
            }

            app(AiSupportUsage::class)->publish($summary, $result, fn () => DB::transaction(function () use ($context, $output, $result, $summary, $connections, $sourceFingerprint): void {
                $accountId = (int) $summary->conversation->site->account_id;
                if (! Account::query()->whereKey($accountId)->lockForUpdate()->first()) {
                    return;
                }

                $summary = ConversationCopilotSummary::query()
                    ->with(['conversation.site.account', 'requestedBy'])
                    ->whereKey($summary->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotSummary::STATUS_RUNNING)
                    ->lockForUpdate()
                    ->first();

                if ($summary === null) {
                    return;
                }

                if (! $this->policyIsCurrent($summary)) {
                    $this->recordFailure($summary, 'policy_changed', $result);

                    return;
                }

                $conversation = $summary->conversation;
                $requester = $summary->requestedBy;
                if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('view', $conversation)) {
                    $this->recordFailure($summary, 'access_revoked', $result);

                    return;
                }

                if (! $connections->isCurrent($accountId, $summary->connection_revision)) {
                    $this->recordFailure($summary, 'connection_changed', $result);

                    return;
                }

                $updated = ConversationCopilotSummary::query()
                    ->whereKey($summary->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotSummary::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotSummary::STATUS_READY,
                        'summary' => $output,
                        'source_context_fingerprint' => $sourceFingerprint,
                        'source_last_message_id' => $context->lastMessageId,
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => $result->promptTokens === null ? null : max(0, $result->promptTokens),
                        'completion_tokens' => $result->completionTokens === null ? null : max(0, $result->completionTokens),
                        'failure_code' => null,
                        'completed_at' => AiSupportUsage::lifecycleNow($summary),
                    ]);

                if ($updated === 1) {
                    $this->audit($summary, 'conversation.ai_summary.generated', [
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
            // Provider exceptions can contain endpoints, account identifiers,
            // or echoed prompt text. Keep the host log diagnostic-only.
            Log::warning('Conversation copilot summary generation failed.', [
                'exception_type' => $exception::class,
                'summary_id' => $summary->id,
                'conversation_id' => $conversation->id,
            ]);
            $code = is_string($summary->preparation_fingerprint) && $exception instanceof AiNetworkException
                && in_array($exception->reason, ['connection_changed', 'policy_changed', 'access_revoked', 'context_changed', 'ticket_exists'], true)
                ? $exception->reason : 'provider';
            $this->recordFailure($summary, $code, $result);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $summary = ConversationCopilotSummary::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->summaryId);

        if (! $summary instanceof ConversationCopilotSummary) {
            return;
        }

        Log::warning('Conversation copilot summary job failed.', [
            'exception_type' => $exception === null ? null : $exception::class,
            'summary_id' => $summary->id,
            'conversation_id' => $summary->conversation_id,
        ]);
        $this->recordFailure($summary, 'job');
    }

    private function isCurrent(?ConversationCopilotSummary $summary): bool
    {
        return $summary !== null
            && $summary->generation === $this->generation
            && $summary->status === ConversationCopilotSummary::STATUS_PENDING;
    }

    private function policyIsCurrent(ConversationCopilotSummary $summary): bool
    {
        try {
            return $summary->conversation !== null && app(AiAssistPolicyResolver::class)->isCurrent((int) $summary->conversation->site_id, self::PURPOSE, $summary->policy_revision);
        } catch (Throwable) {
            return false;
        }
    }

    private function cancelBeforeProvider(ConversationCopilotSummary $summary): void
    {
        try {
            app(AiSupportUsage::class)->cancelFor($summary, 'known_unsent');
        } catch (Throwable) {
            // Bounded expiry recovers an uncertain reservation release.
        }
    }

    private function recordFailure(ConversationCopilotSummary $summary, string $code, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(AiUsageLedger::class)->withAccountUsageLock((int) $summary->conversation?->site?->account_id, function () use ($code, $summary, $result): void {
            $updated = ConversationCopilotSummary::query()
                ->whereKey($summary->id)
                ->where('generation', $this->generation)
                ->whereIn('status', [
                    ConversationCopilotSummary::STATUS_PENDING,
                    ConversationCopilotSummary::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ConversationCopilotSummary::STATUS_FAILED,
                    'failure_code' => $code,
                    'completed_at' => AiSupportUsage::lifecycleNow($summary),
                ]);

            if ($updated === 1) {
                app(AiAssistPreparation::class)->syncTerminal($summary);
                $this->audit($summary, 'conversation.ai_summary.failed', [
                    'failure_code' => $code,
                ], $result);
            }
        });
    }

    /** @param  array<string, int|string|null>  $metadata */
    private function audit(#[\SensitiveParameter] ConversationCopilotSummary $summary, string $action, #[\SensitiveParameter] array $metadata, #[\SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(SharedAssistAudit::class)->record($summary, $action, $metadata, result: $result);
    }
}
