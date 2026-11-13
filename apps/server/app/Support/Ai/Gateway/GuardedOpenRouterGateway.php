<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;

final class GuardedOpenRouterGateway extends OpenRouterGateway
{
    use CreatesGuardedAiClient;
}
