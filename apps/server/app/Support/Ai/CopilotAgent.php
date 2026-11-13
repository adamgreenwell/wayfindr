<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/** A stateless agent: Wayfindr owns context selection and persistence. */
final class CopilotAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly string $openRouterProvider = '',
        private readonly ?int $outputCap = null,
        private readonly bool $guarded = false,
    ) {}

    public function maxTokens(): ?int
    {
        return $this->outputCap;
    }

    public function maxSteps(): int
    {
        return 1;
    }

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    /**
     * Keep OpenRouter evaluation and runtime requests on one attributable,
     * zero-retention endpoint instead of accepting transparent rerouting.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        if ($driver !== Lab::OpenRouter->value) {
            return [];
        }

        $upstreamProvider = strtolower(trim($this->openRouterProvider));

        if ($upstreamProvider === '') {
            return [];
        }

        return [
            'provider' => [
                'order' => [$upstreamProvider],
                'allow_fallbacks' => false,
                'zdr' => true,
                ...($this->guarded ? ['require_parameters' => true] : []),
            ],
        ];
    }
}
