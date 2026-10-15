<?php

declare(strict_types=1);

use App\Models\OperatorSetting;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotSdkFactory;
use App\Support\Ai\AgentCopilotUnavailable;
use App\Support\Ai\CopilotAgent;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

test('the provider boundary sanitizes and bounds text without attachments', function (): void {
    config()->set('wayfindr.ai.max_context_characters', 1000);

    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();

    CopilotAgent::fake(['A concise draft.']);

    $result = app(AgentCopilotProvider::class)->generate(new AgentCopilotPrompt(
        purpose: 'draft_reply',
        instructions: 'Draft a reply for an agent to review.',
        input: 'Email ada@example.test token=secret-value '.str_repeat('bounded context ', 100),
    ));

    expect($result->text)->toBe('A concise draft.')
        ->and($result->provider)->toBe('ollama')
        ->and($result->model)->toBe('qwen3.5:4b');

    CopilotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->attachments->isEmpty()
            && mb_strlen($prompt->prompt) === 1000
            && str_contains($prompt->prompt, '[EMAIL REDACTED]')
            && str_contains($prompt->prompt, 'token=[REDACTED]')
            && ! str_contains($prompt->prompt, 'ada@example.test')
            && $prompt->model === 'qwen3.5:4b';
    });
});

test('openrouter calls require zero retention and stay on one upstream provider', function (): void {
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'openrouter');
    $settings->set('ai.model', 'anthropic/claude-sonnet-4.5');
    $settings->set('ai.openrouter_provider', 'amazon-bedrock');
    $settings->set('ai.api_key', 'openrouter-test-key');
    $settings->applyOverrides();

    Http::preventStrayRequests();
    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'generation-test',
            'model' => 'anthropic/claude-sonnet-4.5',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'A grounded candidate.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
        ]),
    ]);

    $result = app(AgentCopilotProvider::class)->generate(new AgentCopilotPrompt(
        purpose: 'grounded_answer_evaluation',
        instructions: 'Answer only from the supplied synthetic article.',
        input: 'Synthetic question and article.',
    ));

    expect($result->text)->toBe('A grounded candidate.')
        ->and($result->provider)->toBe('openrouter/amazon-bedrock')
        ->and($result->model)->toBe('anthropic/claude-sonnet-4.5');

    // Recorded on every copilot artifact. Read through the real SDK driver, so
    // a renamed or swapped usage field fails here rather than at runtime.
    expect($result->promptTokens)->toBe(12, 'the prompt token count was not read from the provider response')
        ->and($result->completionTokens)->toBe(4, 'the completion token count was not read from the provider response');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request['model'] === 'anthropic/claude-sonnet-4.5'
            && $request['provider'] === [
                'order' => ['amazon-bedrock'],
                'allow_fallbacks' => false,
                'zdr' => true,
            ];
    });
});

test('provider-specific routing options do not leak into direct provider calls', function (): void {
    $agent = new CopilotAgent('Answer briefly.');

    expect($agent->providerOptions('openai'))->toBe([])
        ->and($agent->providerOptions('anthropic'))->toBe([])
        ->and($agent->providerOptions('gemini'))->toBe([]);
});

test('an unset or partial provider fails closed before the sdk is called', function (): void {
    CopilotAgent::fake()->preventStrayPrompts();

    expect(fn () => app(AgentCopilotProvider::class)->probe())
        ->toThrow(AgentCopilotUnavailable::class);

    CopilotAgent::assertNeverPrompted();
});

test('the provider preserves structured prompts after their feature scrub pass', function (): void {
    $settings = app(OperatorSettings::class);
    $settings->set('ai.provider', 'ollama');
    $settings->set('ai.model', 'qwen3.5:4b');
    $settings->set('ai.endpoint', 'http://localhost:11434');
    $settings->applyOverrides();

    CopilotAgent::fake(['A concise summary.']);
    $input = json_encode([
        'messages' => [['role' => 'visitor', 'body' => 'The final value is token=[REDACTED]']],
    ], JSON_THROW_ON_ERROR);

    app(AgentCopilotProvider::class)->generate(new AgentCopilotPrompt(
        purpose: 'conversation_summary',
        instructions: 'Summarize for an agent to review.',
        input: $input,
    ));

    CopilotAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        return json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR) === [
            'messages' => [['role' => 'visitor', 'body' => 'The final value is token=[REDACTED]']],
        ];
    });
});

test('provider credentials are encrypted in the shared settings store', function (): void {
    app(OperatorSettings::class)->set('ai.api_key', 'super-secret-provider-key');

    $stored = OperatorSetting::query()->where('key', 'ai.api_key')->value('value');

    expect($stored)->not->toBe('super-secret-provider-key')
        ->and((string) $stored)->not->toContain('super-secret-provider-key')
        ->and(app(OperatorSettings::class)->get('ai.api_key'))->toBe('super-secret-provider-key');
});

test('attaching a resolved connection leaves the original prompt unchanged', function (): void {
    $connection = new ResolvedAgentCopilotConnection(
        accountId: 12,
        source: 'installation',
        revision: str_repeat('a', 64),
        provider: 'ollama',
        model: 'qwen3.5:4b',
        endpoint: 'http://localhost:11434',
        openRouterProvider: '',
        apiKey: '',
    );
    $original = new AgentCopilotPrompt('draft_reply', 'Draft for review.', 'Synthetic question.', 20);
    $scoped = $original->withConnection($connection);

    expect($original->connection)->toBeNull()
        ->and($scoped)->not->toBe($original)
        ->and($scoped->connection)->toBe($connection)
        ->and([$scoped->purpose, $scoped->instructions, $scoped->input, $scoped->timeoutSeconds])
        ->toBe([$original->purpose, $original->instructions, $original->input, $original->timeoutSeconds]);
});

