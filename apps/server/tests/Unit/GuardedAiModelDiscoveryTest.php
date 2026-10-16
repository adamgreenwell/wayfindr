<?php

declare(strict_types=1);

use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Execution\AiProviderRequestRunner;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\GuardedAiModelDiscovery;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;

final class DiscoverySessionFixture implements AiGuardedSession
{
    public array $events = [];

    public ?Closure $duringRequest = null;

    public ?Closure $duringRecord = null;

    public ?Closure $duringSettle = null;

    public function __construct(private ResolvedAgentCopilotConnection $resolved, private string $body) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->resolved;
    }

    public function maxOutputTokens(): int
    {
        return 10;
    }

    public function dispatch(string $url, array $headers, string $json): AiHttpResult
    {
        throw new LogicException('Discovery cannot dispatch a generation request.');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        $this->events[] = ['request', $method, $url, $headers, $body];
        if ($this->duringRequest !== null) {
            ($this->duringRequest)();
        }

        return new AiHttpResult(new Response(200, [], $this->body), 'synthetic-attempt', 'synthetic-dispatch');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        $this->events[] = ['record', $inputTokens, $outputTokens, $receipt->attemptId];
        if ($this->duringRecord !== null) {
            ($this->duringRecord)();
        }
    }

    public function settle(string $reason = 'completed'): void
    {
        $this->events[] = ['settle', $reason];
        if ($this->duringSettle !== null) {
            ($this->duringSettle)();
        }
    }

    public function usage(): array
    {
        return ['input' => 0, 'output' => 0];
    }
}

function discoveryFixture(string $provider, string $body, string $endpoint = '', string $key = 'synthetic-private-key'): array
{
    $connection = new ResolvedAgentCopilotConnection(7, 'account', str_repeat('a', 64), $provider, '', $endpoint, 'exact-upstream', $key);
    $context = AiExecutionContext::discovery(7, 11, '11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222');
    $session = new DiscoverySessionFixture($connection, $body);
    $runner = new class($session)
    {
        public array $calls = [];

        public function __construct(private AiGuardedSession $session) {}

        public function session(ResolvedAgentCopilotConnection $connection, AiExecutionContext $context): AiGuardedSession
        {
            $this->calls[] = [$connection, $context];

            return $this->session;
        }
    };
    $container = new Container;
    $container->instance(AiProviderRequestRunner::class, $runner);

    return [new GuardedAiModelDiscovery($container), $connection, $context, $session, $runner];
}

function discoveryFailure(Closure $operation, string $reason): void
{
    try {
        $operation();
        test()->fail('Expected sanitized discovery refusal: '.$reason);
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('private-provider-marker', 'synthetic-private-key', 'private-host');
    }
}

test('discovery uses one fixed provider route exact IDs and explicit credential headers', function (string $provider, array $catalogue, array $expected, string $url, array $headers, string $endpoint): void {
    [$discovery, $connection, $context, $session, $runner] = discoveryFixture($provider, json_encode($catalogue, JSON_THROW_ON_ERROR), $endpoint);

    expect($discovery->discover($connection, $context))->toBe($expected)
        ->and($runner->calls)->toBe([[$connection, $context]])
        ->and($session->events)->toBe([
            ['request', 'GET', $url, $headers, ''],
            ['record', 0, 0, 'synthetic-attempt'],
            ['settle', 'completed'],
        ], 'Discovery must consume one guarded metadata request, then settle known zero generation tokens.');
})->with([
    'anthropic' => ['anthropic', ['data' => [['id' => 'claude-exact-20260101']], 'has_more' => true, 'last_id' => 'unfollowed-cursor'], ['claude-exact-20260101'], 'https://api.anthropic.com/v1/models', ['x-api-key' => 'synthetic-private-key', 'anthropic-version' => '2023-06-01'], ''],
    'gemini' => ['gemini', ['models' => [['name' => 'models/gemini-exact-001', 'baseModelId' => 'gemini-alias']], 'nextPageToken' => 'unfollowed-cursor'], ['gemini-exact-001'], 'https://generativelanguage.googleapis.com/v1beta/models', ['x-goog-api-key' => 'synthetic-private-key'], ''],
    'openai' => ['openai', ['data' => [['id' => 'Exact-Model-2026']]], ['Exact-Model-2026'], 'https://api.openai.com/v1/models', ['Authorization' => 'Bearer synthetic-private-key'], ''],
    'openrouter' => ['openrouter', ['data' => [['id' => 'vendor/exact-model:variant']], 'links' => ['next' => 'https://private-host/cursor']], ['vendor/exact-model:variant'], 'https://openrouter.ai/api/v1/models', ['Authorization' => 'Bearer synthetic-private-key'], ''],
    'compatible' => ['openai-compatible', ['data' => [['id' => 'namespace/custom-model']]], ['namespace/custom-model'], 'https://models.example.test/custom/models', ['Authorization' => 'Bearer synthetic-private-key'], 'https://models.example.test/custom'],
    'ollama' => ['ollama', ['models' => [['name' => 'local/exact:tag', 'model' => 'different-alias']]], ['local/exact:tag'], 'http://localhost:11434/api/tags', ['Authorization' => 'Bearer synthetic-private-key'], ''],
]);

