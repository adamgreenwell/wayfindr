<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Endpoint safety alone never authorizes a new provider attempt. */
final class UnavailableAiOutboundAdmission implements AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId): string
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce): string
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function cancelUnsent(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce, #[SensitiveParameter] string $reason = 'known_unsent'): void
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }

    public function markUnknown(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $dispatchNonce, #[SensitiveParameter] string $reason = 'usage_unavailable'): void
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }
}
