<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Endpoint safety alone never authorizes a new provider attempt. */
final class UnavailableAiOutboundAdmission implements AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): string
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId, #[SensitiveParameter] string $claimNonce): string
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function cancelUnsent(string $attemptId, #[SensitiveParameter] string $claimNonce, string $reason = 'known_unsent'): void
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function markUnknown(string $attemptId, #[SensitiveParameter] string $dispatchNonce, string $reason = 'usage_unavailable'): void
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }
}
