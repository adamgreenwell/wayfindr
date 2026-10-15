<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Endpoint safety alone never authorizes a new provider attempt. */
final class UnavailableAiOutboundAdmission implements AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): void
    {
        throw new AiNetworkException('usage_admission_unavailable');
    }
}
