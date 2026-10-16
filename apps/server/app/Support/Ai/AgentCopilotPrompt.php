<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Execution\AiExecutionContext;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class AgentCopilotPrompt
{
    public function __construct(
        #[SensitiveParameter] public string $purpose,
        #[SensitiveParameter] public string $instructions,
        #[SensitiveParameter] public string $input,
        public int $timeoutSeconds = 30,
        #[SensitiveParameter] public ?ResolvedAgentCopilotConnection $connection = null,
        public ?AiExecutionContext $execution = null,
    ) {
        if (trim($purpose) === '' || trim($instructions) === '' || trim($input) === '') {
            throw new InvalidArgumentException('Copilot prompts require a purpose, instructions, and input.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('Copilot prompt timeout must be between 1 and 120 seconds.');
        }
    }

    public function withExecution(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $execution): self
    {
        return new self($this->purpose, $this->instructions, $this->input, $this->timeoutSeconds, $connection, $execution);
    }

    public function withConnection(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): self
    {
        return new self($this->purpose, $this->instructions, $this->input, $this->timeoutSeconds, $connection, $this->execution);
    }
}
