<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Enums\AccountPermission;
use App\Models\AccountAiConnection;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

/** Called inside the ledger's installation -> Account transaction. */
final readonly class AiRequestAuthority
{
    public function __construct(private AgentCopilotConnectionResolver $connections) {}

    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] AiExecutionContext $context, bool $beforeQueue = false): void
    {
        if (DB::transactionLevel() < 1 || $context->accountId !== $connection->accountId) {
            throw new AiNetworkException('execution_identity_invalid');
        }
        $actor = User::query()->whereKey($context->actorId)->where('account_id', $context->accountId)->lockForUpdate()->first();
        if ($actor === null || $actor->isDeactivated()) {
            throw new AiNetworkException('access_revoked');
        }
        if ($context->kind === 'refinement') {
            app(DraftRefinements::class)->assertAllowed($actor, $connection, $context, $beforeQueue);

            return;
        }
        if ($context->kind === 'support') {
            $class = AiSupportUsage::ARTIFACTS[$context->purpose] ?? null;
            if ($class === null || $context->artifactId === null || $context->generation !== $context->attemptId) {
                throw new AiNetworkException('execution_identity_invalid');
            }
            $ability = match ($context->purpose) {
                'conversation_reply_draft', 'conversation_knowledge_suggestion' => 'reply',
                'conversation_ticket_suggestion' => 'createTicket',
                default => 'view',
            };
            $artifact = $class::query()->with('conversation.site.account')->whereKey($context->artifactId)->lockForUpdate()->first();
            if ($artifact === null || $artifact->generation !== $context->generation
                || (int) $artifact->requested_by_id !== $actor->id
                || ! in_array($artifact->status, $beforeQueue ? ['pending', 'running'] : ['running'], true)
                || $artifact->connection_revision !== $connection->revision
                || (int) $artifact->conversation?->site?->account_id !== $context->accountId
                || ! Gate::forUser($actor)->allows($ability, $artifact->conversation)) {
                throw new AiNetworkException('access_revoked');
            }
            $policy = app(AiAssistPolicyResolver::class)->forConversation($artifact->conversation);
            if (! app(AiAssistPolicyResolver::class)->isCurrent((int) $artifact->conversation->site_id,
                $context->purpose, $artifact->policy_revision)
                || $context->policyRevision !== $artifact->policy_revision
                || ($context->maxContextCharacters !== null && $context->maxContextCharacters !== $policy->maxContextCharacters)) {
                throw new AiNetworkException('access_revoked');
            }
            if (($context->purpose === 'conversation_knowledge_suggestion' && ! $artifact->conversation->site->account->articles()->published()->exists())
                || ($context->purpose === 'conversation_ticket_suggestion' && $artifact->conversation->tickets()->exists())) {
                throw new AiNetworkException('context_changed');
            }
            if (! $this->connections->isCurrentConnection($connection)) {
                throw new AiNetworkException('connection_changed');
            }
            AiSupportUsage::assertPreparationCurrent($artifact);
            $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $context->attemptId)->first();
            if (! $beforeQueue && ($attempt === null || ! hash_equals($attempt->work_fingerprint, AiSupportUsage::workFingerprint($artifact, $connection)))) {
                throw new AiNetworkException('context_changed');
            }

            return;
        }
        if (! $actor->hasAccountPermission(AccountPermission::ManageAiConnections)) {
            throw new AiNetworkException('access_revoked');
        }
        $record = AccountAiConnection::query()->where('account_id', $context->accountId)->lockForUpdate()->first();
        if ($record === null || $record->proposed_revision !== $context->proposedRevision
            || ($context->kind === 'probe' && ($record->test_generation !== $context->testGeneration || $record->test_status !== 'running'))) {
            throw new AiNetworkException('connection_changed');
        }
        $values = $record->proposedConfiguration();
        $candidate = $values === null ? null : ($context->kind === 'discovery'
            ? $this->connections->candidateForDiscovery($context->accountId, $record->proposed_revision, $values)
            : $this->connections->candidate($context->accountId, $record->proposed_revision, $values));
        if ($candidate === null || $candidate->source !== $connection->source || ! hash_equals($candidate->revision, $connection->revision)) {
            throw new AiNetworkException('connection_changed');
        }
    }
}
