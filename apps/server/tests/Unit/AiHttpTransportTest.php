<?php

declare(strict_types=1);

use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\AiOutboundAdmission;
use App\Support\Ai\Network\BoundedAiCurlFactory;
use App\Support\Ai\Network\UnavailableAiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;

function aiTransportConnection(int $account = 12, string $host = 'models.example.test', string $key = 'private-key-a'): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection(
        $account, 'account', str_repeat('a', 64), 'openai-compatible', 'support-model',
        'https://'.$host.'/v1', '', $key,
    );
}

function aiTransportPolicy(?Closure $resolve = null): AiNetworkPolicy
{
    $dns = new class($resolve) implements AiDnsResolver
    {
        public function __construct(private ?Closure $resolve) {}

        public function resolve(string $host, float $deadline): array
        {
            return $this->resolve === null ? ['8.8.8.8'] : ($this->resolve)($host, $deadline);
        }
    };

    return new AiNetworkPolicy($dns, ['mode' => 'public-only', 'private_grants' => []], 'transport-test-revision-key');
}

function aiTransportAdmission(?Closure $allow = null): AiOutboundAdmission
{
    return new class($allow) implements AiOutboundAdmission
    {
        public function __construct(private ?Closure $allow) {}

        public function assertAllowed(ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId): string
        {
            if ($this->allow !== null) {
                ($this->allow)($connection, $purpose, $attemptId);
            }

            return 'synthetic-claim';
        }

        public function beginDispatch(ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId, string $claimNonce): string
        {
            return 'synthetic-dispatch';
        }

        public function cancelUnsent(string $attemptId, string $claimNonce, string $reason = 'known_unsent'): void {}

        public function markUnknown(string $attemptId, string $dispatchNonce, string $reason = 'usage_unavailable'): void {}
    };
}

function aiTransportFailure(Closure $send, string $reason): AiNetworkException
{
    try {
        $send();
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->toBe('The AI connection request is unavailable.');

        return $exception;
    }

    test()->fail('Expected a sanitized AI network failure: '.$reason);
}

/** Exercise the production cURL path against one disposable loopback response. */
function aiTransportLoopback(string $wireResponse, Closure $assertResponse): void
{
    $script = <<<'PHP'
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) { exit(1); }
    echo stream_socket_get_name($server, false)."\n";
    flush();
    $client = stream_socket_accept($server, 3);
    if ($client === false) { exit(2); }
    stream_set_timeout($client, 3);
    while (($line = fgets($client)) !== false && trim($line) !== '') {}
    $response = base64_decode($argv[1], true);
    while ($response !== '') {
        $written = @fwrite($client, substr($response, 0, 1024));
        if ($written === false || $written === 0) { break; }
        $response = substr($response, $written);
    }
    fclose($client);
    fclose($server);
    PHP;
    $server = new Process([PHP_BINARY, '-n', '-r', $script, base64_encode($wireResponse)], timeout: 5);
    try {
        $server->start();
        $readyBy = hrtime(true) / 1e9 + 2;
        while (! str_contains($server->getOutput(), "\n") && $server->isRunning() && hrtime(true) / 1e9 < $readyBy) {
            usleep(1_000);
        }
        $address = trim($server->getOutput());
        expect($address)->toMatch('/\A127\.0\.0\.1:[0-9]+\z/', 'The isolated HTTP fixture must bind loopback before a request starts.');
        $origin = 'http://'.$address;
        $connection = new ResolvedAgentCopilotConnection(12, 'account', str_repeat('a', 64), 'openai-compatible', 'synthetic-model', $origin.'/v1', '', 'synthetic-key');
        $dns = new class implements AiDnsResolver
        {
            public function resolve(string $host, float $deadline): array
            {
                throw new LogicException('The loopback literal must never use DNS.');
            }
        };
        $policy = new AiNetworkPolicy($dns, ['mode' => 'operator-private', 'private_grants' => [[
            'account_id' => 12, 'source' => 'account', 'origin' => $origin, 'ips' => ['127.0.0.1'],
        ]]], 'synthetic-test-revision');
        $assertResponse(new AiHttpTransport($policy, aiTransportAdmission()), $connection, $origin.'/v1/models');
        expect($server->wait())->toBe(0, 'The local HTTP fixture must terminate cleanly.');
    } finally {
        if ($server->isRunning()) {
            $server->stop(0);
        }
    }
}

