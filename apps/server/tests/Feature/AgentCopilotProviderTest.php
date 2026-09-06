<?php

declare(strict_types=1);

use App\Models\OperatorSetting;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotUnavailable;
use App\Support\Ai\CopilotAgent;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->and($result->provider)->toBe('wayfindr')
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

test('an unset or partial provider fails closed before the sdk is called', function (): void {
    CopilotAgent::fake()->preventStrayPrompts();

    expect(fn () => app(AgentCopilotProvider::class)->probe())
        ->toThrow(AgentCopilotUnavailable::class);

    CopilotAgent::assertNeverPrompted();
});

test('provider credentials are encrypted in the shared settings store', function (): void {
    app(OperatorSettings::class)->set('ai.api_key', 'super-secret-provider-key');

    $stored = OperatorSetting::query()->where('key', 'ai.api_key')->value('value');

    expect($stored)->not->toBe('super-secret-provider-key')
        ->and((string) $stored)->not->toContain('super-secret-provider-key')
        ->and(app(OperatorSettings::class)->get('ai.api_key'))->toBe('super-secret-provider-key');
});
