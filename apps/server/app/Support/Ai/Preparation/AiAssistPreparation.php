<?php

declare(strict_types=1);

namespace App\Support\Ai\Preparation;

use App\Jobs\GenerateConversationCopilotKnowledgeSuggestion;
use App\Jobs\GenerateConversationCopilotReplyDraft;
use App\Jobs\GenerateConversationCopilotSummary;
use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AiAssistPreparationReceipt;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\ConversationAssistPresentation;
use App\Support\Ai\ConversationTextContextSelector;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Grounding\ConversationGroundedReplyPresentation;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One admission/cache boundary for explicit Manual and visible Prepare opens. */
final readonly class AiAssistPreparation
{
    private const JOBS = [
        'conversation_summary' => GenerateConversationCopilotSummary::class,
        'conversation_reply_draft' => GenerateConversationCopilotReplyDraft::class,
        'conversation_knowledge_suggestion' => GenerateConversationCopilotKnowledgeSuggestion::class,
        'conversation_ticket_suggestion' => GenerateConversationCopilotTicketSuggestion::class,
    ];

    private const OUTPUT = [
        'conversation_summary' => ['summary'], 'conversation_reply_draft' => ['draft', 'grounding'],
        'conversation_knowledge_suggestion' => ['article_ids'],
        'conversation_ticket_suggestion' => ['title', 'priority', 'label_ids'],
    ];

    public function __construct(private AiAssistPreparationIdentityResolver $identities,
        private AiSupportUsage $usage, private AiUsageLedger $ledger, private AiUsageClock $clock,
        private AgentCopilotConnectionResolver $connections) {}

    public function ensure(User $actor, Conversation $conversation, string $purpose, string $action, string $workspace): array
    {
        if (! in_array($action, ['open', 'initial', 'refresh'], true)
            || $workspace !== ($purpose === 'conversation_ticket_suggestion' ? 'conversion' : 'conversation')) {
            throw new AiNetworkException('execution_identity_invalid');
        }
        $identity = $this->identities->forTask($actor, $conversation, $purpose);
        if ($action === 'open' && $identity->mode !== 'prepare') {
            throw new AiNetworkException('access_revoked');
        }
        // No lower-order Account lock may be held before entering this boundary.
        $admission = $this->ledger->withAccountUsageLock($identity->accountId,
            function () use ($actor, $conversation, $purpose, $action, $identity): array {
                $actor = User::query()->whereKey($actor->id)->where('account_id', $identity->accountId)->lockForUpdate()->first();
                $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();
                if ($actor === null || $conversation === null) {
                    throw new AiNetworkException('access_revoked');
                }
                try {
                    $identity = $this->identities->forTask($actor, $conversation, $purpose);
                } catch (AuthorizationException) {
                    throw new AiNetworkException('access_revoked');
                }
                if ($action === 'open' && $identity->mode !== 'prepare') {
                    throw new AiNetworkException('access_revoked');
                }
                $class = AiSupportUsage::ARTIFACTS[$purpose];
                $artifact = $class::query()->where('conversation_id', $conversation->id)->lockForUpdate()->first();
                $receipt = $this->receipt($identity)->lockForUpdate()->first();
                $now = $this->clock->now()->utc();
                if ($receipt !== null && ($action !== 'refresh' || $identity->state === 'insufficient_context')) {
                    return [$artifact, false];
                }
                if ($artifact !== null && DB::table('ai_usage_attempts')->where('account_id', $identity->accountId)
                    ->where('attempt_id', $artifact->generation)->where('concurrency_held', true)->exists()) {
                    $this->ledger->releaseExpiredDispatch($artifact->generation);
                }
                if ($artifact !== null && hash_equals((string) $artifact->preparation_fingerprint, $identity->fingerprint)
                    && $this->pendingFresh($artifact, $now)) {
                    return [$artifact, false];
                }
                if ($artifact !== null && $this->runningClaimLive($artifact, $now)) {
                    return [$artifact, false];
                }
                if ($action === 'refresh' && $receipt !== null
                    && $this->retryDeadline($artifact?->preparation_fingerprint === $identity->fingerprint ? $artifact : null, $receipt)->isAfter($now)) {
                    return [$artifact, false];
                }
                $previous = null;
                if ($artifact !== null) {
                    if ($artifact->status === 'ready' && $this->readableSource($artifact, $identity, $conversation, $actor)) {
                        $previous = $artifact->getRawOriginal();
                        unset($previous['previous_output']);
                    } elseif (is_array($artifact->previous_output)) {
                        $candidate = $artifact->newFromBuilder($artifact->previous_output);
                        if ($this->readableSource($candidate, $identity, $conversation, $actor)) {
                            $previous = $artifact->previous_output;
                        }
                    }
                    if (in_array($artifact->status, ['pending', 'running'], true)) {
                        if (! hash_equals((string) $artifact->preparation_fingerprint, $identity->fingerprint)) {
                            $this->usage->cancelFor($artifact, 'known_unsent');
                        }
                        // Same-key explicit replacement lets reserveFor retire
                        // its unsent hold without inventing a fresh cooldown.
                        $artifact->forceFill(['status' => 'failed'])->save();
                        $this->syncTerminal($artifact);
                        $artifact->refresh();
                    }
                }
                $connection = $this->connections->forAccount($identity->accountId);
                if ($connection === null || $connection->revision !== $identity->connectionRevision) {
                    throw new AiNetworkException('connection_changed');
                }
                if ($identity->state === 'eligible' && ($identity->sourceSnapshot['messages'] ?? []) === []) {
                    throw new AiNetworkException('execution_identity_invalid');
                }
                $cooldown = max(60, app(AiUsagePolicy::class)->forConnection($connection)->retryCooldownSeconds);
                $values = [
                    'conversation_id' => $identity->conversationId, 'requested_by_id' => $actor->id,
                    'generation' => (string) Str::uuid(), 'connection_revision' => $identity->connectionRevision,
                    'policy_revision' => $identity->policyRevision, 'preparation_fingerprint' => $identity->fingerprint,
                    'preparation_source_snapshot' => $identity->sourceSnapshot, 'previous_output' => $previous,
                    'status' => $identity->state === 'eligible' ? 'pending' : 'insufficient_context',
                    'source_context_fingerprint' => null, 'source_last_message_id' => null, 'source_message_count' => 0,
                    'provider' => null, 'model' => null, 'prompt_tokens' => null, 'completion_tokens' => null,
                    'failure_code' => null, 'requested_at' => $now, 'started_at' => null,
                    'completed_at' => $identity->state === 'eligible' ? null : $now,
                    'retry_not_before' => $now->addSeconds($cooldown),
                    ...array_fill_keys(self::OUTPUT[$purpose], null),
                ];
                $artifact ??= new $class;
                $artifact->forceFill($values)->save();
                // Terminal sync may have updated this row through another
                // instance. Refresh dirty tracking before replacing its generation.
                $receipt?->refresh();
                $receipt ??= new AiAssistPreparationReceipt;
                $receipt->forceFill(['account_id' => $identity->accountId, 'conversation_id' => $identity->conversationId,
                    'purpose' => $purpose, 'fingerprint' => $identity->fingerprint, 'generation' => $artifact->generation,
                    'requested_by_id' => $actor->id, 'status' => $artifact->status, 'requested_at' => $now,
                    'completed_at' => $artifact->completed_at, 'retry_not_before' => $artifact->retry_not_before])->save();
                if ($identity->state !== 'eligible') {
                    return [$artifact, false];
                }
                [$event, $artifactKey] = match ($purpose) {
                    'conversation_summary' => ['ai_summary', 'summary_id'],
                    'conversation_reply_draft' => ['ai_reply_draft', 'draft_id'],
                    'conversation_knowledge_suggestion' => ['ai_knowledge_suggestion', 'suggestion_id'],
                    'conversation_ticket_suggestion' => ['ai_ticket_suggestion', 'suggestion_id'],
                };
                $requestedAudit = AuditEvent::query()->create(['account_id' => $identity->accountId, 'site_id' => $identity->siteId,
                    'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
                    'subject_type' => $conversation->getMorphClass(), 'subject_id' => $conversation->id,
                    'action' => 'conversation.'.$event.'.requested',
                    'metadata' => [$artifactKey => $artifact->id, 'generation' => $artifact->generation, 'connection_revision' => $artifact->connection_revision], 'occurred_at' => $now]);

                try {
                    // The artifact and usage hold commit together, before enqueue.
                    $this->usage->reserveFor($artifact);
                } catch (Throwable) {
                    $artifact->forceFill(['status' => 'failed', 'failure_code' => 'usage', 'completed_at' => $now])->save();
                    $this->syncTerminal($artifact);
                    $this->auditFailure($requestedAudit, 'usage');

                    return [$artifact, false];
                }

                return [$artifact, true, $requestedAudit];
            });
        [$artifact, $dispatch] = $admission;
        if ($dispatch) {
            try {
                $job = self::JOBS[$purpose];
                $job::dispatch($artifact->id, $artifact->generation);
            } catch (Throwable) {
                $this->usage->cancelFor($artifact);
                DB::transaction(function () use ($artifact, $admission): void {
                    // Erasure also locks Account before artifacts/audits. Keep
                    // the captured failure atomic and never recreate erased work.
                    if (Account::query()->whereKey($admission[2]->account_id)->lockForUpdate()->first() === null) {
                        return;
                    }
                    $changed = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)
                        ->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => 'queue',
                            'completed_at' => $this->clock->now()]);
                    if ($changed === 1) {
                        $this->syncTerminal($artifact);
                        $this->auditFailure($admission[2], 'queue');
                    }
                });
                $dispatch = false;
            }
        }

        return $this->read($actor, $conversation, $purpose) + ['queued' => $dispatch];
    }

    /** Read-only: no usage locks, reservations, receipt writes or retries. */
    public function read(User $actor, Conversation $conversation, string $purpose): array
    {
        $identity = $this->identities->forTask($actor, $conversation, $purpose);
        $class = AiSupportUsage::ARTIFACTS[$purpose];
        $artifact = $class::query()->where('conversation_id', $identity->conversationId)->first();
        $receipt = $this->receipt($identity)->first();
        $now = $this->clock->now()->utc();
        $state = $identity->state === 'insufficient_context' ? 'insufficient_context' : ($receipt === null && $artifact?->preparation_fingerprint === null ? 'empty' : 'unavailable');
        $output = null;
        $displayArtifact = null;
        $current = $artifact !== null && $receipt !== null && $artifact->generation === $receipt->generation
            && hash_equals($identity->fingerprint, (string) $artifact->preparation_fingerprint);
        if ($current) {
            $state = $identity->reuseState($artifact->preparation_fingerprint, $artifact->status, $this->pendingFresh($artifact, $now));
            if ($state === 'ready' && $this->readableSource($artifact, $identity, $conversation->fresh(), $actor)) {
                $output = $this->output($artifact, $identity);
                $displayArtifact = $artifact;
            } elseif ($state === 'ready') {
                $state = 'unavailable';
            }
        }
        if ($artifact !== null && $output === null && in_array($artifact->status, ['ready', 'pending', 'running'], true)) {
            $prior = $artifact->status === 'ready' ? $artifact
                : (is_array($artifact->previous_output) ? $artifact->newFromBuilder($artifact->previous_output) : null);
            if ($prior !== null && $this->readableSource($prior, $identity, $conversation->fresh(), $actor)) {
                $output = $this->output($prior, $identity);
                $displayArtifact = $prior;
                $state = $current && $this->pendingFresh($artifact, $now) ? 'preparing' : 'stale';
            } elseif ($artifact->status === 'running' && $this->runningClaimLive($artifact, $now)) {
                $state = 'preparing';
            }
        }
        $retry = max(0, $this->retryDeadline($artifact?->preparation_fingerprint === $identity->fingerprint ? $artifact : null, $receipt)->getTimestamp() - $now->getTimestamp());
        $busy = $artifact !== null && ($this->runningClaimLive($artifact, $now) || ($current && $this->pendingFresh($artifact, $now)));

        $provenance = app(ConversationAssistPresentation::class)->provenance($conversation, $displayArtifact);

        return ['task' => $purpose, 'mode' => $identity->mode, 'state' => $state, 'output' => $output,
            'output_generation' => $displayArtifact?->generation,
            'generation' => $current ? $artifact->generation : $receipt?->generation,
            'policy_revision' => $identity->policyRevision, 'retry_after' => $retry,
            'can_use' => $state === 'ready',
            'can_prepare' => $identity->state === 'eligible' && ! $busy && ($receipt === null || $retry === 0),
            'source_message_count' => $provenance['source_message_count'] ?? null,
            'source_position' => $provenance['source_position'] ?? null,
            'source_ordinal' => $provenance['source_ordinal'] ?? null, 'source_url' => $provenance['source_url'] ?? null];
    }

    /** Use is a fresh read capability; it never inserts, submits, or sends. */
    public function use(User $actor, Conversation $conversation, string $purpose, string $generation): array
    {
        $accountId = $this->identities->forTask($actor, $conversation, $purpose)->accountId;

        return DB::transaction(function () use ($actor, $conversation, $purpose, $generation, $accountId): array {
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $class = AiSupportUsage::ARTIFACTS[$purpose];
            $class::query()->where('conversation_id', $conversation->id)->lockForUpdate()->first();
            $state = $this->read($actor, $conversation, $purpose);
            if (! $state['can_use'] || $state['generation'] !== $generation) {
                throw new AiNetworkException('context_changed');
            }

            return $state;
        });
    }

    /** Worker failure/publication sync, conditional on the captured generation. */
    public function syncTerminal(Model $artifact): void
    {
        if (! is_string($artifact->preparation_fingerprint)) {
            return;
        }
        DB::transaction(function () use ($artifact): void {
            $fresh = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)->lockForUpdate()->first();
            if ($fresh === null || ! in_array($fresh->status, ['ready', 'failed', 'insufficient_context'], true)) {
                return;
            }
            $now = $this->clock->now()->utc();
            $deadline = $this->retryDeadline($fresh, null)->max($now->addSeconds(60));
            $fresh->forceFill(['previous_output' => null, 'retry_not_before' => $deadline])->save();
            AiAssistPreparationReceipt::query()->where('fingerprint', $artifact->preparation_fingerprint)
                ->where('conversation_id', $artifact->conversation_id)->where('generation', $artifact->generation)
                ->update(['status' => $fresh->status, 'completed_at' => $now, 'retry_not_before' => $deadline]);
        });
    }

    /** Called inside publication's usage transaction; late generations cannot withdraw a replacement. */
    public function withdrawPublication(Model $artifact, string $code = 'context_changed'): void
    {
        $purpose = array_search($artifact::class, AiSupportUsage::ARTIFACTS, true);
        if ($purpose === false) {
            throw new AiNetworkException('execution_identity_invalid');
        }
        $changed = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)
            ->whereIn('status', ['pending', 'running', 'ready'])->update([
                'status' => 'failed', 'failure_code' => in_array($code, ['policy_changed', 'connection_changed', 'access_revoked', 'ticket_exists'], true) ? $code : 'context_changed', 'completed_at' => $this->clock->now(),
                'source_context_fingerprint' => null, ...array_fill_keys(self::OUTPUT[$purpose], null),
            ]);
        if ($changed === 1) {
            $this->syncTerminal($artifact);
        }
    }

    /** Keep legacy event names and only the admitted generation's sanitized metadata. */
    private function auditFailure(AuditEvent $requested, string $code): void
    {
        $requested = $requested->fresh() ?? $requested;
        AuditEvent::query()->create([
            ...$requested->only(['account_id', 'site_id', 'actor_type', 'actor_id', 'subject_type', 'subject_id']),
            'action' => Str::beforeLast($requested->action, '.').'.failed',
            'metadata' => [...$requested->metadata, 'failure_code' => $code],
            'occurred_at' => $this->clock->now(),
        ]);
    }

    private function receipt(AiAssistPreparationIdentity $identity): Builder
    {
        return AiAssistPreparationReceipt::query()->where('account_id', $identity->accountId)
            ->where('conversation_id', $identity->conversationId)->where('purpose', $identity->purpose)
            ->where('fingerprint', $identity->fingerprint);
    }

    private function readableSource(Model $artifact, AiAssistPreparationIdentity $identity, Conversation $conversation, User $actor): bool
    {
        $snapshot = $artifact->preparation_source_snapshot;

        return is_string($artifact->source_context_fingerprint) && $artifact->source_context_fingerprint !== ''
            && $artifact->connection_revision === $identity->connectionRevision
            && $artifact->policy_revision === $identity->policyRevision && is_array($snapshot)
            && ($snapshot['catalogue_revision'] ?? null) === $identity->catalogueRevision
            && app(ConversationTextContextSelector::class)->sourceStillCurrent($conversation, $snapshot)
            && (! $artifact instanceof ConversationCopilotReplyDraft || ! ($identity->sourceSnapshot['grounding_enabled'] ?? false)
                || app(ConversationGroundedReplyPresentation::class)->isCurrent($artifact, $actor, $conversation));
    }

    private function pendingFresh(Model $artifact, CarbonImmutable $now): bool
    {
        $clock = $artifact->status === 'running' ? $artifact->started_at : $artifact->requested_at;

        return in_array($artifact->status, ['pending', 'running'], true) && $clock !== null && $clock->isAfter($now->subMinutes(5));
    }

    private function runningClaimLive(Model $artifact, CarbonImmutable $now): bool
    {
        if ($artifact->status !== 'running') {
            return false;
        }
        if ($this->pendingFresh($artifact, $now)) {
            return true;
        }
        $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->first();
        $deadline = match ($attempt?->state) {
            'claimed' => $attempt->claim_expires_at, 'dispatched', 'unknown' => $attempt->dispatch_expires_at,
            default => null,
        };

        return $deadline !== null && CarbonImmutable::parse($deadline, 'UTC')->isAfter($now);
    }

    private function retryDeadline(?Model $artifact, ?AiAssistPreparationReceipt $receipt): CarbonImmutable
    {
        $deadline = CarbonImmutable::createFromTimestampUTC(0);
        foreach ([$artifact?->retry_not_before, $receipt?->retry_not_before] as $candidate) {
            if ($candidate !== null) {
                $deadline = $deadline->max(CarbonImmutable::parse($candidate, 'UTC'));
            }
        }
        $generations = array_values(array_unique(array_filter([$artifact?->generation, $receipt?->generation])));
        if ($generations !== []) {
            $candidate = DB::table('ai_usage_attempts')->whereIn('attempt_id', $generations)->max('retry_not_before');
            if ($candidate !== null) {
                $deadline = $deadline->max(CarbonImmutable::parse($candidate, 'UTC'));
            }
        }

        return $deadline;
    }

    private function output(Model $artifact, AiAssistPreparationIdentity $identity): array
    {
        return match ($identity->purpose) {
            'conversation_summary' => ['text' => $artifact->summary],
            'conversation_reply_draft' => ['text' => $artifact->draft] + (is_array($artifact->grounding)
                ? ['grounding' => app(ConversationGroundedReplyPresentation::class)->output($artifact, $artifact->conversation)] : []),
            'conversation_ticket_suggestion' => ['title' => $artifact->title, 'priority' => $artifact->priority,
                'label_ids' => Account::query()->findOrFail($identity->accountId)->ticketLabels()
                    ->whereIn('id', $artifact->suggestedLabelIds())->pluck('id')->all()],
            'conversation_knowledge_suggestion' => ['articles' => app(KnowledgeArticleSuggestionMatcher::class)
                ->present(Account::query()->findOrFail($identity->accountId), $artifact->suggestedArticleIds())
                ->map(fn ($item) => ['id' => $item['article']->id, 'title' => $item['article']->title,
                    'snippet' => $item['snippet'], 'revision' => KnowledgeArticleSuggestionMatcher::revision($item['article'], $item['snippet']),
                    'source_url' => route('dashboard.conversations.assist.sources.show', ['supportCode' => $artifact->conversation->support_code,
                        'article' => $item['article']->id, 'generation' => $artifact->generation,
                        'article_revision' => KnowledgeArticleSuggestionMatcher::revision($item['article'], $item['snippet'])])])->all()],
        };
    }
}