test('AI transport default admission denies before DNS and terminal dispatch', function (): void {
    $dns = 0;
    $calls = 0;
    $transport = new AiHttpTransport(
        aiTransportPolicy(function () use (&$dns): array {
            $dns++;

            return ['8.8.8.8'];
        }),
        new UnavailableAiOutboundAdmission,
        function () use (&$calls) {
            $calls++;

            return Create::promiseFor(new Response(200));
        },
    );

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions'), 'usage_admission_unavailable');
    expect([$dns, $calls])->toBe([0, 0]);
});

test('AI transport pins an admitted request and owns every transport option', function (): void {
    // Policy also checks the real monotonic deadline. Start on an integer
    // tick so injected elapsed time is exact without leaving that time domain.
    $clock = ceil(hrtime(true) / 1e9);
    $events = [];
    $request = null;
    $options = null;
    $transport = new AiHttpTransport(
        aiTransportPolicy(function (string $host, float $deadline) use (&$clock, &$events): array {
            $events[] = 'dns';
            expect($host)->toBe('models.example.test')->and($deadline)->toBeGreaterThan($clock);
            $clock += 3;

            return ['8.8.8.8'];
        }),
        aiTransportAdmission(function (ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId) use (&$clock, &$events): void {
            $events[] = 'admission';
            expect([$connection->accountId, $purpose, $attemptId])->toBe([12, 'probe', 'attempt-1']);
            $clock += 4;
        }),
        function (RequestInterface $wireRequest, array $wireOptions) use (&$request, &$options, &$events) {
            $events[] = 'send';
            $request = $wireRequest;
            $options = $wireOptions;
            ($wireOptions['on_headers'])(new Response(200, ['Content-Length' => '2']));
            $wireOptions['sink']->write('{}');

            return Create::promiseFor(new Response(200, [], $wireOptions['sink']));
        },
        function () use (&$clock): float {
            return $clock;
        },
    );

    $result = $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions', [
        'Authorization' => 'Bearer private-key-a', 'Content-Type' => 'application/json', 'Accept-Encoding' => 'gzip',
    ], '{}');
    $response = $result->response;

    expect($events)->toBe(['admission', 'dns', 'send'])
        ->and((string) $response->getBody())->toBe('{}')
        ->and($request->getHeaderLine('Host'))->toBe('models.example.test')
        ->and($request->getHeaderLine('Accept-Encoding'))->toBe('identity')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer private-key-a')
        ->and($options['timeout'])->toBe(13.0)
        ->and($options['connect_timeout'])->toBe(5.0)
        ->and($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['verify'])->toBeTrue()
        ->and($options['stream'])->toBeFalse()
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['models.example.test:443:8.8.8.8'])
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS)
        ->and($options['curl'][CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($options['curl'][CURLOPT_NOPROXY])->toBe('*')
        ->and($options['curl'][CURLOPT_PROXY])->toBe('')
        ->and($options['curl'][CURLOPT_SSL_VERIFYPEER])->toBeTrue()
        ->and($options['curl'][CURLOPT_SSL_VERIFYHOST])->toBe(2)
        ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
        ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue();
});

test('AI transport accepts informational headers before a final successful response', function (int $status): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request, array $options) use ($status) {
        ($options['on_headers'])(new Response($status));
        ($options['on_headers'])(new Response(200, ['Content-Length' => '2']));
        $options['sink']->write('{}');

        return Create::promiseFor(new Response(200, [], $options['sink']));
    });

    try {
        $result = $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models');
        $response = $result->response;
        $reason = null;
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
    }

    expect($reason)->toBeNull('Standard informational headers must not reject the following final HTTP 200 response.')
        ->and($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('{}');
})->with([100, 102, 103]);

