<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageLedger;
use SensitiveParameter;

/** Bind only after adapters enforce the reserved input and output token caps. */
final readonly class LedgerAiOutboundAdmission implements AiOutboundAdmission
{
    public function __construct(private AiUsageLedger $ledger) {}

    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): string
    {
        return $this->ledger->claim($connection, $purpose, $attemptId);
    }

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId, #[SensitiveParameter] string $claimNonce): string
    {
        return $this->ledger->beginDispatch($connection, $purpose, $attemptId, $claimNonce)->dispatchNonce;
    }

    public function cancelUnsent(string $attemptId, #[SensitiveParameter] string $claimNonce, string $reason = 'known_unsent'): void
    {
        $this->ledger->cancelUnsent($attemptId, $claimNonce, $reason);
    }

    public function markUnknown(string $attemptId, #[SensitiveParameter] string $dispatchNonce, string $reason = 'usage_unavailable'): void
    {
        $this->ledger->markUnknown($attemptId, $dispatchNonce, $reason);
    }
}
