<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** #1174 must atomically admit this exact server-owned attempt before DNS/I/O. */
interface AiOutboundAdmission
{
    public function assertAllowed(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): void;
}
