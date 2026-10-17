<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Models\AiAssistPreparationReceipt;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\ConversationCopilotSummary;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\AiContextSanitizer;
use App\Support\Ai\ConversationKnowledgeSuggestionPromptBuilder;
use App\Support\Ai\ConversationReplyDraftPromptBuilder;
use App\Support\Ai\ConversationSummaryPromptBuilder;
use App\Support\Ai\ConversationTicketSuggestionPromptBuilder;
use App\Support\Ai\Grounding\ConversationGroundedReplyPresentation;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Preparation\AiAssistPreparationIdentityResolver;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/** Enroll the existing explicit manual jobs without changing upgrade behavior. */
final readonly class AiSupportUsage
{
    public const ARTIFACTS = [
        'conversation_summary' => ConversationCopilotSummary::class,
        'conversation_reply_draft' => ConversationCopilotReplyDraft::class,
        'conversation_knowledge_suggestion' => ConversationCopilotKnowledgeSuggestion::class,
        'conversation_ticket_suggestion' => ConversationCopilotTicketSuggestion::class,
    ];

    public function __construct(private AgentCopilotConnectionResolver $connections, private AiUsageLedger $ledger,
        private AiUsagePolicy $policy, private AiRequestAuthority $authority) {}

    /** Claim a prepared artifact in the same lock order as its admission. */
    public function claimPrepared(Model $artifact): int
    {
        return $this->ledger->withAccountUsageLock((int) $artifact->conversation?->site?->account_id,
            function () use ($artifact): int {
                User::query()->whereKey($artifact->requested_by_id)->lockForUpdate()->first();
                Conversation::query()->whereKey($artifact->conversation_id)->lockForUpdate()->first();
                $fresh = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)
                    ->where('status', 'pending')->lockForUpdate()->first();
                if ($fresh === null) {
                    return 0;
                }
                self::assertPreparationCurrent($fresh);

                return $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)
                    ->where('status', 'pending')->update(['status' => 'running', 'started_at' => app(AiUsageClock::class)->now()]);
            });
    }

    public static function lifecycleNow(Model $artifact): CarbonInterface
    {
        return is_string($artifact->preparation_fingerprint) ? app(AiUsageClock::class)->now() : now();
    }

    public function reserveFor(#[SensitiveParameter] Model $artifact): void
    {
        $fresh = $this->fresh($artifact);
        $connection = $this->connections->forAccount((int) $fresh->conversation?->site?->account_id);
        if ($connection === null || $fresh->connection_revision !== $connection->revision) {
            throw new AiNetworkException('connection_changed');
        }
        $this->assertPolicy($fresh);
        if ($this->connections->isLegacyInstallationDefault($connection)) {
            return;
        }
        $context = $this->context($fresh);
        $prompt = self::promptFor($fresh);
        $input = self::sanitizedInput($prompt);
        // Conservative protocol envelope, checked against the actual SDK JSON
        // again at dispatch. Unexpected SDK growth fails before transmission.
        $bound = min(66_560, strlen(json_encode([$prompt->instructions, $input], JSON_THROW_ON_ERROR)) + 5_120);
        $output = min(2_048, $this->policy->forConnection($connection)->maxOutputTokens);
        $fingerprint = self::workFingerprint($fresh, $connection);
        $latest = DB::table('ai_usage_attempts')->where('account_id', $connection->accountId)
            ->where('source', $connection->source)->where('connection_revision', $connection->revision)
            ->where('purpose', $context->purpose)->where('work_fingerprint', $fingerprint)->orderByDesc('id')->first();
        if ($latest !== null && $latest->attempt_id !== $context->attemptId) {
            // The controller may replace a five-minute-old generation while
            // its queue/claim/dispatch receipt is still live. Retire only that
            // equivalent work, after proving this new generation still owns it.
            $this->ledger->retireSuperseded($connection, $context->purpose, $latest->attempt_id, $fingerprint,
                function () use ($fresh, $connection, $context, $fingerprint, $latest): bool {
                    $current = $fresh::query()->with('conversation.site')->whereKey($fresh->getKey())
                        ->where('generation', $context->generation)->lockForUpdate()->first();
                    if ($current === null || $current->generation === $latest->attempt_id) {
                        return false;
                    }
                    $this->authority->assertAllowed($connection, $context, true);

                    return hash_equals($fingerprint, self::workFingerprint($current, $connection));
                });
        }
        $receipt = $this->ledger->reserve($connection, $context->purpose, $context->attemptId, $bound, $output,
            $fingerprint, $context->actorId, $latest?->attempt_id,
            fn () => $this->authority->assertAllowed($connection, $context, true));
        if ($receipt->attemptId !== $context->attemptId || $receipt->state !== 'reserved') {
            throw new AiNetworkException('usage_attempt_reused');
        }
    }

    /** Publish and settle atomically in installation -> Account order. */
    public function publish(#[SensitiveParameter] Model $artifact, #[SensitiveParameter] AgentCopilotResult $result, #[SensitiveParameter] Closure $publication): void
    {
        if (is_string($artifact->preparation_fingerprint)) {
            if ($result->isGuarded() && ! DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->exists()) {
                throw new AiNetworkException('usage_reservation_missing');
            }
            $this->ledger->withAccountUsageLock((int) $artifact->conversation?->site?->account_id,
                function () use ($artifact, $result, $publication): void {
                    $fresh = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)
                        ->where('status', 'running')->lockForUpdate()->first();
                    if ($fresh === null) {
                        $result->settle('invalid_output');

                        return;
                    }
                    try {
                        self::assertPreparationCurrent($fresh);
                    } catch (Throwable $failure) {
                        app(AiAssistPreparation::class)->withdrawPublication($fresh, $failure instanceof AiNetworkException ? $failure->reason : 'access_revoked');
                        $result->settle('invalid_output');

                        return;
                    }
                    $publication();
                    $current = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)->first();
                    if ($current?->status === 'ready') {
                        try {
                            self::assertPreparationCurrent($current);
                        } catch (Throwable $failure) {
                            app(AiAssistPreparation::class)->withdrawPublication($current, $failure instanceof AiNetworkException ? $failure->reason : 'access_revoked');
                        }
                    }
                    app(AiAssistPreparation::class)->syncTerminal($artifact);
                    $ready = $artifact::query()->whereKey($artifact->id)->where('generation', $artifact->generation)->where('status', 'ready')->exists();
                    $result->settle($ready ? 'completed' : 'invalid_output');
                });

            return;
        }
        if (! $result->isGuarded()) {
            $publication();
            $result->settle('completed');

            return;
        }
        if (! DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->exists()) {
            throw new AiNetworkException('usage_reservation_missing');
        }
        $accountId = (int) $artifact->conversation?->site?->account_id;
        $this->ledger->withAccountUsageLock($accountId, function () use ($artifact, $result, $publication): void {
            $publication();
            $fresh = $artifact::query()->whereKey($artifact->getKey())->where('generation', $artifact->generation)->first();
            $result->settle($fresh?->status === 'ready' ? 'completed' : 'invalid_output');
        });
    }

    public function contextFor(#[SensitiveParameter] Model $artifact): ?AiExecutionContext
    {
        $fresh = $this->fresh($artifact);
        $connection = $this->connections->forAccount((int) $fresh->conversation?->site?->account_id);
        if ($connection === null || $fresh->connection_revision !== $connection->revision) {
            throw new AiNetworkException('connection_changed');
        }

        $this->assertPolicy($fresh);

        return $this->connections->isLegacyInstallationDefault($connection) ? null : $this->context($fresh);
    }

    public function cancelFor(#[SensitiveParameter] Model $artifact, #[SensitiveParameter] string $reason = 'queue_failed'): void
    {
        // Use the captured generation; a reloaded newer artifact is another
        // attempt. Missing holds include all preserved legacy requests.
        try {
            if (DB::table('ai_usage_attempts')->where('attempt_id', $artifact->generation)->exists()) {
                $this->ledger->cancelReserved($artifact->generation, $reason);
            }
        } catch (Throwable) {
            // A failed store never permits I/O; bounded expiry remains recovery.
        }
    }

    public static function promptFor(#[SensitiveParameter] Model $artifact): AgentCopilotPrompt
    {
        $builder = match ($artifact::class) {
            ConversationCopilotSummary::class => ConversationSummaryPromptBuilder::class,
            ConversationCopilotReplyDraft::class => ConversationReplyDraftPromptBuilder::class,
            ConversationCopilotKnowledgeSuggestion::class => ConversationKnowledgeSuggestionPromptBuilder::class,
            ConversationCopilotTicketSuggestion::class => ConversationTicketSuggestionPromptBuilder::class,
            default => throw new AiNetworkException('execution_identity_invalid'),
        };
        $context = $artifact instanceof ConversationCopilotReplyDraft
            ? app($builder)->build($artifact->conversation, User::query()->find($artifact->requested_by_id))
            : app($builder)->build($artifact->conversation);
        if ($context === null) {
            throw new AiNetworkException('context_unavailable');
        }

        return $context->prompt;
    }

    public static function sanitizedInput(#[SensitiveParameter] AgentCopilotPrompt $prompt, ?int $contextLimit = null): string
    {
        $maximum = max(1_000, (int) config('wayfindr.ai.max_context_characters', 30_000));
        $limit = min($maximum, $contextLimit ?? $prompt->execution?->maxContextCharacters ?? $maximum);

        return mb_substr(app(AiContextSanitizer::class)->sanitize($prompt->input), 0, $limit);
    }

    /** Opaque source proof; never stores a transcript, prompt, or article text. */
    public static function sourceFingerprint(#[SensitiveParameter] Model $artifact, #[SensitiveParameter] ?AgentCopilotPrompt $prompt = null): string
    {
        $policy = app(AiAssistPolicyResolver::class)->forConversation($artifact->conversation);
        $prompt ??= self::promptFor($artifact);

        return hash_hmac('sha256', json_encode([
            $prompt->purpose, $prompt->instructions, self::sanitizedInput($prompt, $policy->maxContextCharacters),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Called only after policy commit; retain dispatched charges and leases. */
    public function cancelPolicyReservations(int $accountId): void
    {
        try {
            $attempts = DB::table('ai_usage_attempts')->where('account_id', $accountId)->where('state', 'reserved')
                ->whereIn('purpose', array_keys(self::ARTIFACTS))->get(['attempt_id', 'purpose']);
            foreach ($attempts as $attempt) {
                $class = self::ARTIFACTS[$attempt->purpose];
                $artifact = $class::query()->with('conversation.site')->where('generation', $attempt->attempt_id)->first();
                if ($artifact === null || ! app(AiAssistPolicyResolver::class)->isCurrent(
                    (int) $artifact->conversation?->site_id, $attempt->purpose, $artifact->policy_revision)) {
                    $this->ledger->cancelReserved($attempt->attempt_id, 'access_revoked');
                }
            }
        } catch (Throwable) {
            // Policy revisions already forbid I/O. Expiry recovers an uncertain
            // unsent hold; cleanup failure must not misreport a committed edit.
        }
    }

    public static function workFingerprint(#[SensitiveParameter] Model $artifact, #[SensitiveParameter] ResolvedAgentCopilotConnection $connection): string
    {
        return self::fingerprintForPrompt($artifact, $connection, self::promptFor($artifact));
    }

    public static function assertActualPrompt(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] AiExecutionContext $context, #[SensitiveParameter] AgentCopilotPrompt $prompt): void
    {
        if ($context->kind !== 'support') {
            return;
        }
        $class = self::ARTIFACTS[$context->purpose] ?? null;
        $artifact = $class === null ? null : $class::query()->whereKey($context->artifactId)->where('generation', $context->generation)->first();
        $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $context->attemptId)->first();
        if ($artifact === null || $attempt === null || ! hash_equals($attempt->work_fingerprint, self::fingerprintForPrompt($artifact, $connection, $prompt))) {
            throw new AiNetworkException('context_changed');
        }
    }

    private static function fingerprintForPrompt(#[SensitiveParameter] Model $artifact, #[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] AgentCopilotPrompt $prompt): string
    {
        return hash_hmac('sha256', json_encode([
            $artifact->preparation_fingerprint === null ? 'manual-v2' : 'prepared-v1',
            ...($artifact->preparation_fingerprint === null ? [] : [$artifact->preparation_fingerprint]),
            $artifact::class, $artifact->conversation_id, $artifact->policy_revision,
            $connection->revision, self::sourceFingerprint($artifact, $prompt),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Fail closed at admission, actual dispatch and both sides of publication. */
    public static function assertPreparationCurrent(Model $artifact): void
    {
        if (! is_string($artifact->preparation_fingerprint)) {
            return;
        }
        $purpose = array_search($artifact::class, self::ARTIFACTS, true);
        $fresh = $artifact::query()->with(['conversation.site.account', 'requestedBy'])
            ->whereKey($artifact->id)->where('generation', $artifact->generation)->first();
        if ($purpose === false || $fresh?->conversation === null || $fresh->requestedBy === null) {
            throw new AiNetworkException('context_changed');
        }
        if (! app(AiAssistPolicyResolver::class)->isCurrent((int) $fresh->conversation->site_id, $purpose, $fresh->policy_revision)) {
            throw new AiNetworkException('policy_changed');
        }
        if (! app(AgentCopilotConnectionResolver::class)->isCurrent((int) $fresh->conversation->site->account_id, $fresh->connection_revision)) {
            throw new AiNetworkException('connection_changed');
        }
        if ($purpose === 'conversation_ticket_suggestion' && $fresh->conversation->tickets()->exists()) {
            throw new AiNetworkException('ticket_exists');
        }
        $identity = app(AiAssistPreparationIdentityResolver::class)->forTask($fresh->requestedBy, $fresh->conversation, $purpose);
        if ($fresh instanceof ConversationCopilotReplyDraft && $fresh->status === 'ready'
            && ($identity->sourceSnapshot['grounding_enabled'] ?? false)
            && ! app(ConversationGroundedReplyPresentation::class)->isCurrent($fresh, $fresh->requestedBy, $fresh->conversation)) {
            throw new AiNetworkException('context_changed');
        }
        if (! hash_equals($identity->fingerprint, $fresh->preparation_fingerprint)
            || ! AiAssistPreparationReceipt::query()->where('account_id', $identity->accountId)
                ->where('conversation_id', $identity->conversationId)->where('purpose', $purpose)
                ->where('fingerprint', $identity->fingerprint)->where('generation', $fresh->generation)
                ->whereIn('status', $fresh->status === 'ready' ? ['pending', 'ready'] : ['pending'])->exists()) {
            throw new AiNetworkException('context_changed');
        }
    }

    private function assertPolicy(#[SensitiveParameter] Model $artifact): void
    {
        $purpose = array_search($artifact::class, self::ARTIFACTS, true);
        if ($purpose === false || ! app(AiAssistPolicyResolver::class)->isCurrent(
            (int) $artifact->conversation?->site_id, $purpose, $artifact->policy_revision)) {
            throw new AiNetworkException('access_revoked');
        }
    }

    private function fresh(#[SensitiveParameter] Model $artifact): Model
    {
        $fresh = $artifact::query()->with('conversation.site')->whereKey($artifact->getKey())->where('generation', $artifact->generation)->first();
        if ($fresh === null) {
            throw new AiNetworkException('execution_superseded');
        }

        self::assertPreparationCurrent($fresh);

        return $fresh;
    }

    private function context(#[SensitiveParameter] Model $artifact): AiExecutionContext
    {
        $purpose = array_search($artifact::class, self::ARTIFACTS, true);
        if ($purpose === false) {
            throw new AiNetworkException('execution_identity_invalid');
        }

        return new AiExecutionContext('support', (int) $artifact->conversation?->site?->account_id,
            (int) $artifact->requested_by_id, $purpose, $artifact->generation,
            (int) $artifact->getKey(), $artifact->generation,
            policyRevision: $artifact->policy_revision,
            maxContextCharacters: app(AiAssistPolicyResolver::class)->forConversation($artifact->conversation)->maxContextCharacters);
    }
}
