<?php

use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\AiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

class AiLifecycleAdmissionFixture implements AiOutboundAdmission
{
    public array $events = [];

    public ?Closure $claimAction = null;

    public ?Closure $dispatchAction = null;

    public ?Closure $unknownAction = null;

    public ?Closure $cancelAction = null;

    public function assertAllowed(ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): string
    {
        $this->events[] = ['claim', $attemptId];

        return $this->claimAction === null ? 'claim-nonce' : ($this->claimAction)();
    }

    public function beginDispatch(ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId, string $claimNonce): string
    {
        $this->events[] = ['dispatch', $attemptId, $claimNonce];

        return $this->dispatchAction === null ? 'dispatch-nonce' : ($this->dispatchAction)();
    }

    public function cancelUnsent(string $attemptId, string $claimNonce, string $reason = 'known_unsent'): void
    {
        $this->events[] = ['cancel', $attemptId, $claimNonce, $reason];
        if ($this->cancelAction !== null) {
            ($this->cancelAction)();
        }
    }

    public function markUnknown(string $attemptId, string $dispatchNonce, string $reason = 'usage_unavailable'): void
    {
        $this->events[] = ['unknown', $attemptId, $dispatchNonce, $reason];
        if ($this->unknownAction !== null) {
            ($this->unknownAction)();
        }
    }
}

function aiLifecycleConnection(): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection(7, 'account', str_repeat('a', 64), 'openai-compatible', 'synthetic-model', 'https://models.example.test/v1', '', 'synthetic-key');
}

function aiLifecyclePolicy(AiLifecycleAdmissionFixture $admission, ?Closure $resolve = null): AiNetworkPolicy
{
    $dns = new class($admission, $resolve) implements AiDnsResolver
    {
        public function __construct(private AiLifecycleAdmissionFixture $admission, private ?Closure $resolve) {}

        public function resolve(string $host, float $deadline): array
        {
            $this->admission->events[] = ['dns'];

            return $this->resolve === null ? ['8.8.8.8'] : ($this->resolve)();
        }
    };

    return new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-key');
}

function aiLifecycleReason(Closure $operation): string
{
    try {
        $operation();
    } catch (AiNetworkException $exception) {
        expect($exception->getPrevious())->toBeNull();

        return $exception->reason;
    }

    test()->fail('The attempt must stop without a usable response.');
}

test('AI dispatch consumes the claim after DNS and persists unknown usage before returning its receipt', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, function () use ($admission) {
        $admission->events[] = ['send'];

        return Create::promiseFor(new Response(200, [], 'private-output-marker'));
    });
    $result = $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'POST', 'https://models.example.test/v1/chat', [], '{}');

    expect(array_column($admission->events, 0))->toBe(['claim', 'dns', 'dispatch', 'send', 'unknown'], 'One claimed worker must consume one dispatch and record its conservative outcome before exposing a result.')
        ->and($admission->events[2])->toBe(['dispatch', 'attempt', 'claim-nonce'])
        ->and($admission->events[4])->toBe(['unknown', 'attempt', 'dispatch-nonce', 'usage_unavailable'])
        ->and($result)->toBeInstanceOf(AiHttpResult::class)
        ->and([$result->attemptId, $result->dispatchNonce])->toBe(['attempt', 'dispatch-nonce'])
        ->and((string) $result->response->getBody())->toBe('private-output-marker');
    expect(fn () => json_encode($result, JSON_THROW_ON_ERROR))->toThrow(LogicException::class)
        ->and(fn () => serialize($result))->toThrow(LogicException::class);
    ob_start();
    var_dump($result);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('dispatch-nonce', 'private-output-marker');
});

test('AI preflight rejection never releases another workers hold or reaches DNS', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $admission->claimAction = fn () => throw new AiNetworkException('usage_request_limit');
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, fn () => throw new LogicException('must not send'));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('usage_request_limit')
        ->and(array_column($admission->events, 0))->toBe(['claim'], 'A losing duplicate worker owns no claim to cancel.');
});

test('AI DNS failure releases only its known unsent claim', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission, fn () => throw new AiNetworkException('dns_unavailable')), $admission, fn () => throw new LogicException('must not send'));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('dns_unavailable')
        ->and($admission->events)->toBe([['claim', 'attempt'], ['dns'], ['cancel', 'attempt', 'claim-nonce', 'known_unsent']]);
});

