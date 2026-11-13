<?php

declare(strict_types=1);

use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\Gateway\GuardedAiGatewayFactory;
use App\Support\Ai\Gateway\GuardedAiPendingRequest;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use GuzzleHttp\Psr7\Response;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\OpenRouterProvider;

final class GuardedGatewaySessionFixture implements AiGuardedSession
{
    public array $calls = [];

    public array $observed = [];

    public array $settlements = [];

    public ?array $response = null;

    public function __construct(private ResolvedAgentCopilotConnection $resolved) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->resolved;
    }

    public function maxOutputTokens(): int
    {
        return 40;
    }

    public function dispatch(string $url, array $headers, string $json): AiHttpResult
    {
        $this->calls[] = compact('url', 'headers', 'json');

        return new AiHttpResult(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->response ?? guardedGatewayResponse($this->resolved->provider), JSON_THROW_ON_ERROR)),
            '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        throw new LogicException('SDK gateways must use dispatch.');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void
    {
        $this->observed[] = [$receipt, $inputTokens, $outputTokens];
    }

    public function settle(string $reason = 'completed'): void
    {
        $this->settlements[] = $reason;
    }

    public function usage(): array
    {
        return ['input' => null, 'output' => null];
    }
}

function guardedGatewayConnection(string $driver = 'openai', string $key = 'private-key', int $accountId = 1): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection($accountId, 'account', str_repeat('a', 64), $driver, 'synthetic-model',
        'https://models.example.test/v1', 'synthetic-upstream', $key);
}

function guardedGatewayRouter(): array
{
    return ['provider' => ['order' => ['synthetic-upstream'], 'allow_fallbacks' => false, 'zdr' => true, 'require_parameters' => true]];
}