test('fresh sdk drivers preserve explicit connection configuration without mutating globals', function (string $driver): void {
    $before = config('ai');
    $factory = app(AgentCopilotSdkFactory::class);
    $a = $factory->make($driver, 'model-a', 'https://a.example.test/v1', 'key-a');
    $b = $factory->make($driver, 'model-b', 'https://b.example.test/v1', 'key-b');
    $again = $factory->make($driver, 'model-a', 'https://a.example.test/v1', 'key-a');

    expect($a)->not->toBe($b)
        ->and($again)->not->toBe($a)
        ->and($a->driver())->toBe($driver)
        ->and($a->providerCredentials())->toBe(['key' => 'key-a'])
        ->and($b->providerCredentials())->toBe(['key' => 'key-b'])
        ->and($again->providerCredentials())->toBe(['key' => 'key-a'])
        ->and($a->defaultTextModel())->toBe('model-a')
        ->and($b->defaultTextModel())->toBe('model-b')
        ->and($again->defaultTextModel())->toBe('model-a')
        ->and($a->additionalConfiguration()['url'])->toBe('https://a.example.test/v1')
        ->and($b->additionalConfiguration()['url'])->toBe('https://b.example.test/v1')
        ->and($again->additionalConfiguration()['url'])->toBe('https://a.example.test/v1')
        ->and($again->additionalConfiguration()['store'])->toBeFalse()
        ->and(config('ai'))->toBe($before);
})->with(['anthropic', 'gemini', 'ollama', 'openai', 'openai-compatible', 'openrouter']);

test('resolved installation calls isolate credentials model endpoint and upstream across a reused adapter', function (): void {
    // Deliberately disagree with both explicit connections. The adapter must
    // not take routing or credentials from these process-wide defaults.
    config()->set('ai.providers.wayfindr', [
        'driver' => 'openrouter', 'key' => 'wrong-global-key',
        'url' => 'https://wrong.example.test/v1',
        'models' => ['text' => ['default' => 'wrong-model']],
        'openrouter' => ['provider' => 'wrong-upstream'],
    ]);
    $before = config('ai');
    $connections = [
        new ResolvedAgentCopilotConnection(12, 'installation', str_repeat('a', 64), 'openrouter', 'model-a', 'https://a.example.test/v1', 'amazon-bedrock', 'key-a'),
        new ResolvedAgentCopilotConnection(24, 'installation', str_repeat('b', 64), 'openrouter', 'model-b', 'https://b.example.test/v1', 'anthropic', 'key-b'),
    ];
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => Http::response([
        'id' => 'generation-test',
        'model' => $request['model'],
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'A reviewable candidate.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
    ]));
    $provider = app(AgentCopilotProvider::class);
    $prompt = new AgentCopilotPrompt('draft_reply', 'Draft for review.', 'Synthetic question.');

    foreach ([$connections[0], $connections[1], $connections[0]] as $connection) {
        $result = $provider->generate($prompt->withConnection($connection));

        expect($result->provider)->toBe('openrouter/'.$connection->openRouterProvider)
            ->and($result->model)->toBe($connection->model)
            ->and($result->promptTokens)->toBe(12)
            ->and($result->completionTokens)->toBe(4);
    }

    $requests = Http::recorded()->map(fn (array $pair): array => [
        $pair[0]->url(), $pair[0]->header('Authorization'), $pair[0]['model'], $pair[0]['provider'],
    ])->all();

    expect($requests)->toBe([
        ['https://a.example.test/v1/chat/completions', ['Bearer key-a'], 'model-a', ['order' => ['amazon-bedrock'], 'allow_fallbacks' => false, 'zdr' => true]],
        ['https://b.example.test/v1/chat/completions', ['Bearer key-b'], 'model-b', ['order' => ['anthropic'], 'allow_fallbacks' => false, 'zdr' => true]],
        ['https://a.example.test/v1/chat/completions', ['Bearer key-a'], 'model-a', ['order' => ['amazon-bedrock'], 'allow_fallbacks' => false, 'zdr' => true]],
    ])->and(config('ai'))->toBe($before);
});

test('account owned outbound calls remain blocked even when sdk or http fakes are installed', function (bool $fakeAgent): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response([
        'id' => 'unauthorized-generation',
        'model' => 'model-a',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'This call must never happen.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    if ($fakeAgent) {
        CopilotAgent::fake(['A fake is not authorization.']);
    }

    $connection = new ResolvedAgentCopilotConnection(
        accountId: 12,
        source: 'account',
        revision: str_repeat('a', 64),
        provider: 'openai-compatible',
        model: 'model-a',
        endpoint: 'http://127.0.0.1:11434/v1',
        openRouterProvider: '',
        apiKey: 'account-secret',
    );
    $prompt = (new AgentCopilotPrompt('configuration_probe', 'Synthetic probe.', 'No customer data.'))->withConnection($connection);

    expect(fn () => app(AgentCopilotProvider::class)->generate($prompt))
        ->toThrow(AgentCopilotUnavailable::class, 'account_endpoint_policy_pending');

    Http::assertNothingSent();
    CopilotAgent::assertNeverPrompted();
})->with([true, false]);
