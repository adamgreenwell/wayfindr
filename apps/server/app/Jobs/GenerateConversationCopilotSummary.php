<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ConversationCopilotSummary;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\ConversationSummaryPromptBuilder;
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
        ConversationSummaryPromptBuilder $promptBuilder,
    ): void {
        $summary = ConversationCopilotSummary::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->summaryId);

        if (! $this->isCurrent($summary)) {
            return;
        }

        $conversation = $summary->conversation;
        $requester = $summary->requestedBy;

        // Queue delay is an authority boundary. Losing access, deactivation,
        // or deleting either record before the worker starts means no text may
        // leave this installation.
        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('view', $conversation)) {
            $this->recordFailure($summary, 'access_revoked');

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->recordFailure($summary, 'no_messages');

                return;
            }

            $claimed = ConversationCopilotSummary::query()
                ->whereKey($summary->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotSummary::STATUS_PENDING)
                ->update([
                    'status' => ConversationCopilotSummary::STATUS_RUNNING,
                    'started_at' => now(),
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
                return;
            }

            if (! Gate::forUser($requester)->allows('view', $conversation)) {
                $this->recordFailure($summary, 'access_revoked');

                return;
            }

            $result = $provider->generate($context->prompt);
            $maximum = 4_000;
            DB::transaction(function () use ($context, $maximum, $result, $summary): void {
                $updated = ConversationCopilotSummary::query()
                    ->whereKey($summary->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotSummary::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotSummary::STATUS_READY,
                        'summary' => mb_substr(trim($result->text), 0, $maximum),
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
                    $this->audit($summary, 'conversation.ai_summary.generated', [
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => max(0, $result->promptTokens),
                        'completion_tokens' => max(0, $result->completionTokens),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            // Provider exceptions can contain endpoints, account identifiers,
            // or echoed prompt text. Keep the host log diagnostic-only.
            Log::warning('Conversation copilot summary generation failed.', [
                'exception_type' => $exception::class,
                'summary_id' => $summary->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->recordFailure($summary, 'provider');
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

    private function recordFailure(ConversationCopilotSummary $summary, string $code): void
    {
        DB::transaction(function () use ($code, $summary): void {
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
                    'completed_at' => now(),
                ]);

            if ($updated === 1) {
                $this->audit($summary, 'conversation.ai_summary.failed', [
                    'failure_code' => $code,
                ]);
            }
        });
    }

    /** @param  array<string, int|string>  $metadata */
    private function audit(ConversationCopilotSummary $summary, string $action, array $metadata): void
    {
        $conversation = $summary->conversation;

        if ($conversation === null) {
            return;
        }

        AuditEvent::query()->create([
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $summary->requestedBy?->getMorphClass(),
            'actor_id' => $summary->requested_by_id,
            'subject_type' => $conversation->getMorphClass(),
            'subject_id' => $conversation->id,
            'action' => $action,
            'metadata' => [
                'summary_id' => $summary->id,
                'generation' => $this->generation,
                ...$metadata,
            ],
            'occurred_at' => now(),
        ]);
    }
}
