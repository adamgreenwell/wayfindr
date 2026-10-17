<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Conversation;
use App\Models\ConversationDraftRefinement;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\AssistFeedback;
use App\Support\Ai\Observability\DraftRefinementAudit;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/** Private lifecycle, using the ledger's installation -> account -> actor -> conversation lock order. */
final readonly class DraftRefinements
{
    public function __construct(private AiUsageLedger $ledger, private AiUsageClock $clock,
        private ConversationDraftRefinementPromptBuilder $builder, private AgentCopilotConnectionResolver $connections) {}

    /** Allocate a cancellable, content-free slot before the browser sends unsent text. */
    public function authorize(User $actor, Conversation $conversation, string $revision): array
    {
        if (! Str::isUuid($revision)) {
            throw new AiNetworkException('execution_identity_invalid');
        }

        return $this->locked((int) $actor->account_id, function () use ($actor, $conversation, $revision): array {
            $actor = User::query()->whereKey($actor->id)->where('account_id', $actor->account_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::query()->with('site')->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $policy = app(AiAssistPolicyResolver::class)->forConversation($conversation);
            $connection = $this->connections->forAccount((int) $actor->account_id);
            if ($actor->isDeactivated() || ! Gate::forUser($actor)->allows('reply', $conversation)
                || ! $policy->allows(ConversationDraftRefinementPromptBuilder::PURPOSE) || $connection === null) {
                throw new AiNetworkException('access_revoked');
            }
            $previous = ConversationDraftRefinement::query()->where('conversation_id', $conversation->id)
                ->where('requested_by_id', $actor->id)->lockForUpdate()->first();
            $this->assertRetryAllowed($previous, (int) $actor->account_id, (int) $actor->id, $connection->revision);
            if ($previous !== null && $previous->operation !== []) {
                $this->ledger->cancelReserved($previous->generation, 'superseded');
                if (in_array($previous->status, ['pending', 'running', 'ready'], true)) {
                    app(DraftRefinementAudit::class)->record($previous, 'superseded');
                }
            }
            $row = $previous ?? new ConversationDraftRefinement;
            $row->forceFill(['account_id' => $actor->account_id, 'conversation_id' => $conversation->id, 'requested_by_id' => $actor->id,
                'generation' => (string) Str::uuid(), 'draft_revision' => $revision, 'operation' => [], 'status' => 'authorized',
                'connection_revision' => $connection->revision, 'policy_revision' => $policy->revisionFor(ConversationDraftRefinementPromptBuilder::PURPOSE),
                'work_fingerprint' => '', 'input' => null, 'scope' => null, 'preview' => null, 'failure_code' => null,
                'requested_at' => $this->clock->now(), 'expires_at' => $this->clock->now()->addSeconds(900),
                'started_at' => null, 'completed_at' => null])->save();

            return $this->receipt($row);
        });
    }

    public function request(User $actor, Conversation $conversation, #[SensitiveParameter] DraftRefinementInput $input, ?string $captureGeneration = null): array
    {
        $row = $this->locked((int) $actor->account_id, function () use ($actor, $conversation, $input, $captureGeneration): ConversationDraftRefinement {
            User::query()->whereKey($actor->id)->where('account_id', $actor->account_id)->lockForUpdate()->firstOrFail();
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $previous = ConversationDraftRefinement::query()->where('conversation_id', $conversation->id)
                ->where('requested_by_id', $actor->id)->lockForUpdate()->first();
            // HTTP capture can only consume the exact live grant. Deleted, cancelled, expired
            // or superseded grants never recreate work, even after a late request arrives.
            if ($captureGeneration !== null && ($previous === null || $previous->generation !== $captureGeneration
                || $previous->status !== 'authorized' || $previous->draft_revision !== $input->draftRevision
                || ! $previous->expires_at->isAfter($this->clock->now()))) {
                throw new AiNetworkException('execution_superseded');
            }
            $context = $this->builder->build($actor, $conversation, $input);
            $scope = $context->sourceSnapshot();
            if ($captureGeneration !== null && ($previous->connection_revision !== $scope['connection_revision']
                || $previous->policy_revision !== $scope['policy_revision'])) {
                throw new AiNetworkException('context_changed');
            }
            $this->assertRetryAllowed($previous, (int) $actor->account_id, (int) $actor->id, $scope['connection_revision']);
            if ($previous !== null && $previous->operation !== []) {
                $this->ledger->cancelReserved($previous->generation, 'superseded');
                if (in_array($previous->status, ['pending', 'running', 'ready'], true)) {
                    app(DraftRefinementAudit::class)->record($previous, 'superseded');
                }
            }
            $connection = $this->connections->forAccount((int) $actor->account_id);
            if ($connection === null) {
                throw new AiNetworkException('connection_changed');
            }
            $prompt = $context->providerPrompt();
            // Bound the nested wire JSON (including configured body metadata) before persistence.
            // The fixed SDK wrappers fit within 5 KiB; transport still validates the actual body.
            $wireBound = strlen(json_encode([$prompt->instructions, $prompt->input, $connection->model, $connection->openRouterProvider],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 5120;
            if ($wireBound > 65536) {
                throw new AiNetworkException('context_unavailable');
            }
            $bound = $wireBound + 1024;
            $row = $previous ?? new ConversationDraftRefinement;
            $row->forceFill(['account_id' => $actor->account_id, 'conversation_id' => $conversation->id, 'requested_by_id' => $actor->id,
                'generation' => $captureGeneration ?? (string) Str::uuid(), 'draft_revision' => $input->draftRevision, 'operation' => $input->operation(),
                'connection_revision' => $scope['connection_revision'], 'policy_revision' => $scope['policy_revision'],
                'work_fingerprint' => hash_hmac('sha256', json_encode(['private-refinement-v1', $scope], JSON_THROW_ON_ERROR), (string) config('app.key')),
                'status' => 'pending', 'input' => ['instructions' => $prompt->instructions, 'input' => $prompt->input],
                'scope' => ['snapshot' => $scope, 'sources' => $context->providerSources(), 'guard_identity' => app(DraftRefinementSafetyGuard::class)->identity(),
                    'audit_route' => app(DraftRefinementAudit::class)->route($connection)], 'preview' => null, 'failure_code' => null,
                'requested_at' => CarbonImmutable::createFromTimestampUTC($scope['created_at']), 'expires_at' => CarbonImmutable::createFromTimestampUTC($scope['expires_at']),
                'started_at' => null, 'completed_at' => null])->save();
            $row->load('conversation.site');
            $output = min(2048, app(AiUsagePolicy::class)->forConnection($connection)->maxOutputTokens);
            $reservation = $this->ledger->reserve($connection, ConversationDraftRefinementPromptBuilder::PURPOSE,
                $row->generation, $bound, $output, $row->work_fingerprint, (int) $actor->id,
                authorize: fn () => $this->assertAllowed($actor, $connection, $this->execution($row), true));
            if ($reservation->attemptId !== $row->generation || $reservation->state !== 'reserved') {
                throw new AiNetworkException('usage_attempt_reused');
            }
            app(DraftRefinementAudit::class)->record($row, 'requested');

            return $row;
        });
        try {
            GenerateConversationDraftRefinement::dispatch((int) $row->id, $row->generation)->afterCommit();
        } catch (Throwable) {
            $this->fail((int) $row->id, $row->generation, 'queue_failed');
            throw new AiNetworkException('queue_failed');
        }

        return $this->receipt($row);
    }

    public function show(User $actor, Conversation $conversation, string $generation, string $revision, bool $apply = false): array
    {
        return $this->locked((int) $actor->account_id, function () use ($actor, $conversation, $generation, $revision, $apply): array {
            $row = $this->owned($actor, $conversation, $generation);
            if ($row === null) {
                throw new AiNetworkException('execution_superseded');
            }
            if (! in_array($row->status, ['pending', 'running', 'ready'], true)) {
                if ($apply) {
                    throw new AiNetworkException('context_changed');
                }

                return $this->receipt($row);
            }
            if (! $this->current($row, $revision)) {
                $this->withdraw($row, 'stale', 'context_changed');

                return $this->receipt($row);
            }
            if ($apply && $row->status !== 'ready') {
                throw new AiNetworkException('context_changed');
            }
            $receipt = $this->receipt($row);
            if ($row->status === 'ready') {
                $receipt['preview'] = $row->preview;
                $receipt['sources'] = array_values(array_filter($row->scope['sources'] ?? [],
                    fn (array $source): bool => in_array($source['ref'], $row->preview['citations'] ?? [], true)));
                if ($apply) {
                    $this->withdraw($row, 'applied');
                    $receipt['feedback_receipt'] = app(AssistFeedback::class)->receipt($actor, $conversation, 'conversation_draft_refinement', $generation, $row->expires_at->getTimestamp());
                }
            }

            return $receipt;
        });
    }

    public function cancel(User $actor, Conversation $conversation, string $generation): void
    {
        $this->locked((int) $actor->account_id, function () use ($actor, $conversation, $generation): void {
            $row = $this->owned($actor, $conversation, $generation);
            if ($row !== null && in_array($row->status, ['authorized', 'pending', 'running', 'ready'], true)) {
                $this->withdraw($row, 'cancelled');
            }
        });
    }

    /** A fresh erasure-visible claim is committed before any provider code can run. */
    public function claim(int $id, string $generation): ?ConversationDraftRefinement
    {
        $hint = ConversationDraftRefinement::query()->find($id);
        if ($hint === null) {
            return null;
        }

        return $this->locked((int) $hint->account_id, function () use ($id, $generation, $hint): ?ConversationDraftRefinement {
            User::query()->whereKey($hint->requested_by_id)->lockForUpdate()->first();
            Conversation::query()->whereKey($hint->conversation_id)->lockForUpdate()->first();
            $row = ConversationDraftRefinement::query()->with(['conversation.site', 'requestedBy'])->whereKey($id)
                ->where('generation', $generation)->where('status', 'pending')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $connection = $this->connections->forAccount((int) $row->account_id);
            if ($connection === null || $row->requestedBy === null) {
                $this->withdraw($row, 'failed', 'access_revoked');

                return null;
            }
            $this->assertAllowed($row->requestedBy, $connection, $this->execution($row), true);
            $row->forceFill(['status' => 'running', 'started_at' => $this->clock->now()])->save();
            DB::table('conversation_draft_refinement_sends')->insert(['account_id' => $row->account_id,
                'conversation_id' => $row->conversation_id, 'generation' => $generation, 'started_at' => $this->clock->now()]);
            app(DraftRefinementAudit::class)->record($row, 'started');

            return $row;
        });
    }

    /** Invoked by the existing guarded authority inside its account transaction. */
    public function assertAllowed(User $actor, ResolvedAgentCopilotConnection $connection, AiExecutionContext $execution, bool $beforeQueue): void
    {
        $hint = ConversationDraftRefinement::query()->whereKey($execution->artifactId)->first();
        if ($hint !== null) {
            Conversation::query()->whereKey($hint->conversation_id)->lockForUpdate()->first();
        }
        $row = ConversationDraftRefinement::query()->with(['conversation.site', 'requestedBy'])->whereKey($execution->artifactId)
            ->where('generation', $execution->attemptId)->lockForUpdate()->first();
        if (DB::transactionLevel() < 1 || $execution->kind !== 'refinement'
            || $execution->purpose !== ConversationDraftRefinementPromptBuilder::PURPOSE || $execution->generation !== $execution->attemptId
            || $row === null || (int) $row->requested_by_id !== (int) $actor->id || $execution->actorId !== (int) $actor->id
            || (int) $row->account_id !== $execution->accountId || $connection->accountId !== $execution->accountId
            || ! in_array($row->status, $beforeQueue ? ['pending', 'running'] : ['running'], true)
            || $execution->policyRevision !== $row->policy_revision || $connection->revision !== $row->connection_revision
            || ! $this->connections->isCurrentConnection($connection) || ! $this->current($row, $row->draft_revision)
            || $execution->maxContextCharacters !== ($row->scope['snapshot']['max_context_characters'] ?? null)
            || ! $this->builder->isCurrent($actor, $row->conversation, $this->context($row), $row->draft_revision)) {
            throw new AiNetworkException('access_revoked');
        }
        if (! $beforeQueue && ! DB::table('ai_usage_attempts')->where('attempt_id', $execution->attemptId)
            ->where('work_fingerprint', $row->work_fingerprint)->exists()) {
            throw new AiNetworkException('context_changed');
        }
        if (! $beforeQueue && ! DB::table('conversation_draft_refinement_sends')->where('generation', $execution->attemptId)
            ->where('conversation_id', $row->conversation_id)->where('account_id', $row->account_id)
            ->where('started_at', '>', $this->clock->now()->subSeconds(180))->exists()) {
            throw new AiNetworkException('access_revoked');
        }
    }

    public function assertActualPrompt(ResolvedAgentCopilotConnection $connection, AiExecutionContext $execution,
        #[SensitiveParameter] AgentCopilotPrompt $prompt): void
    {
        $row = ConversationDraftRefinement::query()->with(['conversation.site', 'requestedBy'])->whereKey($execution->artifactId)
            ->where('generation', $execution->attemptId)->first();
        if ($row === null || $row->requestedBy === null || $connection->revision !== $row->connection_revision
            || ! $this->builder->isCurrent($row->requestedBy, $row->conversation,
                new DraftRefinementContext($prompt, $row->scope['snapshot'] ?? [], $row->scope['sources'] ?? []), $row->draft_revision)
            || AiSupportUsage::sanitizedInput($prompt) !== $prompt->input) {
            throw new AiNetworkException('context_changed');
        }
    }

    public function execution(#[SensitiveParameter] ConversationDraftRefinement $row): AiExecutionContext
    {
        return new AiExecutionContext('refinement', (int) $row->account_id, (int) $row->requested_by_id,
            ConversationDraftRefinementPromptBuilder::PURPOSE, $row->generation, (int) $row->id, $row->generation,
            policyRevision: $row->policy_revision, maxContextCharacters: $row->scope['snapshot']['max_context_characters'] ?? null);
    }

    public function context(#[SensitiveParameter] ConversationDraftRefinement $row): DraftRefinementContext
    {
        $input = $row->input;
        if (! is_array($input) || ! is_string($input['instructions'] ?? null) || ! is_string($input['input'] ?? null)) {
            throw new AiNetworkException('context_unavailable');
        }

        return new DraftRefinementContext(new AgentCopilotPrompt(ConversationDraftRefinementPromptBuilder::PURPOSE,
            $input['instructions'], $input['input'], 75), $row->scope['snapshot'] ?? [], $row->scope['sources'] ?? []);
    }

    public function complete(int $id, string $generation, #[SensitiveParameter] AgentCopilotResult $result,
        #[SensitiveParameter] DraftRefinementProposal $proposal): void
    {
        $hint = ConversationDraftRefinement::query()->find($id);
        if ($hint === null) {
            $result->settle('invalid_output');

            return;
        }
        $this->locked((int) $hint->account_id, function () use ($id, $generation, $result, $proposal, $hint): void {
            User::query()->whereKey($hint->requested_by_id)->lockForUpdate()->first();
            Conversation::query()->whereKey($hint->conversation_id)->lockForUpdate()->first();
            $row = ConversationDraftRefinement::query()->with(['conversation.site', 'requestedBy'])->whereKey($id)
                ->where('generation', $generation)->where('status', 'running')->lockForUpdate()->first();
            if ($row === null || ! $this->current($row, $row->draft_revision) || ! $result->isGuarded()
                || ! DB::table('ai_usage_attempts')->where('attempt_id', $generation)->whereIn('state', ['dispatched', 'unknown'])->whereNotNull('dispatch_nonce')->exists()) {
                if ($row !== null) {
                    $this->withdraw($row, 'stale', 'context_changed', $result);
                }
                $result->settle('invalid_output');

                return;
            }
            $row->forceFill(['status' => 'ready', 'input' => null, 'preview' => $proposal->previewData(),
                'completed_at' => $this->clock->now(), 'failure_code' => null])->save();
            // Recheck both sides of publication, under the same lock as settlement.
            if (! $this->current($row->fresh(['conversation.site', 'requestedBy']), $row->draft_revision)) {
                $this->withdraw($row, 'stale', 'context_changed', $result);
                $result->settle('invalid_output');
            } else {
                $result->settle('completed');
                app(DraftRefinementAudit::class)->record($row, 'ready', result: $result);
            }
        });
    }

    public function fail(int $id, string $generation, string $code, #[SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        $hint = ConversationDraftRefinement::query()->find($id);
        if ($hint !== null) {
            $this->locked((int) $hint->account_id, function () use ($id, $generation, $code, $result): void {
                $row = ConversationDraftRefinement::query()->whereKey($id)->where('generation', $generation)
                    ->whereIn('status', ['pending', 'running', 'ready'])->lockForUpdate()->first();
                if ($row !== null) {
                    $this->withdraw($row, 'failed', $code, $result);
                }
            });
        }
    }

    public function cleanup(): int
    {
        DB::table('conversation_draft_refinement_sends')->where('started_at', '<=', $this->clock->now()->subSeconds(180))->delete();

        // Bound per-minute audit/delete work; ciphertext remains unusable at fixed expiry.
        $deleted = 0;
        $hints = ConversationDraftRefinement::query()->where('expires_at', '<=', $this->clock->now())->orderBy('id')->limit(500)->get(['id', 'account_id']);
        foreach ($hints as $hint) {
            try {
                $deleted += $this->locked((int) $hint->account_id, function () use ($hint): int {
                    $row = ConversationDraftRefinement::query()->whereKey($hint->id)->where('expires_at', '<=', $this->clock->now())->lockForUpdate()->first();
                    if ($row === null) {
                        return 0;
                    }
                    if (in_array($row->status, ['pending', 'running', 'ready'], true)) {
                        app(DraftRefinementAudit::class)->record($row, 'expired');
                    }

                    return $row->delete() ? 1 : 0;
                });
            } catch (AiNetworkException $exception) {
                // A concurrently erased parent already removed the slot; never recreate it.
                if (ConversationDraftRefinement::query()->whereKey($hint->id)->exists()) {
                    throw $exception;
                }
            }
        }

        return $deleted;
    }

    /** Read-only feedback check; caller holds the owning account/actor/parent/slot locks. */
    public function previewIsCurrent(#[SensitiveParameter] ConversationDraftRefinement $row): bool
    {
        return $row->status === 'ready' && $this->current($row, $row->draft_revision);
    }

    private function current(#[SensitiveParameter] ConversationDraftRefinement $row, string $revision): bool
    {
        return $row->expires_at?->isAfter($this->clock->now()) === true && $row->draft_revision === $revision
            && $row->requestedBy !== null && $row->conversation !== null && is_array($row->scope)
            && ($row->scope['guard_identity'] ?? null) === app(DraftRefinementSafetyGuard::class)->identity()
            && ($row->scope['snapshot']['operation'] ?? null) === $row->operation
            && $this->builder->scopeIsCurrent($row->requestedBy, $row->conversation,
                $row->scope['snapshot'] ?? [], $row->scope['sources'] ?? [], $revision);
    }

    private function owned(User $actor, Conversation $conversation, string $generation): ?ConversationDraftRefinement
    {
        $actor = User::query()->whereKey($actor->id)->where('account_id', $actor->account_id)->lockForUpdate()->first();
        $conversation = Conversation::query()->with('site')->whereKey($conversation->id)->lockForUpdate()->first();
        if ($actor === null || $actor->isDeactivated() || $conversation === null || ! Gate::forUser($actor)->allows('reply', $conversation)) {
            throw new AiNetworkException('access_revoked');
        }

        return ConversationDraftRefinement::query()->with(['conversation.site', 'requestedBy'])
            ->where('account_id', $actor->account_id)->where('requested_by_id', $actor->id)
            ->where('conversation_id', $conversation->id)->where('generation', $generation)->lockForUpdate()->first();
    }

    private function withdraw(#[SensitiveParameter] ConversationDraftRefinement $row, string $status, ?string $code = null, #[SensitiveParameter] ?AgentCopilotResult $result = null): void
    {
        app(DraftRefinementAudit::class)->record($row, $status, $code, $result);
        if ($row->operation !== []) {
            $this->ledger->cancelReserved($row->generation, 'cancelled');
        }
        $row->forceFill(['status' => $status, 'input' => null, 'scope' => null, 'preview' => null,
            'failure_code' => $code, 'completed_at' => $this->clock->now()])->save();
    }

    private function receipt(#[SensitiveParameter] ConversationDraftRefinement $row): array
    {
        return ['generation' => $row->generation, 'draft_revision' => $row->draft_revision,
            'policy_revision' => $row->policy_revision, 'state' => $row->status, 'expires_at' => $row->expires_at->getTimestamp(),
            'failure_code' => $row->failure_code, 'ttl_seconds' => max(0, $row->expires_at->getTimestamp() - $this->clock->now()->getTimestamp()), 'preview' => null, 'sources' => []];
    }

    private function assertRetryAllowed(?ConversationDraftRefinement $previous, int $accountId, int $actorId, string $connectionRevision): void
    {
        // Allocating/cancelling a content-free grant consumes no attempt and cannot reset a captured cooldown.
        $retry = ($previous?->operation ?? []) === [] ? null : $previous?->requested_at?->addSeconds(60);
        $providerRetry = DB::table('ai_usage_attempts')->where('account_id', $accountId)->where('requester_id', $actorId)
            ->where('purpose', ConversationDraftRefinementPromptBuilder::PURPOSE)->where('connection_revision', $connectionRevision)->max('retry_not_before');
        if ($providerRetry !== null) {
            $retry = $retry === null ? CarbonImmutable::parse($providerRetry, 'UTC') : $retry->max(CarbonImmutable::parse($providerRetry, 'UTC'));
        }
        if ($retry !== null && $this->clock->now()->lessThan($retry)) {
            throw new AiNetworkException('usage_retry_cooldown', retryAfterSeconds: max(1, $retry->getTimestamp() - $this->clock->now()->getTimestamp()));
        }
    }

    private function locked(int $accountId, Closure $operation): mixed
    {
        return $this->ledger->withAccountUsageLock($accountId, $operation);
    }
}
