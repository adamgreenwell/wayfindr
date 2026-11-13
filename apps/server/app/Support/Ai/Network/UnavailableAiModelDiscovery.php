<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** No discovery call exists until guarded adapters and usage admission land. */
final class UnavailableAiModelDiscovery implements AiModelDiscovery
{
    public function discover(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): ?array
    {
        return null;
    }
}
