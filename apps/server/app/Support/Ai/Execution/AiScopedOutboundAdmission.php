<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageLedger;
use SensitiveParameter;

/** A fresh scope for one request; never registered as an unscoped singleton. */
final readonly class AiScopedOutboundAdmission implements AiOutboundAdmission
{
    public function __construct(private AiUsageLedger $ledger, private AiRequestAuthority $authority, private AiExecutionContext $context) {}

    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId): string
    {
        $this->matches($purpose, $attemptId);

        return $this->ledger->claim($connection, $purpose, $attemptId, fn () => $this->authority->assertAllowed($connection, $this->context));
    }

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce): string
    {
        $this->matches($purpose, $attemptId);

        return $this->ledger->beginDispatch($connection, $purpose, $attemptId, $claimNonce,
            fn () => $this->authority->assertAllowed($connection, $this->context))->dispatchNonce;
    }

    public function cancelUnsent(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce, #[SensitiveParameter] string $reason = 'known_unsent'): void
    {
        $this->matches($this->context->purpose, $attemptId);
        $this->ledger->cancelUnsent($attemptId, $claimNonce, $reason);
    }

    public function markUnknown(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $dispatchNonce, #[SensitiveParameter] string $reason = 'usage_unavailable'): void
    {
        $this->matches($this->context->purpose, $attemptId);
        $this->ledger->markUnknown($attemptId, $dispatchNonce, $reason);
    }

    private function matches(#[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId): void
    {
        if ($purpose !== $this->context->purpose || $attemptId !== $this->context->attemptId) {
            throw new AiNetworkException('execution_identity_invalid');
        }
    }
}