test('AI informational header handling retains the total deadline', function (): void {
    $clock = hrtime(true) / 1e9;
    $accepted = false;
    $transport = new AiHttpTransport(
        aiTransportPolicy(), aiTransportAdmission(),
        function (RequestInterface $request, array $options) use (&$clock, &$accepted) {
            $clock += 21;
            ($options['on_headers'])(new Response(103));
            $accepted = true;

            return Create::promiseFor(new Response(200, [], '{}'));
        },
        function () use (&$clock): float {
            return $clock;
        },
    );

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'deadline_exceeded');
    expect($accepted)->toBeFalse('Expired interim headers must be refused before accepting another response block.');
});

test('AI transport never treats a final informational response as success', function (int $status): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), fn () => Create::promiseFor(new Response($status)));

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'http_error');
})->with([100, 101, 102, 103]);

test('AI transport refuses protocol switching at the header boundary', function (): void {
    $accepted = false;
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request, array $options) use (&$accepted) {
        ($options['on_headers'])(new Response(101, ['Upgrade' => 'websocket']));
        $accepted = true;

        return Create::promiseFor(new Response(200));
    });

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'http_error');
    expect($accepted)->toBeFalse('A protocol upgrade must be refused before any upgraded body is accepted.');
});

test('production AI cURL accepts bounded interim blocks and a final response', function (): void {
    $wire = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 102 Processing\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </synthetic>; rel=preload\r\n\r\n"
        ."HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}";
    aiTransportLoopback($wire, function (AiHttpTransport $transport, ResolvedAgentCopilotConnection $connection, string $url): void {
        $result = $transport->send($connection, 'probe', 'local-attempt', 'GET', $url);
        $response = $result->response;
        expect($response->getStatusCode())->toBe(200)->and((string) $response->getBody())->toBe('{}');
    });
});

test('production AI cURL caps cumulative header bytes and lines before the body', function (string $wire): void {
    aiTransportLoopback($wire, function (AiHttpTransport $transport, ResolvedAgentCopilotConnection $connection, string $url): void {
        aiTransportFailure(fn () => $transport->send($connection, 'probe', 'local-attempt', 'GET', $url), 'response_headers_too_large');
    });
})->with([
    'header byte ceiling' => ["HTTP/1.1 200 OK\r\nX-Large: ".str_repeat('x', 32_768)."\r\nContent-Length: 2\r\n\r\n{}"],
    'header line ceiling' => ["HTTP/1.1 200 OK\r\n".str_repeat("X-Small: a\r\n", 129)."Content-Length: 2\r\n\r\n{}"],
    'interim blocks share the same ceiling' => [str_repeat("HTTP/1.1 103 Early Hints\r\n".str_repeat("X-Small: a\r\n", 12)."\r\n", 10)."HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}"],
    'trailers count even when discarded' => ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\n{}\r\n0\r\n".str_repeat("X-Trailer: a\r\n", 129)."\r\n"],
]);

test('production AI cURL accepts the exact cumulative header ceilings', function (string $headers): void {
    aiTransportLoopback($headers.'{}', function (AiHttpTransport $transport, ResolvedAgentCopilotConnection $connection, string $url): void {
        $result = $transport->send($connection, 'probe', 'local-attempt', 'GET', $url);
        $response = $result->response;
        expect($response->getStatusCode())->toBe(200)->and((string) $response->getBody())->toBe('{}');
    });
})->with([
    'exact byte ceiling' => [function (): string {
        $prefix = "HTTP/1.1 200 OK\r\nX-Pad: ";
        $suffix = "\r\nContent-Length: 2\r\nConnection: close\r\n\r\n";

        return $prefix.str_repeat('x', 32_768 - strlen($prefix) - strlen($suffix)).$suffix;
    }],
    'exact line ceiling' => ["HTTP/1.1 200 OK\r\n".str_repeat("X-Small: a\r\n", 124)."Content-Length: 2\r\nConnection: close\r\n\r\n"],
]);

