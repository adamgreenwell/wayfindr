<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** Optional, authorized/admitted bounded discovery; null keeps manual entry. */
interface AiModelDiscovery
{
    /** @return list<string>|null Null means discovery is unavailable. */
    public function discover(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): ?array;
}