function guardedGatewayResponse(string $driver): array
{
    $common = ['model' => 'synthetic-model'];

    return $common + match ($driver) {
        'openai' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Synthetic acknowledgement.']]]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 7]],
        'gemini' => ['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'Synthetic acknowledgement.']]]], 'usage' => ['total_input_tokens' => 5, 'total_output_tokens' => 6, 'total_thought_tokens' => 1]],
        'anthropic' => ['type' => 'message', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Synthetic acknowledgement.']], 'usage' => ['input_tokens' => 3, 'cache_read_input_tokens' => 1, 'cache_creation_input_tokens' => 1, 'output_tokens' => 7]],
        'ollama' => ['message' => ['role' => 'assistant', 'content' => 'Synthetic acknowledgement.'], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 7],
        default => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Synthetic acknowledgement.'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 7]],
    };
}

function guardedGatewayWorld(string $driver, array $configOverrides = []): array
{
    $session = new GuardedGatewaySessionFixture(guardedGatewayConnection($driver));
    $events = new Dispatcher;
    $factory = new GuardedAiGatewayFactory($events);
    $gateway = $factory->make($session);
    $config = [...['name' => 'wayfindr', 'driver' => $driver, 'key' => 'private-key', 'url' => 'https://models.example.test/v1', 'store' => false], ...$configOverrides];
    $provider = match ($driver) {
        'openai' => new OpenAiProvider($gateway, $config, $events),
        'gemini' => new GeminiProvider($gateway, $config, $events),
        'anthropic' => new AnthropicProvider($gateway, $config, $events),
        'ollama' => new OllamaProvider($config, $events),
        'openai-compatible' => new OpenAiCompatibleProvider($config, $events),
        'openrouter' => new OpenRouterProvider($config, $events),
    };
    $provider->useTextGateway($gateway);

    return compact('session', 'gateway', 'provider', 'factory');
}

function guardedGatewayInvoke(array $world, ?TextGenerationOptions $options = null, bool $stream = false, string $model = 'synthetic-model')
{
    $options ??= new TextGenerationOptions(maxTokens: 40, providerOptions: $world['session']->connection()->provider === 'openrouter' ? guardedGatewayRouter() : []);
    $arguments = [$world['provider'], $model, 'Private instructions.', [new UserMessage('Private support text.')], [], null, $options, 20, new StepContext];

    return $stream
        ? iterator_to_array($world['gateway']->generateStreamStep('invocation', ...$arguments))
        : $world['gateway']->generateTextStep(...$arguments);
}

function guardedGatewayRefusal(Closure $operation, string $reason = 'invalid_request'): void
{
    try {
        $operation();
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)->and($exception->getPrevious())->toBeNull();

        return;
    }
    test()->fail('Unsafe SDK requests must stop before dispatch.');
}

dataset('guarded sdk drivers', ['openai', 'gemini', 'anthropic', 'ollama', 'openai-compatible', 'openrouter']);

test('six native SDK builders dispatch only exact guarded text requests and capture raw usage', function (string $driver): void {
    $world = guardedGatewayWorld($driver);
    $response = guardedGatewayInvoke($world);
    $session = $world['session'];
    expect($response->text)->toBe('Synthetic acknowledgement.')
        ->and($session->calls)->toHaveCount(1)->and($session->observed)->toHaveCount(1)
        ->and(array_slice($session->observed[0], 1))->toBe([5, 7])->and($session->settlements)->toBe([])
        ->and($world['factory']->make($session))->not->toBe($world['gateway']);
    $body = json_decode($session->calls[0]['json'], true, flags: JSON_THROW_ON_ERROR);
    $route = match ($driver) {
        'openai' => 'responses', 'gemini' => 'interactions', 'anthropic' => 'messages', 'ollama' => 'api/chat', default => 'chat/completions'
    };
    $cap = match ($driver) {
        'openai' => $body['max_output_tokens'], 'gemini' => $body['generation_config']['max_output_tokens'], 'ollama' => $body['options']['num_predict'], default => $body['max_tokens']
    };
    expect($session->calls[0]['url'])->toBe('https://models.example.test/v1/'.$route)
        ->and($body['model'])->toBe('synthetic-model')->and($cap)->toBe(40)
        ->and(strlen($session->calls[0]['json']))->toBeLessThanOrEqual(65536)
        ->and($session->calls[0]['headers']['Content-Type'])->toBe('application/json');
    if (in_array($driver, ['openai', 'gemini'], true)) {
        expect($body['store'])->toBeFalse();
    }
    if ($driver === 'openrouter') {
        expect($body['provider'])->toBe(guardedGatewayRouter()['provider']);
    }
})->with('guarded sdk drivers');

test('native streaming requests never dispatch', function (string $driver): void {
    $world = guardedGatewayWorld($driver);
    guardedGatewayRefusal(fn () => guardedGatewayInvoke($world, stream: true));
    expect($world['session']->calls)->toBe([]);
})->with('guarded sdk drivers');

test('native SDK default or changed output caps never exceed the reserved cap', function (string $driver, ?int $cap): void {
    $world = guardedGatewayWorld($driver);
    $options = new TextGenerationOptions(maxTokens: $cap, providerOptions: $driver === 'openrouter' ? guardedGatewayRouter() : []);
    guardedGatewayRefusal(fn () => guardedGatewayInvoke($world, $options));
    expect($world['session']->calls)->toBe([]);
})->with('guarded sdk drivers')->with([null, 41]);

test('provider option tools cannot bypass the final JSON guard', function (string $driver): void {
    $world = guardedGatewayWorld($driver);
    $options = new TextGenerationOptions(maxTokens: 40, providerOptions: ['tools' => [['type' => 'web_search']], ...($driver === 'openrouter' ? guardedGatewayRouter() : [])]);
    guardedGatewayRefusal(fn () => guardedGatewayInvoke($world, $options));
    expect($world['session']->calls)->toBe([]);
})->with('guarded sdk drivers');

test('SDK foreign model or credential configuration stops before dispatch', function (string $driver, bool $foreignKey): void {
    $world = guardedGatewayWorld($driver, $foreignKey ? ['key' => 'foreign-account-key'] : []);
    guardedGatewayRefusal(fn () => guardedGatewayInvoke($world, model: $foreignKey ? 'synthetic-model' : 'foreign-model'));
    expect($world['session']->calls)->toBe([]);
})->with('guarded sdk drivers')->with([false, true]);

test('OpenRouter final routing policy requires one pinned zero retention upstream and parameter enforcement', function (string $field, mixed $value): void {
    $world = guardedGatewayWorld('openrouter');
    $policy = guardedGatewayRouter();
    $policy['provider'][$field] = $value;
    guardedGatewayRefusal(fn () => guardedGatewayInvoke($world, new TextGenerationOptions(maxTokens: 40, providerOptions: $policy)));
    expect($world['session']->calls)->toBe([]);
})->with([['order', ['other-upstream']], ['order', ['synthetic-upstream', 'fallback']], ['allow_fallbacks', true], ['zdr', false], ['require_parameters', false]]);

test('raw usage is captured before SDK validation and never completes a task', function (): void {
    $world = guardedGatewayWorld('openai');
    $world['session']->response = ['error' => ['type' => 'synthetic', 'message' => 'Private provider error.'], 'usage' => ['input_tokens' => 5, 'output_tokens' => 7]];
    expect(fn () => guardedGatewayInvoke($world))->toThrow(AiException::class);
    expect(array_slice($world['session']->observed[0], 1))->toBe([5, 7])->and($world['session']->settlements)->toBe([]);
});

test('guarded gateways do not use global Laravel HTTP fakes', function (): void {
    $original = Http::getFacadeRoot();
    $global = new Factory;
    $calls = 0;
    $global->fake(function () use (&$calls, $global) {
        $calls++;

        return $global->response(['unguarded' => true]);
    });
    Http::swap($global);
    try {
        $world = guardedGatewayWorld('openai');
        expect(guardedGatewayInvoke($world)->text)->toBe('Synthetic acknowledgement.')
            ->and($calls)->toBe(0)->and($world['session']->calls)->toHaveCount(1);
    } finally {
        if ($original !== null) {
            Http::swap($original);
        } else {
            Http::clearResolvedInstance('http');
        }
    }
});

test('guarded pending requests refuse foreign routes options oversized bodies and stateful references', function (string $case): void {
    $session = new GuardedGatewaySessionFixture(guardedGatewayConnection());
    $client = new GuardedAiPendingRequest($session, 'https://models.example.test/v1', ['Authorization' => 'Bearer private-key']);
    $body = ['model' => 'synthetic-model', 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Private input.']]]], 'store' => false, 'max_output_tokens' => 40];
    $method = $case === 'method' ? 'GET' : 'POST';
    $url = $case === 'url' ? 'https://foreign.example.test/responses' : 'responses';
    $options = ['json' => $body];
    match ($case) {
        'options' => $options['proxy'] = 'http://127.0.0.1:8080',
        'size' => $options['json']['input'][0]['content'][0]['text'] = str_repeat('x', 65536),
        'previous' => $options['json']['previous_response_id'] = 'private-response',
        'store' => $options['json']['store'] = true,
        'tool' => $options['json']['input'][0] = ['type' => 'function_call_output', 'call_id' => 'id', 'output' => 'private tool text'],
        'remote' => $options['json']['input'][0]['content'][0] = ['type' => 'input_file', 'file_url' => 'https://private.example.test/data'],
        default => null,
    };
    guardedGatewayRefusal(fn () => $client->send($method, $url, $options));
    expect($session->calls)->toBe([]);
})->with(['method', 'url', 'options', 'size', 'previous', 'store', 'tool', 'remote']);

test('one guarded pending request can never retry and its credentials cannot be serialized or dumped', function (): void {
    $session = new GuardedGatewaySessionFixture(guardedGatewayConnection());
    $client = new GuardedAiPendingRequest($session, 'https://models.example.test/v1', ['Authorization' => 'Bearer private-key']);
    $body = ['model' => 'synthetic-model', 'input' => [['role' => 'user', 'content' => 'Private input.']], 'store' => false, 'max_output_tokens' => 40];
    $client->post('responses', $body);
    guardedGatewayRefusal(fn () => $client->post('responses', $body), 'usage_attempt_dispatched');
    expect($session->calls)->toHaveCount(1)->and(fn () => serialize($client))->toThrow(LogicException::class);
    ob_start();
    var_dump($client);
    $debug = ob_get_clean();
    expect($debug)->not->toContain('private-key', 'Private input.', 'models.example.test');
});

test('SDK zero defaults never replace missing raw usage evidence', function (string $driver): void {
    $world = guardedGatewayWorld($driver);
    $world['session']->response = guardedGatewayResponse($driver);
    unset($world['session']->response['usage'], $world['session']->response['prompt_eval_count'], $world['session']->response['eval_count']);
    $response = guardedGatewayInvoke($world);
    expect($response->usage->inputTokens)->toBe(0)->and($response->usage->outputTokens)->toBe(0)
        ->and(array_slice($world['session']->observed[0], 1))->toBe([null, null]);
})->with('guarded sdk drivers');

test('SDK headers and base URL cannot add foreign credentials or destinations', function (string $case): void {
    $session = new GuardedGatewaySessionFixture(guardedGatewayConnection());
    $baseUrl = $case === 'base' ? 'https://foreign.example.test/v1' : 'https://models.example.test/v1';
    $headers = match ($case) {
        'key' => ['Authorization' => 'Bearer foreign-key'],
        'host' => ['Authorization' => 'Bearer private-key', 'Host' => 'foreign.example.test'],
        'extra' => ['Authorization' => 'Bearer private-key', 'X-Private' => 'private-value'],
        default => ['Authorization' => 'Bearer private-key'],
    };
    guardedGatewayRefusal(fn () => new GuardedAiPendingRequest($session, $baseUrl, $headers));
    expect($session->calls)->toBe([]);
})->with(['base', 'key', 'host', 'extra']);

test('separate guarded clients preserve account credential isolation', function (): void {
    $first = new GuardedGatewaySessionFixture(guardedGatewayConnection(key: 'first-account-key', accountId: 1));
    $second = new GuardedGatewaySessionFixture(guardedGatewayConnection(key: 'second-account-key', accountId: 2));
    $body = ['model' => 'synthetic-model', 'input' => [['role' => 'user', 'content' => 'Private input.']], 'store' => false, 'max_output_tokens' => 40];
    (new GuardedAiPendingRequest($first, 'https://models.example.test/v1', ['Authorization' => 'Bearer first-account-key']))->post('responses', $body);
    (new GuardedAiPendingRequest($second, 'https://models.example.test/v1', ['Authorization' => 'Bearer second-account-key']))->post('responses', $body);
    expect($first->calls[0]['headers']['authorization'])->toBe('Bearer first-account-key')
        ->and($second->calls[0]['headers']['authorization'])->toBe('Bearer second-account-key')
        ->and(count($first->calls))->toBe(1)->and(count($second->calls))->toBe(1);
});