test('AI header cap refuses an offending line before retaining it in Guzzle', function (): void {
    $factory = new BoundedAiCurlFactory(static fn () => null);
    $easy = new EasyHandle;
    $callback = (new ReflectionMethod($factory, 'headerFunction'))->invoke($factory, $easy);
    $status = "HTTP/1.1 200 OK\r\n";
    expect($callback(null, $status))->toBe(strlen($status));
    expect($callback(null, 'X-Provider: '.str_repeat('private-header-marker', 2_000)."\r\n"))->toBe(0)
        ->and($easy->headers)->toBe(['HTTP/1.1 200 OK'], 'The excessive provider line must not enter Guzzle header storage.')
        ->and($easy->onHeadersException->reason)->toBe('response_headers_too_large');
});

test('AI header callback enforces the deadline on every line and redacts its trace', function (): void {
    $calls = 0;
    $factory = new BoundedAiCurlFactory(function () use (&$calls): void {
        if (++$calls === 2) {
            throw new AiNetworkException('deadline_exceeded');
        }
    });
    $easy = new EasyHandle;
    $callback = (new ReflectionMethod($factory, 'headerFunction'))->invoke($factory, $easy);
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $callback(null, "HTTP/1.1 200 OK\r\n");
        expect($callback(null, "X-Provider: private-header-trace-marker\r\n"))->toBe(0)
            ->and($easy->headers)->toBe(['HTTP/1.1 200 OK'])
            ->and($easy->onHeadersException->reason)->toBe('deadline_exceeded')
            ->and((string) $easy->onHeadersException)->not->toContain('private-header-trace-marker');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('AI cURL factory is single use even when Guzzle tries an internal retry', function (): void {
    $factory = new BoundedAiCurlFactory(static fn () => null);
    $easy = $factory->create(new Request('GET', 'http://127.0.0.1:1/v1/models'), []);
    $retries = 0;
    $dispatches = 0;
    aiTransportFailure(function () use ($factory, $easy, &$retries, &$dispatches): void {
        CurlFactory::finish(function (RequestInterface $request, array $options) use ($factory, &$retries, &$dispatches) {
            $retries++;
            $retry = $factory->create($request, $options);
            $dispatches++;
            $factory->release($retry);

            return Create::promiseFor(new Response(200));
        }, $easy, $factory)->wait();
    }, 'transport_failed');
    expect($retries)->toBe(1, 'The fixture must exercise Guzzle’s actual no-response retry path.')
        ->and($dispatches)->toBe(0, 'The second create must fail before another transfer can start.');
});

test('AI cURL factory errors redact credential-bearing requests and options', function (): void {
    $factory = new BoundedAiCurlFactory(static fn () => null);
    $request = new Request('GET', 'https://private-factory-topology.test/v1/models', ['Authorization' => 'Bearer private-factory-key']);
    $first = $factory->create($request, []);
    $factory->release($first);
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $error = aiTransportFailure(fn () => $factory->create($request, ['synthetic-marker' => 'private-factory-options']), 'transport_failed');
        expect((string) $error)->not->toContain('private-factory-topology', 'private-factory-key', 'private-factory-options')
            ->and($error->getTrace()[0]['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
            ->and($error->getTrace()[0]['args'][1])->toBeInstanceOf(SensitiveParameterValue::class);
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('AI transport rejects foreign Host before forwarding a key', function (array $headers): void {
    $calls = 0;
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200));
    });

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions', $headers), 'origin_mismatch');
    expect($calls)->toBe(0);
})->with([
    'foreign host' => [['Host' => 'other.example.test', 'Authorization' => 'Bearer private-key-a']],
    'duplicate hosts' => [['Host' => ['models.example.test', 'other.example.test']]],
]);

test('AI transport never sends to a foreign public origin', function (): void {
    $calls = 0;
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200));
    });

    expect(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://other.example.test/v1/chat/completions', ['Authorization' => 'Bearer private-key-a']))
        ->toThrow(AiNetworkException::class);
    expect($calls)->toBe(0);
});

test('AI transport only permits bounded GET and POST request bodies', function (string $method, string $body): void {
    $dns = 0;
    $transport = new AiHttpTransport(
        aiTransportPolicy(function () use (&$dns): array {
            $dns++;

            return ['8.8.8.8'];
        }),
        aiTransportAdmission(),
        fn () => throw new LogicException('No dispatch expected.'),
    );

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', $method, 'https://models.example.test/v1/models', [], $body), 'invalid_request');
    expect($dns)->toBe(0);
})->with([
    'delete' => ['DELETE', ''],
    'get with a body' => ['GET', 'customer text'],
    'over byte cap' => ['POST', str_repeat('é', 32_769)],
]);

