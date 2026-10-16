<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Execution\AiProviderRequestRunner;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Gateway\GuardedAiGatewayFactory;
use App\Support\Ai\Network\AiNetworkException;
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

/** Laravel AI SDK adapter kept behind Wayfindr's deliberately tiny contract. */
final readonly class LaravelAgentCopilotProvider implements AgentCopilotProvider
{
    public function __construct(
        private AgentCopilotConfiguration $configuration,
        private AiContextSanitizer $sanitizer,
        private AgentCopilotSdkFactory $sdkFactory,
        private AgentCopilotConnectionResolver $connections,
        private AiProviderRequestRunner $runner,
        private GuardedAiGatewayFactory $gateways,
    ) {}

    public function generate(#[SensitiveParameter] AgentCopilotPrompt $prompt): AgentCopilotResult
    {
        $connection = $prompt->connection;

        if ($prompt->execution !== null) {
            return $this->generateGuarded($prompt);
        }
        // Preserve only the existing unselected installation-default path.
        if ($connection !== null && ! $this->connections->isLegacyInstallationDefault($connection)) {
            throw AgentCopilotUnavailable::forConfigurationStatus('execution_context_required');
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

    private function generateGuarded(#[SensitiveParameter] AgentCopilotPrompt $prompt): AgentCopilotResult
    {
        $session = null;
        try {
            $connection = $prompt->connection;
            $context = $prompt->execution;
            if ($connection === null || $context === null || $prompt->purpose !== $context->purpose
                || $context->kind === 'discovery') {
                throw new AiNetworkException('execution_identity_invalid');
            }
            $assessment = $this->configuration->assessValues($connection->provider, $connection->model,
                $connection->endpoint, trim($connection->apiKey()) !== '', $connection->openRouterProvider);
            if ($assessment['status'] !== 'ready') {
                throw new AiNetworkException('incomplete_configuration');
            }
            if ($context->kind === 'probe' && ($prompt->instructions !== GuardedAccountAiConnectionProbe::INSTRUCTIONS || $prompt->input !== GuardedAccountAiConnectionProbe::INPUT)) {
                throw new AiNetworkException('probe_context_required');
            }
            $session = $this->runner->session($connection, $context);
            AiSupportUsage::assertActualPrompt($connection, $context, $prompt);
            $sdk = $this->sdkFactory->make($connection->provider, $connection->model, $connection->endpoint, $connection->apiKey());
            $agent = new CopilotAgent($prompt->instructions, $connection->openRouterProvider, $session->maxOutputTokens(), true);
            // Guarded execution never accepts a global SDK/HTTP fake as authority.
            $sdk->useTextGateway($this->gateways->make($session));
            $response = $sdk->prompt(new AgentPrompt($agent, AiSupportUsage::sanitizedInput($prompt), [], $sdk,
                $connection->model, min(20, $prompt->timeoutSeconds), tools: []));
            $text = trim($response->text);
            if ($text === '' || strlen($text) > 262_144 || $response->toolCalls->isNotEmpty()) {
                throw new AiNetworkException('invalid_output');
            }
            $usage = $session->usage();
            $provider = $connection->provider === 'openrouter'
                ? 'openrouter/'.$connection->openRouterProvider : $connection->provider;

            return new AgentCopilotResult($text, $provider, $connection->model,
                $usage['input'], $usage['output'], $session);
        } catch (Throwable $exception) {
            $reason = $exception instanceof AiNetworkException ? $exception->reason : 'invalid_output';
            if ($session !== null) {
                try {
                    $session->settle('invalid_output');
                } catch (Throwable) {
                    $reason = 'usage_reconciliation_unavailable';
                }
            }
            throw new AiNetworkException($reason);
        }
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
