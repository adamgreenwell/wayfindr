<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;

final class GuardedOpenAiCompatibleGateway extends OpenAiCompatibleGateway
{
    use CreatesGuardedAiClient;
}
