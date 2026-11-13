<?php

declare(strict_types=1);

namespace App\Support\Ai\Gateway;

use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Network\AiNetworkException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LogicException;
use SensitiveParameter;
use Throwable;

/** A synchronous SDK shim; it never invokes Laravel's HTTP factory or send. */
final class GuardedAiPendingRequest extends PendingRequest
{
    private readonly array $guardedHeaders;

    private bool $sent = false;

    public function __construct(
        #[SensitiveParameter] private readonly AiGuardedSession $session,
        #[SensitiveParameter] string $baseUrl,
        #[SensitiveParameter] array $headers = [],
        #[SensitiveParameter] array $configuredHeaders = [],
    ) {
        parent::__construct();
        $this->guardedHeaders = AiRequestProtocol::headers($session->connection(), $baseUrl, $headers, $configuredHeaders);
    }

    public function send(#[SensitiveParameter] string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] array $options = []): Response
    {
        try {
            if ($this->sent) {
                throw new AiNetworkException('usage_attempt_dispatched');
            }
            // Consume even a refused request; a shim must never become a retry client.
            $this->sent = true;
            $connection = $this->session->connection();
            $request = AiRequestProtocol::request($connection, $this->session->maxOutputTokens(), $method, $url, $options);
            $receipt = $this->session->dispatch($request['url'], $this->guardedHeaders, $request['json']);
            $response = new Response($receipt->response);
            $data = $response->json();
            $usage = AiReportedUsageParser::parse($connection->provider, is_array($data) ? $data : []);
            // Capture evidence now; the runner completes only after task validation.
            $this->session->recordResponse($receipt, $usage['input_tokens'], $usage['output_tokens']);

            return $response;
        } catch (AiNetworkException $exception) {
            throw new AiNetworkException($exception->reason);
        } catch (Throwable) {
            throw new AiNetworkException('provider_failed');
        }
    }

    public function __debugInfo(): array
    {
        return ['guarded' => true, 'sent' => $this->sent];
    }

    public function __serialize(): array
    {
        throw new LogicException('Guarded AI clients cannot be serialized.');
    }
}