test('AI transport rejects request smuggling and proxy headers', function (array $headers): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), fn () => throw new LogicException('No dispatch expected.'));

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions', $headers, '{}'), 'invalid_request');
})->with([
    'length mismatch' => [['Content-Length' => '1']],
    'transfer encoding' => [['Transfer-Encoding' => 'chunked']],
    'proxy credential' => [['Proxy-Authorization' => 'Bearer private-key-a']],
    'upgrade' => [['Connection' => 'upgrade']],
    'compressed request' => [['Content-Encoding' => 'gzip']],
]);

test('AI transport rejects redirects and non-success responses after exactly one request', function (int $status, string $reason): void {
    $calls = 0;
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function () use (&$calls, $status) {
        $calls++;

        return Create::promiseFor(new Response($status, ['Location' => 'http://127.0.0.1/private'], 'private upstream response'));
    });

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), $reason);
    expect($calls)->toBe(1);
})->with([[301, 'redirect_denied'], [302, 'redirect_denied'], [307, 'redirect_denied'], [308, 'redirect_denied'], [401, 'key_rejected'], [403, 'key_rejected'], [404, 'model_unavailable'], [429, 'provider_rate_limited'], [500, 'http_error']]);

test('AI transport checks encoded and oversized headers even when a fake skips callbacks', function (array $headers, string $reason): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), fn () => Create::promiseFor(new Response(200, $headers, '{}')));

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), $reason);
})->with([
    [['Content-Encoding' => 'gzip'], 'response_encoding'],
    [['Content-Encoding' => 'br'], 'response_encoding'],
    [['Content-Length' => '262145'], 'response_too_large'],
    [['Content-Length' => ['2', '262145']], 'response_too_large'],
]);

test('AI transport bounds chunked writes before the oversized chunk enters its sink', function (): void {
    $sink = null;
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request, array $options) use (&$sink) {
        $sink = $options['sink'];
        ($options['on_headers'])(new Response(200, ['Transfer-Encoding' => 'chunked']));
        $sink->write(str_repeat('x', 262_144));
        $sink->write('x');

        return Create::promiseFor(new Response(200, [], $sink));
    });

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'response_too_large');
    expect($sink->getSize())->toBe(262_144);
});

test('AI transport caps bodies returned directly by a fake handler', function (array $headers): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), fn () => Create::promiseFor(new Response(200, $headers, str_repeat('x', 262_145))));

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'response_too_large');
})->with(['chunked' => [['Transfer-Encoding' => 'chunked']], 'lying length' => [['Content-Length' => '1']], 'no length' => [[]]]);

test('AI transport accepts the exact request and response byte ceilings', function (): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request) {
        expect($request->getBody()->getSize())->toBe(65_536);

        return Create::promiseFor(new Response(200, ['Content-Encoding' => 'identity', 'Content-Length' => '262144'], str_repeat('x', 262_144)));
    });

    $result = $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions', [], str_repeat('x', 65_536));
    $response = $result->response;
    expect($response->getBody()->getSize())->toBe(262_144);
});

test('AI transport includes admission DNS and handler time in one total deadline', function (string $stage): void {
    $clock = hrtime(true) / 1e9;
    $dns = 0;
    $calls = 0;
    $transport = new AiHttpTransport(
        aiTransportPolicy(function () use (&$clock, &$dns, $stage): array {
            $dns++;
            if ($stage === 'dns') {
                $clock += 21;
            }

            return ['8.8.8.8'];
        }),
        aiTransportAdmission(function () use (&$clock, $stage): void {
            if ($stage === 'admission') {
                $clock += 21;
            }
        }),
        function (RequestInterface $request, array $options) use (&$clock, &$calls, $stage) {
            $calls++;
            $clock += 21;
            if ($stage === 'write') {
                $options['sink']->write('late');
            }

            return Create::promiseFor(new Response(200, [], '{}'));
        },
        function () use (&$clock): float {
            return $clock;
        },
    );

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'deadline_exceeded');
    expect($dns)->toBe($stage === 'admission' ? 0 : 1)
        ->and($calls)->toBe(in_array($stage, ['handler', 'write'], true) ? 1 : 0);
})->with(['admission', 'dns', 'handler', 'write']);

