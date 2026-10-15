<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Server-only, single-use claims around an already reserved attempt. */
interface AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): string;

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId, #[SensitiveParameter] string $claimNonce): string;

    public function cancelUnsent(string $attemptId, #[SensitiveParameter] string $claimNonce, string $reason = 'known_unsent'): void;

    public function markUnknown(string $attemptId, #[SensitiveParameter] string $dispatchNonce, string $reason = 'usage_unavailable'): void;
}
