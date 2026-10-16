<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\Ollama\OllamaGateway;

final class GuardedOllamaGateway extends OllamaGateway
{
    use CreatesGuardedAiClient;
}
