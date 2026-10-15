<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use SensitiveParameter;

/** Fresh SDK drivers keep credentials and routing out of shared config/cache. */
final readonly class AgentCopilotSdkFactory
{
    public function __construct(private AiManager $manager) {}

    public function make(
        string $provider,
        string $model,
        string $endpoint,
        #[SensitiveParameter] string $apiKey,
    ): TextProvider {
        $config = [
            'name' => 'wayfindr',
            'driver' => $provider,
            'key' => $apiKey,
            'url' => $endpoint === '' ? null : $endpoint,
            'store' => false,
            'models' => ['text' => ['default' => $model]],
        ];

        return match ($provider) {
            'anthropic' => $this->manager->createAnthropicDriver($config),
            'gemini' => $this->manager->createGeminiDriver($config),
            'ollama' => $this->manager->createOllamaDriver($config),
            'openai' => $this->manager->createOpenaiDriver($config),
            'openai-compatible' => $this->manager->createOpenaiCompatibleDriver($config),
            'openrouter' => $this->manager->createOpenrouterDriver($config),
            default => throw AgentCopilotUnavailable::forConfigurationStatus('unsupported'),
        };
    }
}
