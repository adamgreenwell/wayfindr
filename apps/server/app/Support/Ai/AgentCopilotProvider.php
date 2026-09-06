<?php

declare(strict_types=1);

namespace App\Support\Ai;

/** The only provider-facing contract product features may depend on. */
interface AgentCopilotProvider
{
    public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult;

    /** Send synthetic text only; never a support transcript. */
    public function probe(): AgentCopilotResult;
}
