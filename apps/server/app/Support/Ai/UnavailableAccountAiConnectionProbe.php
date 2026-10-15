<?php

declare(strict_types=1);

namespace App\Support\Ai;

/** #1168 supplies destination policy and the actual synthetic executor. */
final class UnavailableAccountAiConnectionProbe implements AccountAiConnectionProbe
{
    public function policyRevision(): string
    {
        return 'account-probes-unavailable-v1';
    }

    public function probe(ResolvedAgentCopilotConnection $connection): AgentCopilotResult
    {
        throw AgentCopilotUnavailable::forConfigurationStatus('account_probe_unavailable');
    }
}
