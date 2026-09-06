<?php

declare(strict_types=1);

namespace App\Support\Ai;

use UnexpectedValueException;

/** Laravel AI SDK adapter kept behind Wayfindr's deliberately tiny contract. */
final readonly class LaravelAgentCopilotProvider implements AgentCopilotProvider
{
    public function __construct(
        private AgentCopilotConfiguration $configuration,
        private AiContextSanitizer $sanitizer,
    ) {}

    public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
    {
        $assessment = $this->configuration->assessment();

        if ($assessment['status'] !== 'ready') {
            throw AgentCopilotUnavailable::forConfigurationStatus($assessment['status']);
        }

        $maximum = max(1_000, (int) config('wayfindr.ai.max_context_characters', 30_000));
        $input = mb_substr($this->sanitizer->sanitize($prompt->input), 0, $maximum);
        $response = (new CopilotAgent($prompt->instructions))->prompt(
            $input,
            provider: 'wayfindr',
            model: $assessment['model'],
            timeout: $prompt->timeoutSeconds,
        );
        $text = trim($response->text);

        if ($text === '') {
            throw new UnexpectedValueException('The configured AI provider returned an empty response.');
        }

        return new AgentCopilotResult(
            text: $text,
            provider: $response->meta->provider ?? $assessment['provider'],
            model: $response->meta->model ?? $assessment['model'],
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    public function probe(): AgentCopilotResult
    {
        return $this->generate(new AgentCopilotPrompt(
            purpose: 'configuration_probe',
            instructions: 'Answer this synthetic connectivity check with one short plain-text acknowledgement. Do not use tools.',
            input: 'Wayfindr provider configuration test. No support or visitor data is included.',
            timeoutSeconds: 20,
        ));
    }
}
