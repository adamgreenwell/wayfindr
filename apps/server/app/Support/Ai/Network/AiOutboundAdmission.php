<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Server-only, single-use claims around an already reserved attempt. */
interface AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId): string;

    public function beginDispatch(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, #[SensitiveParameter] string $purpose, #[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce): string;

    public function cancelUnsent(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $claimNonce, #[SensitiveParameter] string $reason = 'known_unsent'): void;

    public function markUnknown(#[SensitiveParameter] string $attemptId, #[SensitiveParameter] string $dispatchNonce, #[SensitiveParameter] string $reason = 'usage_unavailable'): void;
}
