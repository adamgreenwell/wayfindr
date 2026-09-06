<?php

declare(strict_types=1);

namespace App\Support\Ai;

use InvalidArgumentException;

final readonly class AgentCopilotPrompt
{
    public function __construct(
        public string $purpose,
        public string $instructions,
        public string $input,
        public int $timeoutSeconds = 30,
    ) {
        if (trim($purpose) === '' || trim($instructions) === '' || trim($input) === '') {
            throw new InvalidArgumentException('Copilot prompts require a purpose, instructions, and input.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('Copilot prompt timeout must be between 1 and 120 seconds.');
        }
    }
}
