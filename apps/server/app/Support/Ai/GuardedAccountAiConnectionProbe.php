<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use Illuminate\Contracts\Container\Container;
use SensitiveParameter;
use Throwable;

/** Synthetic text only. Proposal authority belongs to the guarded execution. */
final readonly class GuardedAccountAiConnectionProbe implements AccountAiConnectionProbe
{
    public const INSTRUCTIONS = 'Reply with a short plain-text acknowledgement such as OK. Do not use tools.';

    public const INPUT = 'Wayfindr synthetic connection check. No customer, visitor, conversation or article data is included.';

    public function __construct(private AiNetworkPolicy $policy, private Container $container) {}

    public function policyRevision(): string
    {
        try {
            $configuration = $this->container->make('config');
            $key = $configuration->get('app.key');
            if (! is_string($key) || $key === '') {
                throw new AiNetworkException('probe_policy_unavailable');
            }

            return hash_hmac('sha256', json_encode([
                'version' => 'ai-guarded-execution-v1',
                'network' => $this->policy->revision(),
                'context_limit' => max(1_000, (int) $configuration->get('wayfindr.ai.max_context_characters', 30_000)),
            ], JSON_THROW_ON_ERROR), $key);
        } catch (Throwable) {
            throw new AiNetworkException('probe_policy_unavailable');
        }
    }

    public function probe(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        #[SensitiveParameter] ?AiExecutionContext $context = null,
    ): AgentCopilotResult {
        if ($context === null || $context->kind !== 'probe' || $context->purpose !== 'configuration_probe'
            || $context->accountId !== $connection->accountId || $context->actorId <= 0) {
            throw new AiNetworkException('probe_context_required');
        }

        try {
            // The provider and runner are resolved only after construction, so
            // Resolver -> Probe -> Provider cannot become a container cycle.
            $provider = $this->container->make(AgentCopilotProvider::class);
            $result = $provider->generate((new AgentCopilotPrompt(
                purpose: 'configuration_probe',
                instructions: self::INSTRUCTIONS,
                input: self::INPUT,
                timeoutSeconds: 20,
            ))->withExecution($connection, $context));

            $acknowledgement = trim($result->text);
            if ($acknowledgement === '' || strlen($acknowledgement) > 1_024 || mb_strlen($acknowledgement) > 256) {
                $result->settle('invalid_output');
                throw new AiNetworkException('probe_invalid_output');
            }

            $result->settle('completed');

            return $result;
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('probe_unavailable');
        }
    }
}
