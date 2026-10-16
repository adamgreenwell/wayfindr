<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\Gemini\GeminiGateway;

final class GuardedGeminiGateway extends GeminiGateway
{
    use CreatesGuardedAiClient;
}