test('AI transport deadline also covers consuming a handler returned body', function (): void {
    $clock = hrtime(true) / 1e9;
    $stream = Utils::streamFor('late body');
    $body = FnStream::decorate($stream, ['read' => function (int $length) use (&$clock, $stream): string {
        $clock += 21;

        return $stream->read($length);
    }]);
    $transport = new AiHttpTransport(
        aiTransportPolicy(), aiTransportAdmission(), fn () => Create::promiseFor(new Response(200, [], $body)),
        function () use (&$clock): float {
            return $clock;
        },
    );

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'deadline_exceeded');
});

test('AI transport sanitizes wrapped handler failures without retaining request body or credentials', function (): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request) {
        throw new RequestException('private-key-a customer transcript private endpoint', $request, new Response(500, [], 'customer transcript'));
    });

    $old = ini_set('zend.exception_ignore_args', '0');
    try {
        $exception = aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'POST', 'https://models.example.test/v1/chat/completions', ['Authorization' => 'Bearer private-key-a'], 'customer transcript'), 'transport_failed');
        expect((string) $exception)->not->toContain('private-key-a')->not->toContain('customer transcript')->not->toContain('models.example.test');
    } finally {
        ini_set('zend.exception_ignore_args', $old);
    }
});

test('AI transport preserves its safe reason when Guzzle wraps a header rejection', function (): void {
    $transport = new AiHttpTransport(aiTransportPolicy(), aiTransportAdmission(), function (RequestInterface $request, array $options) {
        try {
            ($options['on_headers'])(new Response(200, ['Content-Encoding' => 'gzip']));
        } catch (AiNetworkException $exception) {
            throw new RequestException('Raw provider response with private-key-a', $request, null, $exception);
        }

        return Create::promiseFor(new Response(200));
    });

    aiTransportFailure(fn () => $transport->send(aiTransportConnection(), 'probe', 'attempt-1', 'GET', 'https://models.example.test/v1/models'), 'response_encoding');
});

test('AI transport rereads pins and isolates credentials across reused transport calls', function (): void {
    $ips = ['8.8.8.8', '1.1.1.1', '9.9.9.9'];
    $sent = [];
    $admitted = [];
    $transport = new AiHttpTransport(
        aiTransportPolicy(function () use (&$ips): array {
            return [array_shift($ips)];
        }),
        aiTransportAdmission(function (ResolvedAgentCopilotConnection $connection, string $purpose, string $attemptId) use (&$admitted): void {
            $admitted[] = [$connection->accountId, $purpose, $attemptId];
        }),
        function (RequestInterface $request, array $options) use (&$sent) {
            $sent[] = [$request->getUri()->getHost(), $request->getHeaderLine('Authorization'), $options['curl'][CURLOPT_RESOLVE]];

            return Create::promiseFor(new Response(200, [], '{}'));
        },
    );
    $a = aiTransportConnection();
    $b = aiTransportConnection(24, 'other.example.test', 'private-key-b');
    foreach ([$a, $b, $a] as $index => $connection) {
        $transport->send($connection, 'probe', 'attempt-'.$index, 'POST', $connection->endpoint.'/chat/completions', ['Authorization' => 'Bearer '.$connection->apiKey()], '{}');
    }

    expect($admitted)->toBe([[12, 'probe', 'attempt-0'], [24, 'probe', 'attempt-1'], [12, 'probe', 'attempt-2']])
        ->and($sent)->toBe([
            ['models.example.test', 'Bearer private-key-a', ['models.example.test:443:8.8.8.8']],
            ['other.example.test', 'Bearer private-key-b', ['other.example.test:443:1.1.1.1']],
            ['models.example.test', 'Bearer private-key-a', ['models.example.test:443:9.9.9.9']],
        ]);
});
