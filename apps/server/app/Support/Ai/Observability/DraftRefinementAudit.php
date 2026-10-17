<?php

declare(strict_types=1);

namespace App\Support\Ai\Observability;

use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationDraftRefinement;
use App\Models\User;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Refinement\ConversationDraftRefinementPromptBuilder;
use App\Support\Ai\Refinement\DraftRefinementAction;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/** Internal lifecycle writer: caller holds installation/account usage and private-row locks. */
final readonly class DraftRefinementAudit
{
    public const STATES = ['requested', 'started', 'ready', 'failed', 'stale', 'cancelled', 'applied', 'superseded', 'expired'];

    private const REASONS = ['connection_changed', 'access_revoked', 'context_changed', 'usage_token_limit', 'usage_request_limit',
        'usage_concurrency_limit', 'invalid_output', 'provider_failed', 'queue_failed'];

    public function __construct(private AiUsageClock $clock) {}

    /** Capture configured provenance once; never copy provider-reported strings or raw configuration. */
    public function route(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): array
    {
        $tag = fn (string $kind, #[SensitiveParameter] array $values): string => hash_hmac('sha256', json_encode([$kind, $connection->accountId, ...$values], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return ['source' => in_array($connection->source, ['account', 'installation'], true) ? $connection->source : null,
            'provider' => in_array($connection->provider, AgentCopilotConfiguration::PROVIDERS, true) ? $connection->provider : null,
            'model_tag' => $tag('assist-audit-model-v1', [$connection->model]),
            'route_tag' => $tag('assist-audit-route-v1', [$connection->source, $connection->provider, $connection->model, $connection->endpoint, $connection->openRouterProvider])];
    }

    public function record(#[SensitiveParameter] ConversationDraftRefinement $hint, string $state,
        #[SensitiveParameter] ?string $reason = null, #[SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        if (DB::transactionLevel() < 1 || ! in_array($state, self::STATES, true)) {
            throw new LogicException('Private lifecycle audit requires a locked transaction and a closed state.');
        }
        // Never use stale model relations to recreate a footprint after erasure or supersession.
        $row = ConversationDraftRefinement::query()->whereKey($hint->id)->where('generation', $hint->generation)->first();
        if ($row === null || $row->operation === []) {
            return;
        }
        $conversation = Conversation::query()->with('site')->whereKey($row->conversation_id)->first();
        $actor = User::query()->whereKey($row->requested_by_id)->where('account_id', $row->account_id)->first();
        if ($conversation?->site === null || (int) $conversation->site->account_id !== (int) $row->account_id || $actor === null) {
            return;
        }
        $action = 'conversation.ai_draft_refinement.'.$state;
        if (AuditEvent::query()->where('account_id', $row->account_id)->where('subject_type', $conversation->getMorphClass())
            ->where('subject_id', $conversation->id)->where('action', $action)->where('metadata->generation', $row->generation)->exists()) {
            return;
        }
        try {
            $route = $row->scope['audit_route'] ?? [];
        } catch (DecryptException) {
            // Expired ciphertext must remain removable after loss of the old key.
            $route = [];
        }
        $route = is_array($route) ? $route : [];
        $operation = $row->operation;
        AuditEvent::query()->create(['account_id' => $row->account_id, 'site_id' => $conversation->site_id,
            'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
            'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id, 'action' => $action,
            'metadata' => ['version' => 1, 'purpose' => ConversationDraftRefinementPromptBuilder::PURPOSE,
                'refinement_id' => $row->id, 'generation' => Str::isUuid($row->generation) ? $row->generation : null,
                'state' => $state, 'operation' => DraftRefinementAction::tryFrom(is_string($operation['action'] ?? null) ? $operation['action'] : '')?->value,
                'connection_revision' => Str::isUuid($row->connection_revision) ? $row->connection_revision : $this->digest($row->connection_revision),
                'policy_revision' => $this->digest($row->policy_revision), 'work_fingerprint' => $this->digest($row->work_fingerprint),
                'source' => in_array($route['source'] ?? null, ['account', 'installation'], true) ? $route['source'] : null,
                'provider' => in_array($route['provider'] ?? null, AgentCopilotConfiguration::PROVIDERS, true) ? $route['provider'] : null,
                'model_tag' => $this->digest($route['model_tag'] ?? null), 'route_tag' => $this->digest($route['route_tag'] ?? null),
                'elapsed_ms' => $this->duration($row->requested_at, $this->clock->now()),
                'queue_ms' => $this->duration($row->requested_at, $row->started_at),
                'reported_input_tokens' => $this->tokens($result?->promptTokens), 'reported_output_tokens' => $this->tokens($result?->completionTokens),
                'failure_code' => $reason === null ? null : (in_array($reason, self::REASONS, true) ? $reason : 'other')],
            'occurred_at' => $this->clock->now()]);
    }

    private function digest(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1 ? $value : null;
    }

    private function tokens(?int $value): ?int
    {
        return $value !== null && $value >= 0 && $value <= 2147483647 ? $value : null;
    }

    private function duration(?CarbonImmutable $start, ?CarbonImmutable $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }
        // Lifecycle columns have second precision; do not invent subsecond latency.
        $value = ($end->getTimestamp() - $start->getTimestamp()) * 1000;

        return $value >= 0 && $value <= 900000 ? $value : null;
    }
}
