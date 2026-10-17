<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use App\Support\Ai\AgentCopilotPrompt;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Private construction result, never shared preparation or dispatch/Apply authority. */
final readonly class DraftRefinementContext implements JsonSerializable
{
    public function __construct(
        #[SensitiveParameter] private AgentCopilotPrompt $prompt,
        private array $snapshot,
        #[SensitiveParameter] private array $sources,
    ) {}

    /** Explicit private task input; execution still requires usage and erasure admission. */
    public function providerPrompt(): AgentCopilotPrompt
    {
        return $this->prompt;
    }

    /** Server-only signed scope/proofs, without original draft or source prose. */
    public function sourceSnapshot(): array
    {
        return $this->snapshot;
    }

    public function providerSources(): array
    {
        return $this->sources;
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Refinement context is private; explicitly select the authorized task payload.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Refinement contexts cannot enter queues or shared caches.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => $this->prompt->purpose, 'input_characters' => mb_strlen($this->prompt->input), 'source_count' => count($this->sources)];
    }
}
