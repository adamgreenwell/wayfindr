<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Network\AiNetworkException;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use SensitiveParameter;

/** Each invocation owns a fresh SDK gateway and a single guarded session. */
final readonly class GuardedAiGatewayFactory
{
    public function __construct(private Dispatcher $events) {}

    public function make(#[SensitiveParameter] AiGuardedSession $session): StepTextGateway
    {
        return match ($session->connection()->provider) {
            'openai' => new GuardedOpenAiGateway($this->events, $session),
            'gemini' => new GuardedGeminiGateway($this->events, $session),
            'anthropic' => new GuardedAnthropicGateway($this->events, $session),
            'ollama' => new GuardedOllamaGateway($this->events, $session),
            'openai-compatible' => new GuardedOpenAiCompatibleGateway($this->events, $session),
            'openrouter' => new GuardedOpenRouterGateway($this->events, $session),
            default => throw new AiNetworkException('unsupported_provider'),
        };
    }
}
