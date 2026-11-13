<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

final readonly class AiProviderRequestRunner
{
    public function __construct(private AiUsageLedger $ledger, private AiUsagePolicy $policy,
        private AiRequestAuthority $authority, private AiGuardedTransportFactory $transports, private AiUsageClock $clock) {}

    public function session(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] AiExecutionContext $context): AiGuardedSession
    {
        try {
            if ($connection->accountId !== $context->accountId) {
                throw new AiNetworkException('execution_identity_invalid');
            }
            $stored = $context->requiresReservation() ? DB::table('ai_usage_attempts')->where('attempt_id', $context->attemptId)->first() : null;
            if ($context->requiresReservation() && $stored === null) {
                throw new AiNetworkException('usage_reservation_missing');
            }
            $output = $context->kind === 'discovery' ? 0 : ($stored === null
                ? min(64, $this->policy->forConnection($connection)->maxOutputTokens) : (int) $stored->max_output_tokens);

            return new AiProviderRequestSession($connection, $context, $output, $this->ledger, $this->authority,
                $this->transports->make($context), $this->clock);
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('usage_store_unavailable');
        }
    }
}
