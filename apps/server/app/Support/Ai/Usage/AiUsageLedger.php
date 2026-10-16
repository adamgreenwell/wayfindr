<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use App\Models\Account;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use stdClass;
use Throwable;

/** Content-free accounting. No method authorizes support access or provider I/O. */
final readonly class AiUsageLedger
{
    public const PURPOSES = [
        'configuration_probe', 'model_discovery', 'conversation_summary',
        'conversation_reply_draft', 'conversation_knowledge_suggestion',
        'conversation_ticket_suggestion', 'conversation_draft_refinement',
        'grounded_answer_evaluation',
    ];

    public const REASONS = [
        'completed', 'known_unsent', 'usage_unavailable', 'timeout', 'transport_failed',
        'cancelled', 'queue_failed', 'provider_failed', 'connection_changed',
        'access_revoked', 'invalid_output', 'reservation_expired', 'claim_expired',
        'dispatch_expired', 'account_deleted', 'usage_request_limit', 'usage_token_limit',
        'usage_concurrency_limit', 'usage_output_limit', 'superseded',
    ];

    public function __construct(private AiUsagePolicy $policy, private AiUsageClock $clock) {}

    public function reserve(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        #[SensitiveParameter] string $purpose,
        #[SensitiveParameter] string $attemptId,
        int $inputTokenBound,
        int $maxOutputTokens,
        #[SensitiveParameter] string $workFingerprint,
        ?int $requesterId = null,
        #[SensitiveParameter] ?string $replacementOf = null,
        #[SensitiveParameter] ?Closure $authorize = null,
    ): AiUsageReservation {
        $this->identity($connection, $purpose, $attemptId);
        $this->uuid($replacementOf);
        if (preg_match('/\A[a-f0-9]{64}\z/', $workFingerprint) !== 1
            || $inputTokenBound < 0 || $maxOutputTokens < 0
            || $inputTokenBound > PHP_INT_MAX - $maxOutputTokens
            || ($requesterId !== null && $requesterId <= 0)) {
            throw new AiNetworkException('usage_invalid_request');
        }

        return $this->locked($connection->accountId, function (?Account $account, CarbonImmutable $now) use ($connection, $purpose, $attemptId, $inputTokenBound, $maxOutputTokens, $workFingerprint, $requesterId, $replacementOf, $authorize): AiUsageReservation {
            if ($account === null) {
                throw new AiNetworkException('usage_account_unavailable');
            }
            $authorize?->__invoke();
            $existing = DB::table('ai_usage_attempts')->where('attempt_id', $attemptId)->first();
            if ($existing !== null) {
                $this->matches($existing, $connection, $purpose);
                if ($existing->work_fingerprint !== $workFingerprint
                    || (int) $existing->input_token_bound !== $inputTokenBound
                    || (int) $existing->max_output_tokens !== $maxOutputTokens) {
                    throw new AiNetworkException('usage_attempt_mismatch');
                }

                return $this->reservation($existing, true);
            }
            $latest = DB::table('ai_usage_attempts')
                ->where('account_id', $connection->accountId)->where('source', $connection->source)
                ->where('connection_revision', $connection->revision)->where('purpose', $purpose)
                ->where('work_fingerprint', $workFingerprint)->orderByDesc('id')->first();
            if ($latest !== null) {
                if ((int) $latest->input_token_bound !== $inputTokenBound || (int) $latest->max_output_tokens !== $maxOutputTokens) {
                    throw new AiNetworkException('usage_attempt_mismatch');
                }
                if (in_array($latest->state, ['reserved', 'claimed', 'dispatched'], true) || $replacementOf === null) {
                    return $this->reservation($latest, true);
                }
                if ($replacementOf !== $latest->attempt_id) {
                    throw new AiNetworkException('usage_attempt_mismatch');
                }
                if ($latest->retry_not_before !== null && $now->lessThan($this->time($latest->retry_not_before))) {
                    throw new AiNetworkException('usage_retry_cooldown');
                }
            } elseif ($replacementOf !== null) {
                throw new AiNetworkException('usage_attempt_mismatch');
            }

            $limits = $this->policy->forConnection($connection);
            $tokens = $inputTokenBound + $maxOutputTokens;
            $periods = $this->periods($connection->accountId, [$now->toDateString()], $now);
            $reason = $this->allowance($periods, $now->toDateString(), $limits, 1, $tokens, $maxOutputTokens);
            if ($reason !== null) {
                throw new AiNetworkException($reason);
            }
            $this->changePeriod($connection->accountId, $now->toDateString(), ['held_requests' => 1, 'held_tokens' => $tokens]);
            DB::table('ai_usage_attempts')->insert([
                'attempt_id' => $attemptId, 'account_id' => $connection->accountId,
                'requester_id' => $requesterId, 'source' => $connection->source,
                'connection_revision' => $connection->revision, 'purpose' => $purpose,
                'work_fingerprint' => $workFingerprint, 'input_token_bound' => $inputTokenBound,
                'max_output_tokens' => $maxOutputTokens, 'reserved_tokens' => $tokens,
                'state' => 'reserved', 'reserved_day' => $now->toDateString(),
                'expires_at' => $this->stamp($now->addSeconds($limits->queueTtlSeconds)),
                'retry_cooldown_seconds' => max(60, $limits->retryCooldownSeconds),
                'created_at' => $this->stamp($now), 'updated_at' => $this->stamp($now),
            ]);

            return $this->reservation(DB::table('ai_usage_attempts')->where('attempt_id', $attemptId)->first());
        });
    }

    public function claim(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId, #[SensitiveParameter] ?Closure $authorize = null): string
    {
        $this->identity($connection, $purpose, $attemptId);
        $result = $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($connection, $purpose, $authorize): string|AiNetworkException {
            $this->matches($attempt, $connection, $purpose);
            if ($this->expireUnsent($attempt, $now)) {
                return new AiNetworkException('usage_attempt_terminal');
            }
            if ($attempt->state !== 'reserved') {
                throw new AiNetworkException($attempt->state === 'claimed' ? 'usage_attempt_claimed' : 'usage_attempt_terminal');
            }
            if ($account === null) {
                $this->finishUnsent($attempt, $now, 'account_deleted');

                return new AiNetworkException('usage_account_unavailable');
            }
            try {
                $authorize?->__invoke();
            } catch (AiNetworkException $exception) {
                $this->finishUnsent($attempt, $now, 'access_revoked');

                return new AiNetworkException($exception->reason);
            }
            $limits = $this->policy->forConnection($connection);
            $today = $now->toDateString();
            $periods = $this->periods((int) $attempt->account_id, [$today], $now);
            $rollover = $attempt->reserved_day !== $today;
            $reason = $this->allowance($periods, $today, $limits, $rollover ? 1 : 0,
                $rollover ? (int) $attempt->reserved_tokens : 0, (int) $attempt->max_output_tokens);
            if ($reason !== null) {
                $this->finishUnsent($attempt, $now, $reason);

                return new AiNetworkException($reason);
            }
            $nonce = (string) Str::uuid();
            $this->update($attempt, [
                'state' => 'claimed', 'claim_nonce' => $nonce,
                'claim_expires_at' => $this->stamp($now->addSeconds($limits->claimTtlSeconds)->min($this->time($attempt->expires_at))),
            ], $now);

            return $nonce;
        });

        return $this->unwrap($result);
    }

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce, #[SensitiveParameter] ?Closure $authorize = null): AiUsageDispatch
    {
        $this->identity($connection, $purpose, $attemptId);
        $this->uuid($claimNonce);
        $result = $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($connection, $purpose, $claimNonce, $authorize): AiUsageDispatch|AiNetworkException {
            $this->matches($attempt, $connection, $purpose);
            $this->nonce($attempt->claim_nonce, $claimNonce);
            if ($this->expireUnsent($attempt, $now)) {
                return new AiNetworkException('usage_attempt_terminal');
            }
            if ($attempt->state !== 'claimed') {
                throw new AiNetworkException(in_array($attempt->state, ['dispatched', 'unknown', 'completed', 'failed'], true) ? 'usage_attempt_dispatched' : 'usage_attempt_terminal');
            }
            if ($account === null) {
                $this->finishUnsent($attempt, $now, 'account_deleted');

                return new AiNetworkException('usage_account_unavailable');
            }
            try {
                $authorize?->__invoke();
            } catch (AiNetworkException $exception) {
                $this->finishUnsent($attempt, $now, 'access_revoked');

                return new AiNetworkException($exception->reason);
            }
            $limits = $this->policy->forConnection($connection);
            $today = $now->toDateString();
            if ($attempt->reserved_day !== $today) {
                $this->changePeriod((int) $attempt->account_id, $attempt->reserved_day, ['held_requests' => -1, 'held_tokens' => -(int) $attempt->reserved_tokens]);
                $periods = $this->periods((int) $attempt->account_id, [$today], $now);
                $reason = $this->allowance($periods, $today, $limits, 1, (int) $attempt->reserved_tokens, (int) $attempt->max_output_tokens);
                if ($reason !== null) {
                    // Commit the old hold release before returning a refusal.
                    $this->terminal($attempt, $now, 'cancelled', $reason);

                    return new AiNetworkException($reason);
                }
                $this->changePeriod((int) $attempt->account_id, $today, ['held_requests' => 1, 'held_tokens' => (int) $attempt->reserved_tokens]);
                $attempt->reserved_day = $today;
                $this->update($attempt, ['reserved_day' => $today], $now);
            }
            $periods = $this->periods((int) $attempt->account_id, [$today], $now);
            $reason = $this->allowance($periods, $today, $limits, 0, 0, (int) $attempt->max_output_tokens);
            foreach ($this->scopes((int) $attempt->account_id) as $index => $scope) {
                $limit = ($index === 0 ? $limits->installation : $limits->account)['concurrency'];
                if ((int) DB::table('ai_usage_scopes')->where('scope_key', $scope)->value('concurrency_in_use') >= $limit) {
                    $reason ??= 'usage_concurrency_limit';
                }
            }
            if ($reason !== null) {
                $this->finishUnsent($attempt, $now, $reason);

                return new AiNetworkException($reason);
            }
            $this->changePeriod((int) $attempt->account_id, $today, [
                'held_requests' => -1, 'held_tokens' => -(int) $attempt->reserved_tokens,
                'charged_requests' => 1, 'charged_tokens' => (int) $attempt->reserved_tokens,
            ]);
            foreach ($this->scopes((int) $attempt->account_id) as $scope) {
                DB::table('ai_usage_scopes')->where('scope_key', $scope)->increment('concurrency_in_use');
            }
            $nonce = (string) Str::uuid();
            $expires = $now->addSeconds($limits->dispatchTtlSeconds);
            $this->update($attempt, [
                'state' => 'dispatched', 'dispatch_nonce' => $nonce, 'dispatch_day' => $today,
                'dispatched_at' => $this->stamp($now), 'dispatch_expires_at' => $this->stamp($expires),
                'debited_tokens' => (int) $attempt->reserved_tokens, 'concurrency_held' => true,
            ], $now);

            return new AiUsageDispatch($attempt->attempt_id, $nonce, (int) $attempt->account_id, $attempt->source,
                $attempt->connection_revision, $attempt->purpose, $today, (int) $attempt->reserved_tokens,
                (int) $attempt->max_output_tokens, $now, $expires);
        });

        return $this->unwrap($result);
    }

    /** Cancel only a definitely unclaimed queue hold; never a competing worker. */
    public function cancelReserved(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $reason = 'queue_failed'): void
    {
        $this->reason($reason);
        $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($reason): void {
            if ($attempt->state === 'reserved') {
                $this->finishUnsent($attempt, $now, $reason);
            }
        });
    }

    /** Retire only the latest equivalent receipt proven obsolete by its artifact. */
    public function retireSuperseded(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        #[SensitiveParameter] string $purpose,
        #[SensitiveParameter] string $attemptId,
        #[SensitiveParameter] string $workFingerprint,
        #[SensitiveParameter] Closure $isSuperseded,
    ): void {
        $this->identity($connection, $purpose, $attemptId);
        if (preg_match('/\A[a-f0-9]{64}\z/', $workFingerprint) !== 1) {
            throw new AiNetworkException('usage_invalid_request');
        }
        $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($connection, $purpose, $workFingerprint, $isSuperseded): void {
            $this->matches($attempt, $connection, $purpose);
            $latest = DB::table('ai_usage_attempts')->where('account_id', $connection->accountId)
                ->where('source', $connection->source)->where('connection_revision', $connection->revision)
                ->where('purpose', $purpose)->where('work_fingerprint', $workFingerprint)->orderByDesc('id')->value('attempt_id');
            if ($account === null || $attempt->work_fingerprint !== $workFingerprint
                || $latest !== $attempt->attempt_id || ! $isSuperseded()) {
                return;
            }
            if (in_array($attempt->state, ['reserved', 'claimed'], true)) {
                $retryNotBefore = $attempt->retry_not_before;
                $this->finishUnsent($attempt, $now, 'superseded');
                // No request crossed the dispatch boundary. A user's explicit
                // stale retry can replace it now, preserving an existing wait.
                $this->update($attempt, ['retry_not_before' => $retryNotBefore ?? $this->stamp($now)], $now);

                return;
            }
            if (in_array($attempt->state, ['dispatched', 'unknown'], true)) {
                if ($this->time($attempt->dispatch_expires_at)->lessThanOrEqualTo($now)) {
                    $this->releaseConcurrency($attempt);
                }
                if ($attempt->state === 'dispatched') {
                    $terminal = $attempt->terminal_at === null ? $now : $this->time($attempt->terminal_at);
                    $this->update($attempt, [
                        'state' => 'unknown', 'reason' => 'superseded', 'terminal_at' => $this->stamp($terminal),
                        'retry_not_before' => $this->retryDeadline($attempt, $terminal, null),
                    ], $now);
                }
            }
        });
    }

    /** Scoped recovery only: release an expired lease without refunding its charge or shortening retry advice. */
    public function releaseExpiredDispatch(string $attemptId): void
    {
        $this->attempt($attemptId, function (stdClass $attempt, ?Account $account, CarbonImmutable $now): void {
            if (! $attempt->concurrency_held || $attempt->dispatch_expires_at === null
                || $this->time($attempt->dispatch_expires_at)->isAfter($now)) {
                return;
            }
            $this->releaseConcurrency($attempt);
            if ($attempt->state === 'dispatched') {
                $this->terminal($attempt, $now, 'unknown', 'dispatch_expired');
            }
        });
    }

    public function cancelUnsent(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce, #[SensitiveParameter] string $reason = 'known_unsent'): void
    {
        $this->reason($reason);
        $this->uuid($claimNonce);
        $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($claimNonce, $reason): void {
            $this->nonce($attempt->claim_nonce, $claimNonce);
            if (in_array($attempt->state, ['cancelled', 'expired'], true)) {
                return;
            }
            if ($attempt->state !== 'claimed') {
                throw new AiNetworkException('usage_attempt_dispatched');
            }
            $this->finishUnsent($attempt, $now, $reason);
        });
    }

    public function markUnknown(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $dispatchNonce, #[SensitiveParameter] string $reason = 'usage_unavailable'): void
    {
        $this->reason($reason);
        $this->uuid($dispatchNonce);
        $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($dispatchNonce, $reason): void {
            $this->nonce($attempt->dispatch_nonce, $dispatchNonce);
            if (in_array($attempt->state, ['completed', 'failed'], true)) {
                return;
            }
            if (! in_array($attempt->state, ['dispatched', 'unknown'], true)) {
                throw new AiNetworkException('usage_attempt_terminal');
            }
            if ($this->time($attempt->dispatch_expires_at)->lessThanOrEqualTo($now)) {
                $this->releaseConcurrency($attempt);
            }
            if ($attempt->state !== 'unknown') {
                $this->terminal($attempt, $now, 'unknown', $reason);
            }
        });
    }

    public function reconcile(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $dispatchNonce, ?int $inputTokens, ?int $outputTokens, #[SensitiveParameter] string $reason = 'completed', ?int $retryAfterSeconds = null): void
    {
        $this->reason($reason);
        $this->uuid($dispatchNonce);
        if (($inputTokens !== null && $inputTokens < 0) || ($outputTokens !== null && $outputTokens < 0)
            || ($inputTokens !== null && $outputTokens !== null && $inputTokens > PHP_INT_MAX - $outputTokens)
            || ($retryAfterSeconds !== null && ($retryAfterSeconds < 0 || $retryAfterSeconds > 31_536_000))) {
            throw new AiNetworkException('usage_invalid_receipt');
        }
        $this->attempt($attemptId, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now) use ($dispatchNonce, $inputTokens, $outputTokens, $reason, $retryAfterSeconds): void {
            $this->nonce($attempt->dispatch_nonce, $dispatchNonce);
            if (! in_array($attempt->state, ['dispatched', 'unknown', 'completed', 'failed'], true)) {
                throw new AiNetworkException('usage_attempt_terminal');
            }
            foreach (['reported_input_tokens' => $inputTokens, 'reported_output_tokens' => $outputTokens] as $field => $value) {
                if ($value !== null && $attempt->{$field} !== null && (int) $attempt->{$field} !== $value) {
                    throw new AiNetworkException('usage_receipt_mismatch');
                }
            }
            if (in_array($attempt->state, ['completed', 'failed'], true)) {
                return;
            }
            $inputTokens ??= $attempt->reported_input_tokens === null ? null : (int) $attempt->reported_input_tokens;
            $outputTokens ??= $attempt->reported_output_tokens === null ? null : (int) $attempt->reported_output_tokens;
            if ($inputTokens !== null && $outputTokens !== null) {
                if ($inputTokens > PHP_INT_MAX - $outputTokens) {
                    throw new AiNetworkException('usage_invalid_receipt');
                }
                $actual = $inputTokens + $outputTokens;
                $this->changePeriod((int) $attempt->account_id, $attempt->dispatch_day, ['charged_tokens' => $actual - (int) $attempt->debited_tokens]);
                $this->releaseConcurrency($attempt);
                $terminal = $attempt->terminal_at === null ? $now : $this->time($attempt->terminal_at);
                $succeeded = $reason === 'completed';
                $this->update($attempt, [
                    'state' => $succeeded ? 'completed' : 'failed', 'reason' => $reason, 'reported_input_tokens' => $inputTokens,
                    'reported_output_tokens' => $outputTokens, 'debited_tokens' => $actual,
                    'terminal_at' => $this->stamp($terminal),
                    'retry_not_before' => $succeeded ? null : $this->retryDeadline($attempt, $terminal, $retryAfterSeconds),
                ], $now);
            } else {
                // Missing usage never credits a hold, but observed components
                // above its bound must increase the conservative debit.
                $knownInput = $inputTokens ?? (int) $attempt->input_token_bound;
                $knownOutput = $outputTokens ?? (int) $attempt->max_output_tokens;
                if ($knownInput > PHP_INT_MAX - $knownOutput) {
                    throw new AiNetworkException('usage_invalid_receipt');
                }
                $conservative = max((int) $attempt->debited_tokens, $knownInput + $knownOutput);
                $this->changePeriod((int) $attempt->account_id, $attempt->dispatch_day,
                    ['charged_tokens' => $conservative - (int) $attempt->debited_tokens]);
                $this->update($attempt, ['debited_tokens' => $conservative], $now);
                if ($this->time($attempt->dispatch_expires_at)->lessThanOrEqualTo($now)) {
                    $this->releaseConcurrency($attempt);
                }
                $terminal = $attempt->terminal_at === null ? $now : $this->time($attempt->terminal_at);
                $this->update($attempt, [
                    'state' => 'unknown', 'reason' => $reason === 'completed' ? 'usage_unavailable' : $reason,
                    'reported_input_tokens' => $inputTokens, 'reported_output_tokens' => $outputTokens,
                    'terminal_at' => $this->stamp($terminal),
                    'retry_not_before' => $this->retryDeadline($attempt, $terminal, $retryAfterSeconds),
                ], $now);
            }
        });
    }

    /** Bounded sweep; every item enters installation -> one account lock order. */
    public function expire(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new AiNetworkException('usage_invalid_request');
        }
        try {
            $now = $this->stamp($this->clock->now()->utc());
            $ids = DB::table('ai_usage_attempts')->where(function ($query) use ($now): void {
                $query->where(fn ($q) => $q->where('state', 'reserved')->where('expires_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('state', 'claimed')->where('claim_expires_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('concurrency_held', true)->where('dispatch_expires_at', '<=', $now));
            })->orderByRaw('CASE WHEN concurrency_held THEN 0 ELSE 1 END')->orderBy('id')->limit($limit)->pluck('attempt_id');
            $changed = 0;
            foreach ($ids as $id) {
                $changed += $this->attempt($id, function (#[SensitiveParameter] stdClass $attempt, ?Account $account, CarbonImmutable $now): int {
                    if ($attempt->state === 'reserved' && $this->time($attempt->expires_at)->lessThanOrEqualTo($now)) {
                        $this->finishUnsent($attempt, $now, 'reservation_expired', 'expired');

                        return 1;
                    }
                    if ($attempt->state === 'claimed' && $this->time($attempt->claim_expires_at)->lessThanOrEqualTo($now)) {
                        $this->finishUnsent($attempt, $now, 'claim_expired', 'expired');

                        return 1;
                    }
                    if ($attempt->concurrency_held && $this->time($attempt->dispatch_expires_at)->lessThanOrEqualTo($now)) {
                        $this->releaseConcurrency($attempt);
                        if ($attempt->state === 'dispatched') {
                            $this->terminal($attempt, $now, 'unknown', 'dispatch_expired');
                        }

                        return 1;
                    }

                    return 0;
                });
            }

            return $changed;
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('usage_store_unavailable');
        }
    }

    /** Atomic publication/settlement; caller enters before holding Account. No I/O. */
    public function withAccountUsageLock(int $accountId, #[SensitiveParameter] Closure $operation): mixed
    {
        return $this->locked($accountId, function (?Account $account, CarbonImmutable $now) use ($operation): mixed {
            if ($account === null) {
                throw new AiNetworkException('usage_account_unavailable');
            }

            return $operation();
        }, 1);
    }

    public function summary(int $accountId): array
    {
        return $this->locked($accountId, function (?Account $account, CarbonImmutable $now) use ($accountId): array {
            if ($account === null) {
                throw new AiNetworkException('usage_account_unavailable');
            }
            $periods = $this->periods($accountId, [$now->toDateString()], $now);
            $result = ['period_day' => $now->toDateString()];
            foreach ($this->scopes($accountId) as $index => $scope) {
                $row = $periods[$scope][$now->toDateString()];
                $result[$index === 0 ? 'installation' : 'account'] = [
                    'held_requests' => (int) $row->held_requests, 'held_tokens' => (int) $row->held_tokens,
                    'charged_requests' => (int) $row->charged_requests, 'charged_tokens' => (int) $row->charged_tokens,
                    'concurrency' => (int) DB::table('ai_usage_scopes')->where('scope_key', $scope)->value('concurrency_in_use'),
                ];
            }
            $result['observed_tokens'] = (int) DB::table('ai_usage_attempts')->where('account_id', $accountId)
                ->where('dispatch_day', $now->toDateString())->whereIn('state', ['completed', 'failed'])
                ->sum(DB::raw('reported_input_tokens + reported_output_tokens'));

            return $result;
        });
    }

    /** Caller must never enter while holding an Account lock. No I/O in callback. */
    private function locked(int $accountId, #[SensitiveParameter] Closure $operation, int $attempts = 3): mixed
    {
        try {
            return DB::transaction(function () use ($accountId, $operation): mixed {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL lock_timeout = '5s'");
                    DB::statement("SET LOCAL statement_timeout = '10s'");
                }
                // Unique-key insertion serializes concurrent first users too.
                // Keep fresh migrations empty for the restore safety boundary.
                DB::table('ai_usage_scopes')->insertOrIgnore([
                    'scope_key' => 'installation', 'account_id' => null,
                    'created_at' => DB::raw('CURRENT_TIMESTAMP'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                if (! DB::table('ai_usage_scopes')->where('scope_key', 'installation')->lockForUpdate()->first()) {
                    throw new AiNetworkException('usage_store_unavailable');
                }
                $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();
                DB::table('ai_usage_scopes')->insertOrIgnore([
                    'scope_key' => 'account:'.$accountId, 'account_id' => $accountId,
                    'created_at' => DB::raw('CURRENT_TIMESTAMP'), 'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                DB::table('ai_usage_scopes')->where('scope_key', 'account:'.$accountId)->lockForUpdate()->first();
                $now = $this->clock->now()->utc();

                return $operation($account, $now);
            }, $attempts);
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('usage_store_unavailable');
        }
    }

    private function attempt(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] Closure $operation): mixed
    {
        $this->uuid($attemptId);
        try {
            $hint = DB::table('ai_usage_attempts')->where('attempt_id', $attemptId)->first();
            if ($hint === null) {
                throw new AiNetworkException('usage_attempt_missing');
            }

            return $this->locked((int) $hint->account_id, function (?Account $account, CarbonImmutable $now) use ($attemptId, $operation): mixed {
                $hint = DB::table('ai_usage_attempts')->where('attempt_id', $attemptId)->first();
                if ($hint === null) {
                    throw new AiNetworkException('usage_attempt_missing');
                }
                $this->periods((int) $hint->account_id, array_filter([$hint->reserved_day, $hint->dispatch_day, $now->toDateString()]), $now);
                $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $attemptId)->lockForUpdate()->first();

                return $operation($attempt, $account, $now);
            });
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('usage_store_unavailable');
        }
    }

    private function periods(int $accountId, array $days, CarbonImmutable $now): array
    {
        $days = array_values(array_unique($days));
        sort($days, SORT_STRING);
        $rows = [];
        foreach ($this->scopes($accountId) as $scope) {
            foreach ($days as $day) {
                DB::table('ai_usage_periods')->insertOrIgnore([
                    'scope_key' => $scope, 'period_day' => $day,
                    'created_at' => $this->stamp($now), 'updated_at' => $this->stamp($now),
                ]);
                $rows[$scope][$day] = DB::table('ai_usage_periods')->where('scope_key', $scope)->where('period_day', $day)->lockForUpdate()->first();
            }
        }

        return $rows;
    }

    private function allowance(array $periods, string $day, AiUsageLimits $limits, int $requests, int $tokens, int $output): ?string
    {
        if ($limits->maxOutputTokens <= 0 || $output > $limits->maxOutputTokens) {
            return 'usage_output_limit';
        }
        foreach (array_values($periods) as $index => $days) {
            $limit = $index === 0 ? $limits->installation : $limits->account;
            $row = $days[$day];
            foreach (['requests' => $requests, 'tokens' => $tokens] as $kind => $extra) {
                $held = (int) $row->{'held_'.$kind};
                $charged = (int) $row->{'charged_'.$kind};
                if ($limit[$kind] <= 0 || $held > $limit[$kind] - $extra || $charged > $limit[$kind] - $extra - $held) {
                    return $kind === 'requests' ? 'usage_request_limit' : 'usage_token_limit';
                }
            }
            if ($limit['concurrency'] <= 0) {
                return 'usage_concurrency_limit';
            }
        }

        return null;
    }

    private function changePeriod(int $accountId, string $day, array $changes): void
    {
        foreach ($this->scopes($accountId) as $scope) {
            $query = DB::table('ai_usage_periods')->where('scope_key', $scope)->where('period_day', $day);
            $row = $query->first();
            $values = [];
            foreach ($changes as $field => $delta) {
                $old = (int) $row->{$field};
                if (($delta < 0 && $old < -$delta) || ($delta > 0 && $old > PHP_INT_MAX - $delta)) {
                    throw new AiNetworkException('usage_store_unavailable');
                }
                $values[$field] = $old + $delta;
            }
            $query->update($values);
        }
    }

    private function finishUnsent(#[SensitiveParameter] stdClass $attempt, CarbonImmutable $now, string $reason, string $state = 'cancelled'): void
    {
        $this->changePeriod((int) $attempt->account_id, $attempt->reserved_day, ['held_requests' => -1, 'held_tokens' => -(int) $attempt->reserved_tokens]);
        $this->terminal($attempt, $now, $state, $reason);
    }

    private function expireUnsent(#[SensitiveParameter] stdClass $attempt, CarbonImmutable $now): bool
    {
        if ($attempt->state === 'reserved' && $this->time($attempt->expires_at)->lessThanOrEqualTo($now)) {
            $this->finishUnsent($attempt, $now, 'reservation_expired', 'expired');

            return true;
        }
        if ($attempt->state === 'claimed'
            && ($this->time($attempt->expires_at)->lessThanOrEqualTo($now)
                || $this->time($attempt->claim_expires_at)->lessThanOrEqualTo($now))) {
            $this->finishUnsent($attempt, $now, 'claim_expired', 'expired');

            return true;
        }

        return false;
    }

    private function terminal(#[SensitiveParameter] stdClass $attempt, CarbonImmutable $now, string $state, string $reason): void
    {
        $terminal = $attempt->terminal_at === null ? $now : $this->time($attempt->terminal_at);
        $this->update($attempt, [
            'state' => $state, 'reason' => $reason, 'terminal_at' => $this->stamp($terminal),
            'retry_not_before' => $this->stamp($terminal->addSeconds(max(60, (int) $attempt->retry_cooldown_seconds))),
        ], $now);
    }

    private function retryDeadline(#[SensitiveParameter] stdClass $attempt, CarbonImmutable $terminal, ?int $retryAfterSeconds): string
    {
        $deadline = $terminal->addSeconds(max(60, (int) $attempt->retry_cooldown_seconds, $retryAfterSeconds ?? 0));
        if ($attempt->retry_not_before !== null) {
            $deadline = $deadline->max($this->time($attempt->retry_not_before));
        }

        return $this->stamp($deadline);
    }

    private function releaseConcurrency(#[SensitiveParameter] stdClass $attempt): void
    {
        if (! $attempt->concurrency_held) {
            return;
        }
        foreach ($this->scopes((int) $attempt->account_id) as $scope) {
            $changed = DB::table('ai_usage_scopes')->where('scope_key', $scope)->where('concurrency_in_use', '>', 0)->decrement('concurrency_in_use');
            if ($changed !== 1) {
                throw new AiNetworkException('usage_store_unavailable');
            }
        }
        $attempt->concurrency_held = false;
        DB::table('ai_usage_attempts')->where('attempt_id', $attempt->attempt_id)->update(['concurrency_held' => false]);
    }

    private function update(#[SensitiveParameter] stdClass $attempt, array $values, CarbonImmutable $now): void
    {
        DB::table('ai_usage_attempts')->where('attempt_id', $attempt->attempt_id)->update([...$values, 'updated_at' => $this->stamp($now)]);
        foreach ($values as $key => $value) {
            $attempt->{$key} = $value;
        }
    }

    private function reservation(#[SensitiveParameter] stdClass $attempt, bool $reused = false): AiUsageReservation
    {
        return new AiUsageReservation($attempt->attempt_id, (int) $attempt->account_id, $attempt->source,
            $attempt->connection_revision, $attempt->purpose, $attempt->work_fingerprint, $attempt->state,
            $attempt->reserved_day, (int) $attempt->reserved_tokens, (int) $attempt->max_output_tokens,
            $this->time($attempt->expires_at), $attempt->retry_not_before === null ? null : $this->time($attempt->retry_not_before), $reused);
    }

    private function matches(#[SensitiveParameter] stdClass $attempt, #[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose): void
    {
        if ((int) $attempt->account_id !== $connection->accountId || $attempt->source !== $connection->source
            || $attempt->connection_revision !== $connection->revision || $attempt->purpose !== $purpose) {
            throw new AiNetworkException('usage_attempt_mismatch');
        }
    }

    private function identity(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId): void
    {
        $this->uuid($attemptId);
        if ($connection->accountId <= 0 || ! in_array($connection->source, ['account', 'installation'], true)
            || preg_match('/\A[a-f0-9]{64}\z/', $connection->revision) !== 1 || ! in_array($purpose, self::PURPOSES, true)) {
            throw new AiNetworkException('usage_invalid_request');
        }
    }

    private function nonce(#[SensitiveParameter] ?string $expected, #[SensitiveParameter] string $given): void
    {
        if ($expected === null || ! hash_equals($expected, $given)) {
            throw new AiNetworkException('usage_attempt_mismatch');
        }
    }

    private function uuid(#[SensitiveParameter] ?string $id): void
    {
        if ($id !== null && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $id) !== 1) {
            throw new AiNetworkException('usage_invalid_request');
        }
    }

    private function reason(#[SensitiveParameter] string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new AiNetworkException('usage_invalid_reason');
        }
    }

    private function scopes(int $accountId): array
    {
        return ['installation', 'account:'.$accountId];
    }

    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function stamp(CarbonImmutable $value): string
    {
        return $value->utc()->format('Y-m-d H:i:s.u');
    }

    private function unwrap(#[SensitiveParameter] mixed $result): mixed
    {
        if ($result instanceof AiNetworkException) {
            throw new AiNetworkException($result->reason);
        }

        return $result;
    }
}
