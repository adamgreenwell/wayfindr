<?php

declare(strict_types=1);

use App\Models\OperatorSetting;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotUnavailable;
use App\Support\Ai\CopilotAgent;
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
