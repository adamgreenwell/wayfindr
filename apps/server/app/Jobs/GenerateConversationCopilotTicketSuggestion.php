<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\TicketLabel;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\ConversationTicketSuggestionOutputParser;
use App\Support\Ai\ConversationTicketSuggestionPromptBuilder;
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

/** Generate one agent-requested ticket suggestion without changing a ticket. */
final class GenerateConversationCopilotTicketSuggestion implements ShouldBeUnique, ShouldQueue
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
        ConversationTicketSuggestionPromptBuilder $promptBuilder,
        ConversationTicketSuggestionOutputParser $outputParser,
    ): void {
        $suggestion = ConversationCopilotTicketSuggestion::query()
            ->with(['conversation.site.account', 'requestedBy'])
            ->find($this->suggestionId);

        if (! $this->isCurrent($suggestion)) {
            return;
        }

        $conversation = $suggestion->conversation;
        $requester = $suggestion->requestedBy;

        if ($conversation === null || $requester === null || ! Gate::forUser($requester)->allows('createTicket', $conversation)) {
            $this->recordFailure($suggestion, 'access_revoked');

            return;
        }

        if ($conversation->tickets()->exists()) {
            $this->recordFailure($suggestion, 'ticket_exists');

            return;
        }

        try {
            $context = $promptBuilder->build($conversation);

            if ($context === null) {
                $this->recordFailure($suggestion, 'no_messages');

                return;
            }

            $claimed = ConversationCopilotTicketSuggestion::query()
                ->whereKey($suggestion->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotTicketSuggestion::STATUS_PENDING)
                ->update([
                    'status' => ConversationCopilotTicketSuggestion::STATUS_RUNNING,
                    'started_at' => now(),
                ]);

            if ($claimed !== 1) {
                return;
            }

            $suggestion = ConversationCopilotTicketSuggestion::query()
                ->with(['conversation.site.account', 'requestedBy'])
                ->whereKey($suggestion->id)
                ->where('generation', $this->generation)
                ->where('status', ConversationCopilotTicketSuggestion::STATUS_RUNNING)
                ->first();
            $conversation = $suggestion?->conversation;
            $requester = $suggestion?->requestedBy;

            if ($suggestion === null || $conversation === null || $requester === null) {
                return;
            }

            if (! Gate::forUser($requester)->allows('createTicket', $conversation)) {
                $this->recordFailure($suggestion, 'access_revoked');

                return;
            }

            if ($conversation->tickets()->exists()) {
                $this->recordFailure($suggestion, 'ticket_exists');

                return;
            }

            $result = $provider->generate($context->prompt);
            $output = $outputParser->parse(mb_substr(trim($result->text), 0, 8_000));

            if ($output === null) {
                $this->recordFailure($suggestion, 'invalid_output');

                return;
            }

            $accountId = $conversation->site?->account_id;
            $labelIds = $accountId === null
                ? []
                : TicketLabel::query()
                    ->where('account_id', $accountId)
                    ->whereKey($context->labelIds)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->values()
                    ->all();

            DB::transaction(function () use ($context, $labelIds, $output, $result, $suggestion): void {
                $updated = ConversationCopilotTicketSuggestion::query()
                    ->whereKey($suggestion->id)
                    ->where('generation', $this->generation)
                    ->where('status', ConversationCopilotTicketSuggestion::STATUS_RUNNING)
                    ->update([
                        'status' => ConversationCopilotTicketSuggestion::STATUS_READY,
                        'title' => $output->title,
                        'priority' => $output->priority,
                        'label_ids' => $labelIds,
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
                    $this->audit($suggestion, 'conversation.ai_ticket_suggestion.generated', [
                        'source_message_count' => $context->messageCount,
                        'suggested_label_count' => count($labelIds),
                        'provider' => mb_substr($result->provider, 0, 64),
                        'model' => mb_substr($result->model, 0, 255),
                        'prompt_tokens' => max(0, $result->promptTokens),
                        'completion_tokens' => max(0, $result->completionTokens),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::warning('Conversation copilot ticket suggestion generation failed.', [
                'exception_type' => $exception::class,
                'suggestion_id' => $suggestion->id,
                'conversation_id' => $conversation->id,
            ]);
            $this->recordFailure($suggestion, 'provider');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $suggestion = ConversationCopilotTicketSuggestion::query()
            ->with(['conversation.site', 'requestedBy'])
            ->find($this->suggestionId);

        if (! $suggestion instanceof ConversationCopilotTicketSuggestion) {
            return;
        }

        Log::warning('Conversation copilot ticket suggestion job failed.', [
            'exception_type' => $exception === null ? null : $exception::class,
            'suggestion_id' => $suggestion->id,
            'conversation_id' => $suggestion->conversation_id,
        ]);
        $this->recordFailure($suggestion, 'job');
    }

    private function isCurrent(?ConversationCopilotTicketSuggestion $suggestion): bool
    {
        return $suggestion !== null
            && $suggestion->generation === $this->generation
            && $suggestion->status === ConversationCopilotTicketSuggestion::STATUS_PENDING;
    }

    private function recordFailure(ConversationCopilotTicketSuggestion $suggestion, string $code): void
    {
        DB::transaction(function () use ($code, $suggestion): void {
            $updated = ConversationCopilotTicketSuggestion::query()
                ->whereKey($suggestion->id)
                ->where('generation', $this->generation)
                ->whereIn('status', [
                    ConversationCopilotTicketSuggestion::STATUS_PENDING,
                    ConversationCopilotTicketSuggestion::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ConversationCopilotTicketSuggestion::STATUS_FAILED,
                    'failure_code' => $code,
                    'completed_at' => now(),
                ]);

            if ($updated === 1) {
                $this->audit($suggestion, 'conversation.ai_ticket_suggestion.failed', [
                    'failure_code' => $code,
                ]);
            }
        });
    }

    /** @param  array<string, int|string>  $metadata */
    private function audit(ConversationCopilotTicketSuggestion $suggestion, string $action, array $metadata): void
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
