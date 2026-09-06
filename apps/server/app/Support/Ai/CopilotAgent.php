<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/** A stateless agent: Wayfindr owns context selection and persistence. */
final class CopilotAgent implements Agent
{
    use Promptable;

    public function __construct(private readonly string $systemInstructions) {}

    public function instructions(): string
    {
        return $this->systemInstructions;
    }
}
