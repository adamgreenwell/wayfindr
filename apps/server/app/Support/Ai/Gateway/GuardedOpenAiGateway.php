<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;

final class GuardedOpenAiGateway extends OpenAiGateway
{
    use CreatesGuardedAiClient;
}
