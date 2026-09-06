<?php

declare(strict_types=1);

namespace App\Support\Ai;

use RuntimeException;

final class AgentCopilotUnavailable extends RuntimeException
{
    public static function forConfigurationStatus(string $status): self
    {
        return new self("Agent copilot provider is unavailable ({$status}).");
    }
}
