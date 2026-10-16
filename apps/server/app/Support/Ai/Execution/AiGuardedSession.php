<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use SensitiveParameter;

/** One server-owned request, never a shared client or a queued capability. */
interface AiGuardedSession
{
    public function connection(): ResolvedAgentCopilotConnection;

    public function maxOutputTokens(): int;

    public function dispatch(#[SensitiveParameter] string $url, #[SensitiveParameter] array $headers, #[SensitiveParameter] string $json): AiHttpResult;

    public function request(#[SensitiveParameter] string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $headers, #[SensitiveParameter] string $body = ''): AiHttpResult;

    public function recordResponse(#[SensitiveParameter] AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void;

    public function settle(#[SensitiveParameter] string $reason = 'completed'): void;

    /** @return array{input: ?int, output: ?int} */
    public function usage(): array;
}
