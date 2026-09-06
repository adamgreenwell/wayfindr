<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class AgentCopilotResult
{
    public function __construct(
        public string $text,
        public string $provider,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}
}
