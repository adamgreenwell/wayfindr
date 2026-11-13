<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Execution\AiExecutionContext;
use SensitiveParameter;

/** #1168 supplies destination policy and the actual synthetic executor. */
final class UnavailableAccountAiConnectionProbe implements AccountAiConnectionProbe
{
    public function policyRevision(): string
    {
        return 'account-probes-unavailable-v1';
    }

    public function probe(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): AgentCopilotResult
    {
        throw AgentCopilotUnavailable::forConfigurationStatus('account_probe_unavailable');
    }
}
