<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;

final class GuardedAnthropicGateway extends AnthropicGateway
{
    use CreatesGuardedAiClient;
}
