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

    public function __construct(private readonly string $systemInstructions) {}

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

        $upstreamProvider = strtolower(trim((string) config('ai.providers.wayfindr.openrouter.provider')));

        if ($upstreamProvider === '') {
            return [];
        }

        return [
            'provider' => [
                'order' => [$upstreamProvider],
                'allow_fallbacks' => false,
                'zdr' => true,
            ],
        ];
    }
}
