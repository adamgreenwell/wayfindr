<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use UnexpectedValueException;

/** Laravel AI SDK adapter kept behind Wayfindr's deliberately tiny contract. */
final readonly class LaravelAgentCopilotProvider implements AgentCopilotProvider
{
    public function __construct(
        private AgentCopilotConfiguration $configuration,
        private AiContextSanitizer $sanitizer,
        private AgentCopilotSdkFactory $sdkFactory,
    ) {}

    public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
    {
        $connection = $prompt->connection;

        // Account credentials remain inert until #1168 supplies the endpoint
        // policy and probe boundary. A test fake is not outbound authority.
        if ($connection !== null && $connection->source !== 'installation') {
            throw AgentCopilotUnavailable::forConfigurationStatus('account_endpoint_policy_pending');
        }

        $assessment = $connection === null
            ? $this->configuration->assessment()
            : $this->configuration->assessValues(
                $connection->provider,
                $connection->model,
                $connection->endpoint,
                trim($connection->apiKey()) !== '',
                $connection->openRouterProvider,
            );

        if ($assessment['status'] !== 'ready') {
            throw AgentCopilotUnavailable::forConfigurationStatus($assessment['status']);
        }

        $maximum = max(1_000, (int) config('wayfindr.ai.max_context_characters', 30_000));
        $input = mb_substr($this->sanitizer->sanitize($prompt->input), 0, $maximum);
        $sdk = $this->sdkFactory->make(
            $assessment['provider'],
            $assessment['model'],
            $assessment['endpoint'],
            $connection?->apiKey() ?? (string) config('ai.providers.wayfindr.key'),
        );
        $agent = new CopilotAgent($prompt->instructions, $assessment['openrouter_provider']);

        if (Ai::hasFakeGatewayFor($agent)) {
            $sdk->useTextGateway(Ai::fakeGatewayFor($agent));
        }

        $response = $sdk->prompt(new AgentPrompt(
            $agent,
            $input,
            [],
            $sdk,
            $assessment['model'],
            $prompt->timeoutSeconds,
            tools: [],
        ));
        $text = trim($response->text);

        if ($text === '') {
            throw new UnexpectedValueException('The configured AI provider returned an empty response.');
        }

        $provider = $assessment['provider'];

        if ($provider === 'openrouter') {
            $provider .= '/'.$assessment['openrouter_provider'];
        }

        return new AgentCopilotResult(
            text: $text,
            // The SDK names this configured adapter `wayfindr`. Persist the
            // selected driver instead so audit/evaluation provenance identifies
            // the actual processor rather than our internal alias.
            provider: $provider,
            model: $response->meta->model ?? $assessment['model'],
            // The SDK's 1.0 names; Wayfindr keeps prompt/completion in its own
            // contract and stored columns. Input includes any cached tokens.
            promptTokens: $response->usage->inputTokens,
            completionTokens: $response->usage->outputTokens,
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