test('discovery permits an explicitly keyless compatible or local route without fabricating authorization', function (string $provider, string $endpoint): void {
    $body = $provider === 'ollama' ? '{"models":[]}' : '{"data":[]}';
    [$discovery, $connection, $context, $session] = discoveryFixture($provider, $body, $endpoint, '');
    expect($discovery->discover($connection, $context))->toBe([])
        ->and($session->events[0][3])->toBe([]);
})->with([['ollama', ''], ['openai-compatible', 'https://models.example.test/v1']]);

test('discovery keeps only one hundred unique IDs and never follows pagination metadata', function (): void {
    $rows = [['id' => 'exact-0'], ['id' => 'exact-0']];
    for ($index = 1; $index < 150; $index++) {
        $rows[] = ['id' => 'exact-'.$index, 'description' => 'private-provider-marker'];
    }
    [$discovery, $connection, $context, $session] = discoveryFixture('openai', json_encode(['data' => $rows, 'next' => 'https://private-host/cursor'], JSON_THROW_ON_ERROR));
    $models = $discovery->discover($connection, $context);
    expect($models)->toHaveCount(100, 'One discovery result may expose at most one hundred unique IDs.')
        ->toBe(array_map(fn (int $index): string => 'exact-'.$index, range(0, 99)))
        ->and(count(array_filter($session->events, fn (array $event): bool => $event[0] === 'request')))->toBe(1);
});

test('missing wrong-purpose and foreign discovery contexts cannot resolve or call a runner', function (): void {
    [$discovery, $connection, $context, $session, $runner] = discoveryFixture('openai', '{"data":[]}');
    expect($discovery->discover($connection))->toBeNull();
    discoveryFailure(fn () => $discovery->discover($connection, AiExecutionContext::probe(7, 11, $context->proposedRevision, $context->attemptId)), 'discovery_context_required');
    discoveryFailure(fn () => $discovery->discover($connection, AiExecutionContext::discovery(8, 11, $context->proposedRevision, $context->attemptId)), 'discovery_context_required');
    expect($runner->calls)->toBe([])->and($session->events)->toBe([]);
});

test('unsupported discovery never invents a provider route', function (): void {
    [$discovery, $connection, $context, $session, $runner] = discoveryFixture('unsupported', '{"data":[]}');
    discoveryFailure(fn () => $discovery->discover($connection, $context), 'unsupported_provider');
    expect($runner->calls)->toBe([])->and($session->events)->toBe([]);
});

test('invalid discovery catalogues fail closed without retaining provider text', function (string $provider, string $body): void {
    [$discovery, $connection, $context, $session] = discoveryFixture($provider, $body);
    discoveryFailure(fn () => $discovery->discover($connection, $context), 'discovery_invalid_response');
    expect(end($session->events))->toBe(['settle', 'invalid_output']);
})->with([
    ['openai', 'private-provider-marker'],
    ['openai', 'null'],
    ['openai', '{"data":{}}'],
    ['openai', '{"data":[null]}'],
    ['openai', '{"data":[{"id":false}]}'],
    ['openai', '{"data":[{"id":""}]}'],
    ['openai', '{"data":[{"id":" padded "}]}'],
    ['openai', '{"data":[{"id":"line\\nbreak"}]}'],
    ['openai', json_encode(['data' => [['id' => str_repeat('x', 256)]]], JSON_THROW_ON_ERROR)],
    ['gemini', '{"models":[{"name":"gemini-without-resource-prefix"}]}'],
    ['gemini', '{"models":[{"name":"models/"}]}'],
    ['ollama', '{"models":[{"model":"alias-without-canonical-name"}]}'],
]);

test('oversized discovery responses are rejected despite apparently valid IDs', function (): void {
    $body = json_encode(['data' => [['id' => 'exact-model', 'description' => str_repeat('x', 262_144)]]], JSON_THROW_ON_ERROR);
    [$discovery, $connection, $context, $session] = discoveryFixture('openai', $body);
    discoveryFailure(fn () => $discovery->discover($connection, $context), 'discovery_response_too_large');
    expect(end($session->events))->toBe(['settle', 'invalid_output']);
});

test('discovery does not retry an uncertain request or response receipt write', function (string $stage, string $reason): void {
    [$discovery, $connection, $context, $session] = discoveryFixture('openai', '{"data":[]}');
    if ($stage === 'request') {
        $session->duringRequest = fn () => throw new AiNetworkException($reason);
    } else {
        $session->duringRecord = fn () => throw new AiNetworkException($reason);
    }
    discoveryFailure(fn () => $discovery->discover($connection, $context), $reason);
    expect(array_column($session->events, 0))->toBe($stage === 'request' ? ['request'] : ['request', 'record']);
})->with([['request', 'dns_unavailable'], ['record', 'usage_reconciliation_unavailable']]);

test('failed discovery settlement withholds IDs without retrying the ambiguous write', function (): void {
    [$discovery, $connection, $context, $session] = discoveryFixture('openai', '{"data":[{"id":"exact-model"}]}');
    $session->duringSettle = fn () => throw new RuntimeException('private-provider-marker');
    discoveryFailure(fn () => $discovery->discover($connection, $context), 'usage_reconciliation_unavailable');
    expect(array_column($session->events, 0))->toBe(['request', 'record', 'settle']);
});
