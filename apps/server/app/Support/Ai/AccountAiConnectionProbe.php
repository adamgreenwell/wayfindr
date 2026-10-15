<?php

declare(strict_types=1);

namespace App\Support\Ai;

/** Server-owned synthetic executor; browser input can never attest a test. */
interface AccountAiConnectionProbe
{
    /** Changes whenever applicable processing/network policy changes. */
    public function policyRevision(): string;

    public function probe(ResolvedAgentCopilotConnection $connection): AgentCopilotResult;
}