test('AI dispatch admission can refuse after DNS without transmitting', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $admission->dispatchAction = fn () => throw new AiNetworkException('usage_concurrency_limit');
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, fn () => throw new LogicException('must not send'));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('usage_concurrency_limit')
        ->and($admission->events)->toBe([['claim', 'attempt'], ['dns'], ['dispatch', 'attempt', 'claim-nonce'], ['cancel', 'attempt', 'claim-nonce', 'known_unsent']]);
});

test('AI dispatch lock waits reduce both wire timeouts under the original deadline', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $clock = hrtime(true) / 1e9;
    $admission->dispatchAction = function () use (&$clock): string {
        $clock += 18;

        return 'dispatch-nonce';
    };
    $options = [];
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, function ($request, array $wireOptions) use (&$options) {
        $options = $wireOptions;

        return Create::promiseFor(new Response(200, [], '{}'));
    }, function () use (&$clock): float {
        return $clock;
    });
    $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models');

    expect([$options['timeout'], $options['connect_timeout']])->toBe([2.0, 2.0], 'A database wait cannot extend the transport deadline or preserve a stale connect timeout.');
});

test('AI dispatch deadline exhaustion never reaches HTTP and conservatively records timeout', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $clock = hrtime(true) / 1e9;
    $admission->dispatchAction = function () use (&$clock): string {
        $clock += 21;

        return 'dispatch-nonce';
    };
    $calls = 0;
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200));
    }, function () use (&$clock): float {
        return $clock;
    });
    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('deadline_exceeded')
        ->and($calls)->toBe(0)
        ->and(end($admission->events))->toBe(['unknown', 'attempt', 'dispatch-nonce', 'timeout']);
});

test('AI post dispatch failure keeps a conservative debit rather than cancelling', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, fn () => throw new RuntimeException('private-provider-error'));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('transport_failed')
        ->and(array_column($admission->events, 0))->toBe(['claim', 'dns', 'dispatch', 'unknown'])
        ->and(end($admission->events))->toBe(['unknown', 'attempt', 'dispatch-nonce', 'transport_failed']);
});

test('AI outcome persistence failure withholds a successful response without retrying settlement', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $admission->unknownAction = fn () => throw new RuntimeException('private-usage-store-error');
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, fn () => Create::promiseFor(new Response(200, [], 'private-output-marker')));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('usage_reconciliation_unavailable')
        ->and(array_column($admission->events, 0))->toBe(['claim', 'dns', 'dispatch', 'unknown'], 'An ambiguous outcome write must withhold output and leave its bounded lease for recovery.');
});

test('AI successful outcome persistence cannot return output after the absolute deadline', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $clock = hrtime(true) / 1e9;
    $admission->unknownAction = function () use (&$clock): void {
        $clock += 21;
    };
    $calls = 0;
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission), $admission, function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200, [], 'private-output-marker'));
    }, function () use (&$clock): float {
        return $clock;
    });

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('deadline_exceeded', 'A successful outcome write cannot extend the absolute deadline or expose late output.')
        ->and($calls)->toBe(1)
        ->and($admission->events)->toBe([
            ['claim', 'attempt'], ['dns'], ['dispatch', 'attempt', 'claim-nonce'],
            ['unknown', 'attempt', 'dispatch-nonce', 'usage_unavailable'],
        ], 'A persisted outcome must not be retried when its deadline fence refuses the result.');
});

test('AI known unsent cleanup failure remains sanitized and does not transmit', function (): void {
    $admission = new AiLifecycleAdmissionFixture;
    $admission->cancelAction = fn () => throw new RuntimeException('private-usage-store-error');
    $transport = new AiHttpTransport(aiLifecyclePolicy($admission, fn () => throw new AiNetworkException('dns_unavailable')), $admission, fn () => throw new LogicException('must not send'));

    expect(aiLifecycleReason(fn () => $transport->send(aiLifecycleConnection(), 'synthetic_probe', 'attempt', 'GET', 'https://models.example.test/v1/models')))
        ->toBe('usage_reconciliation_unavailable')
        ->and(array_column($admission->events, 0))->toBe(['claim', 'dns', 'cancel']);
});
