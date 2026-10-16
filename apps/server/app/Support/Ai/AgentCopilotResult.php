<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Execution\AiGuardedSession;
use SensitiveParameter;

final readonly class AgentCopilotResult
{
    public function __construct(
        #[SensitiveParameter] public string $text,
        public string $provider,
        public string $model,
        public ?int $promptTokens = 0,
        public ?int $completionTokens = 0,
        #[SensitiveParameter] private ?AiGuardedSession $session = null,
    ) {}

    public function isGuarded(): bool
    {
        return $this->session !== null;
    }

    public function settle(#[SensitiveParameter] string $reason = 'completed'): void
    {
        $this->session?->settle($reason);
    }
}
