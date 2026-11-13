<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Execution\AiProviderRequestRunner;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Contracts\Container\Container;
use SensitiveParameter;
use stdClass;
use Throwable;

/** One bounded catalogue request. Manual model entry always remains available. */
final readonly class GuardedAiModelDiscovery implements AiModelDiscovery
{
    private const MAX_MODELS = 100;

    private const MAX_RESPONSE_BYTES = 262_144;

    public function __construct(private Container $container) {}

    public function discover(
        #[SensitiveParameter] ResolvedAgentCopilotConnection $connection,
        #[SensitiveParameter] ?AiExecutionContext $context = null,
    ): ?array {
        if ($context === null) {
            return null;
        }
        if ($context->kind !== 'discovery' || $context->purpose !== 'model_discovery'
            || $context->accountId !== $connection->accountId || $context->actorId <= 0) {
            throw new AiNetworkException('discovery_context_required');
        }

        $session = null;
        $recorded = false;
        $settlementAttempted = false;
        try {
            $path = match ($connection->provider) {
                'anthropic', 'gemini', 'openai', 'openai-compatible', 'openrouter' => '/models',
                'ollama' => '/api/tags',
                default => throw new AiNetworkException('unsupported_provider'),
            };
            $url = AiProviderRoutes::baseUrl($connection).$path;
            $runner = $this->container->make(AiProviderRequestRunner::class);
            $session = $runner->session($connection, $context);
            $receipt = $session->request('GET', $url, $this->headers($connection));

            // These metadata endpoints do not generate tokens. They still
            // consume request allowance and concurrency through the session.
            $session->recordResponse($receipt, 0, 0);
            $recorded = true;
            $stream = $receipt->response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = $stream->read(self::MAX_RESPONSE_BYTES + 1);
            if (strlen($body) > self::MAX_RESPONSE_BYTES || ! $stream->eof()) {
                throw new AiNetworkException('discovery_response_too_large');
            }
            $data = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
            $models = $this->models($connection->provider, $data);
            $settlementAttempted = true;
            $session->settle('completed');

            return $models;
        } catch (Throwable $exception) {
            $reason = $settlementAttempted ? 'usage_reconciliation_unavailable'
                : ($exception instanceof AiNetworkException ? $exception->reason : ($recorded ? 'discovery_invalid_response' : 'discovery_unavailable'));
            if ($recorded && $session instanceof AiGuardedSession && ! $settlementAttempted) {
                try {
                    $session->settle('invalid_output');
                } catch (Throwable) {
                    $reason = 'usage_reconciliation_unavailable';
                }
            }

            throw new AiNetworkException($reason);
        }
    }

    /** @return array<string, string> */
    private function headers(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): array
    {
        $key = $connection->apiKey();

        return match ($connection->provider) {
            'anthropic' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            'gemini' => ['x-goog-api-key' => $key],
            default => $key === '' ? [] : ['Authorization' => 'Bearer '.$key],
        };
    }

    /** @return list<string> */
    private function models(string $provider, #[SensitiveParameter] mixed $data): array
    {
        $field = in_array($provider, ['gemini', 'ollama'], true) ? 'models' : 'data';
        $rows = $data instanceof stdClass ? ($data->{$field} ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new AiNetworkException('discovery_invalid_response');
        }

        $models = [];
        foreach ($rows as $row) {
            $idField = in_array($provider, ['gemini', 'ollama'], true) ? 'name' : 'id';
            $id = $row instanceof stdClass ? ($row->{$idField} ?? null) : null;
            if ($provider === 'gemini' && is_string($id)) {
                $id = str_starts_with($id, 'models/') ? substr($id, 7) : null;
            }
            if (! is_string($id) || strlen($id) > 255 || preg_match('/\A[^\x00-\x20\x7f]+\z/u', $id) !== 1) {
                throw new AiNetworkException('discovery_invalid_response');
            }
            if (! in_array($id, $models, true)) {
                $models[] = $id;
            }
            if (count($models) >= self::MAX_MODELS) {
                break;
            }
        }

        return $models;
    }
}
