<?php

declare(strict_types=1);

namespace App\Support\Ai\Observability;

use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Caller owns account/parent lifecycle locks; event names remain compatible. */
final readonly class SharedAssistAudit
{
    public function record(#[\SensitiveParameter] Model $hint, string $action, #[\SensitiveParameter] array $metadata = [], #[\SensitiveParameter] ?ResolvedAgentCopilotConnection $connection = null, #[\SensitiveParameter] ?AgentCopilotResult $result = null): ?AuditEvent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Assist audit requires a locked transaction.');
        }
        $purpose = array_search($hint::class, AiSupportUsage::ARTIFACTS, true);
        $events = ['conversation_summary' => 'ai_summary', 'conversation_reply_draft' => 'ai_reply_draft', 'conversation_knowledge_suggestion' => 'ai_knowledge_suggestion', 'conversation_ticket_suggestion' => 'ai_ticket_suggestion'];
        if ($purpose === false || ! in_array($action, array_map(fn ($state) => 'conversation.'.$events[$purpose].'.'.$state, ['requested', 'started', 'generated', 'failed']), true)) {
            throw new LogicException('Unknown Assist audit transition.');
        }
        $row = $hint::query()->whereKey($hint->id)->where('generation', $hint->generation)->first();
        $conversation = $row === null ? null : Conversation::query()->with('site')->find($row->conversation_id);
        $actor = $row === null ? null : User::query()->find($row->requested_by_id);
        if ($conversation?->site === null || $actor === null || (int) $actor->account_id !== (int) $conversation->site->account_id) {
            return null;
        }
        $query = AuditEvent::query()->where('account_id', $actor->account_id)->where('subject_type', $conversation->getMorphClass())
            ->where('subject_id', $conversation->id)->where('metadata->generation', $row->generation);
        if (($existing = (clone $query)->where('action', $action)->first()) !== null) {
            return $existing;
        }
        $requested = (clone $query)->where('action', 'conversation.'.$events[$purpose].'.requested')->first();
        // Scrubbed metadata is a terminal privacy boundary, never a provenance cache.
        if ($requested !== null && ($requested->metadata['erased'] ?? false)) {
            return null;
        }
        $route = $connection === null ? array_intersect_key($requested?->metadata ?? [], array_flip(['source', 'provider', 'model_tag', 'route_tag'])) : app(DraftRefinementAudit::class)->route($connection);
        $now = app(AiUsageClock::class)->now();
        $duration = static function ($start, $end): ?int {
            if ($start === null || $end === null) {
                return null;
            }$ms = ($end->getTimestamp() - $start->getTimestamp()) * 1000;

            return $ms >= 0 && $ms <= 900000 ? $ms : null;
        };
        $bounded = static fn ($value) => is_int($value) && $value >= 0 && $value <= 2147483647 ? $value : null;
        $attempt = DB::table('ai_usage_attempts')->where('account_id', $actor->account_id)->where('attempt_id', $row->generation)->first();
        $input = $result?->isGuarded() === true ? $result->promptTokens : $attempt?->reported_input_tokens;
        $output = $result?->isGuarded() === true ? $result->completionTokens : $attempt?->reported_output_tokens;
        $keys = match ($purpose) {
            'conversation_summary' => 'summary_id','conversation_reply_draft' => 'draft_id',default => 'suggestion_id'
        };
        $failure = $metadata['failure_code'] ?? null;
        $reasons = ['superseded', 'job', 'provider', 'usage', 'queue', 'policy_changed', 'connection_changed', 'access_revoked', 'context_changed', 'no_messages', 'empty_output', 'provider_failed', 'ticket_exists', 'invalid_output', 'usage_token_limit', 'usage_request_limit', 'usage_concurrency_limit'];

        return AuditEvent::query()->create(['account_id' => $actor->account_id, 'site_id' => $conversation->site_id, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
            'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id, 'action' => $action, 'occurred_at' => $now,
            'metadata' => [$keys => $row->id, 'version' => 1, 'purpose' => $purpose, 'generation' => $row->generation, 'state' => Str::afterLast($action, '.'),
                'connection_revision' => $row->connection_revision, 'policy_revision' => $row->policy_revision, 'work_fingerprint' => $row->preparation_fingerprint,
                'source_revision' => is_string($row->source_context_fingerprint) ? $row->source_context_fingerprint : ($requested?->metadata['source_revision'] ?? ($metadata['source_revision'] ?? null)),
                'source' => $route['source'] ?? null, 'provider' => $route['provider'] ?? null, 'model_tag' => $route['model_tag'] ?? null, 'route_tag' => $route['route_tag'] ?? null,
                'elapsed_ms' => $duration($row->requested_at, $now), 'queue_ms' => $duration($row->requested_at, $row->started_at),
                'prompt_tokens' => $bounded($input), 'completion_tokens' => $bounded($output), 'failure_code' => $failure === null ? null : (in_array($failure, $reasons, true) ? $failure : 'other'),
                ...array_intersect_key($metadata, array_flip(['source_message_count', 'suggested_article_count', 'suggested_label_count']))]]);
    }
}
