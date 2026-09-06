<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ConversationCopilotReplyDraft;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\ConversationReplyDraftPromptBuilder;
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
        ConversationReplyDraftPromptBuilder $promptBuilder,
    ): void {
        $draft = ConversationCopilotReplyDraft::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->draftId);

        if (! $this->isCurrent($draft)) {
            return;
        }

        $conversation = $draft->conversation;
        $requester = $draft->requestedBy;

        // Reply permission, not merely transcript visibility, is required at
        // execution time because the output is intended for the composer.
        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('reply', $conversation)) {
            $this->recordFailure($draft, 'access_revoked');

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->recordFailure($draft, 'no_messages');

                return;
            }

            $claimed = ConversationCopilotReplyDraft::query()
                ->whereKey($draft->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotReplyDraft::STATUS_PENDING)
                ->update([
                    'status' => ConversationCopilotReplyDraft::STATUS_RUNNING,
                    'started_at' => now(),
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
                return;
            }

            if (! Gate::forUser($requester)->allows('reply', $conversation)) {
                $this->recordFailure($draft, 'access_revoked');

                return;
            }

            $result = $provider->generate($context->prompt);
            $output = mb_substr(trim($result->text), 0, 6_000);

            if ($output === '') {
                $this->recordFailure($draft, 'empty_output');

                return;
            }

            DB::transaction(function () use ($context, $draft, $output, $result): void {
                $updated = ConversationCopilotReplyDraft::query()
                    ->whereKey($draft->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotReplyDraft::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotReplyDraft::STATUS_READY,
                        'draft' => $output,
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
                    $this->audit($draft, 'conversation.ai_reply_draft.generated', [
                        'source_message_count' => $context->messageCount,
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => max(0, $result->promptTokens),
                        'completion_tokens' => max(0, $result->completionTokens),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Conversation copilot reply draft generation failed.', [
                'exception_type' => $exception::class,
                'draft_id' => $draft->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->recordFailure($draft, 'provider');
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

    private function recordFailure(ConversationCopilotReplyDraft $draft, string $code): void
    {
        DB::transaction(function () use ($code, $draft): void {
            $updated = ConversationCopilotReplyDraft::query()
                ->whereKey($draft->id)
                ->where('generation', $this->generation)
                ->whereIn('status', [
                    ConversationCopilotReplyDraft::STATUS_PENDING,
                    ConversationCopilotReplyDraft::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ConversationCopilotReplyDraft::STATUS_FAILED,
                    'failure_code' => $code,
                    'completed_at' => now(),
                ]);

            if ($updated === 1) {
                $this->audit($draft, 'conversation.ai_reply_draft.failed', [
                    'failure_code' => $code,
                ]);
            }
        });
    }

    /** @param  array<string, int|string>  $metadata */
    private function audit(ConversationCopilotReplyDraft $draft, string $action, array $metadata): void
    {
        $conversation = $draft->conversation;

        if ($conversation === null) {
            return;
        }

        AuditEvent::query()->create([
            'account_id' => $conversation->site?->account_id,
            'site_id' => $conversation->site_id,
            'actor_type' => $draft->requestedBy?->getMorphClass(),
            'actor_id' => $draft->requested_by_id,
            'subject_type' => $conversation->getMorphClass(),
            'subject_id' => $conversation->id,
            'action' => $action,
            'metadata' => [
                'draft_id' => $draft->id,
                'generation' => $this->generation,
                ...$metadata,
            ],
            'occurred_at' => now(),
        ]);
    }
}
