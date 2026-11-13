<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use App\Support\Ai\Execution\AiGuardedSession;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use LogicException;
use SensitiveParameter;

trait CreatesGuardedAiClient
{
    public function __construct(Dispatcher $events, #[SensitiveParameter] private readonly AiGuardedSession $session)
    {
        parent::__construct($events);
    }

    protected function createClient(
        #[SensitiveParameter] string $baseUrl,
        #[SensitiveParameter] array $headers = [],
        #[SensitiveParameter] array $configuredHeaders = [],
        ?int $timeout = 60,
        bool $throw = true,
    ): PendingRequest {
        return new GuardedAiPendingRequest($this->session, $baseUrl, $headers, $configuredHeaders);
    }

    public function __debugInfo(): array
    {
        return ['guarded' => true];
    }

    public function __serialize(): array
    {
        throw new LogicException('Guarded AI gateways cannot be serialized.');
    }
}
