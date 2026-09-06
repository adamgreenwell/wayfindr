<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function aiSettingsOperator(?string $locale = null): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
    ]);
}

test('only a platform operator can reach agent copilot settings', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get(route('operator.settings.ai.edit'))
        ->assertForbidden();

    $this->actingAs(aiSettingsOperator())
        ->get(route('operator.settings.ai.edit'))
        ->assertOk()
        ->assertSee('Agent copilot')
        ->assertSee('Provider boundary')
        ->assertSee('Data boundary')
        ->assertSee('Test the connection');
});

test('an operator can save a hosted provider with a write-only encrypted key', function (): void {
    $secret = 'provider-key-that-must-never-be-rendered';

    $this->actingAs(aiSettingsOperator())
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'endpoint' => '',
            'api_key' => $secret,
        ])
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionHas('status');

    $settings = app(OperatorSettings::class);
    $stored = (string) OperatorSetting::query()->where('key', 'ai.api_key')->value('value');

    expect($settings->get('ai.provider'))->toBe('openai')
        ->and($settings->get('ai.model'))->toBe('gpt-5-mini')
        ->and($settings->get('ai.endpoint'))->toBe('')
        ->and($settings->get('ai.api_key'))->toBe($secret)
        ->and($stored)->not->toContain($secret);

    $response = $this->actingAs(aiSettingsOperator())->get(route('operator.settings.ai.edit'));

    $response->assertOk()
        ->assertSee('an API key is configured')
        ->assertDontSee($secret);
});

test('local and openai-compatible providers have the intended credential rules', function (): void {
    $operator = aiSettingsOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'ollama',
            'model' => 'qwen3.5:4b',
            'endpoint' => 'http://localhost:11434',
        ])
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($operator)
        ->from(route('operator.settings.ai.edit'))
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'openai-compatible',
            'model' => 'local-model',
            'endpoint' => '',
        ])
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionHasErrors('endpoint');

    $this->actingAs($operator)
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'openai-compatible',
            'model' => 'local-model',
            'endpoint' => 'https://models.example.test/v1/',
        ])
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionDoesntHaveErrors();

    expect(app(OperatorSettings::class)->get('ai.endpoint'))
        ->toBe('https://models.example.test/v1');
});

test('hosted provider validation neither stores nor flashes a submitted key', function (): void {
    $secret = 'never-put-this-key-in-the-session';

    $this->actingAs(aiSettingsOperator())
        ->from(route('operator.settings.ai.edit'))
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'anthropic',
            'model' => '',
            'api_key' => $secret,
        ])
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionHasErrors('model')
        ->assertSessionMissing('_old_input.api_key');

    expect(OperatorSetting::query()->where('key', 'ai.api_key')->exists())->toBeFalse();
});

test('the settings audit is instance scoped and never contains the api key', function (): void {
    $secret = 'another-provider-secret';
    $operator = aiSettingsOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'endpoint' => '',
            'api_key' => $secret,
        ])
        ->assertRedirect();

    $event = AuditEvent::query()->where('action', 'operator_settings.ai.updated')->sole();

    expect($event->account_id)->toBeNull()
        ->and($event->actor_id)->toBe($operator->id)
        ->and($event->metadata)->toMatchArray([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'endpoint_configured' => false,
            'status' => 'ready',
            'api_key_changed' => 'updated',
        ])
        ->and(json_encode($event->metadata))->not->toContain($secret);
});

test('an ai settings change appears in the safe operator activity feed', function (): void {
    $secret = 'activity-feed-secret';
    $operator = aiSettingsOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.ai.update'), [
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'endpoint' => 'https://models.example.test/v1',
            'api_key' => $secret,
        ])
        ->assertRedirect();

    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Agent copilot settings updated')
        ->assertSee('provider:')
        ->assertSee('openai')
        ->assertSee('gpt-5-mini')
        ->assertSee('Custom endpoint')
        ->assertDontSee($secret);
});

test('the connection test sends only the provider synthetic probe', function (): void {
    $fake = new class implements AgentCopilotProvider
    {
        public ?AgentCopilotPrompt $prompt = null;

        public bool $probed = false;

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompt = $prompt;

            return new AgentCopilotResult('ok', 'fake', 'fake-model');
        }

        public function probe(): AgentCopilotResult
        {
            $this->probed = true;

            return new AgentCopilotResult('ok', 'ollama', 'qwen3.5:4b');
        }
    };
    app()->instance(AgentCopilotProvider::class, $fake);

    $this->actingAs(aiSettingsOperator())
        ->post(route('operator.settings.ai.test'))
        ->assertRedirect(route('operator.settings.ai.edit'))
        ->assertSessionHas('status');

    expect($fake->probed)->toBeTrue()
        ->and($fake->prompt)->toBeNull();
});

test('provider failures return safe localized feedback without exception details', function (): void {
    Log::spy();

    app()->instance(AgentCopilotProvider::class, new class implements AgentCopilotProvider
    {
        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            throw new RuntimeException('Bearer live-secret from https://private.example.test/account/42');
        }

        public function probe(): AgentCopilotResult
        {
            throw new RuntimeException('Bearer live-secret from https://private.example.test/account/42');
        }
    });

    $this->actingAs(aiSettingsOperator('de'))
        ->followingRedirects()
        ->post(route('operator.settings.ai.test'))
        ->assertOk()
        ->assertSee('Der Anbietertest ist fehlgeschlagen.')
        ->assertDontSee('live-secret')
        ->assertDontSee('private.example.test');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Agent copilot synthetic provider test failed.', Mockery::on(
            fn (array $context): bool => ($context['exception_type'] ?? null) === RuntimeException::class
                && ! str_contains(json_encode($context), 'live-secret')
                && ! str_contains(json_encode($context), 'private.example.test')
        ));
});

test('configuration assessment distinguishes optional, incomplete, and ready states', function (): void {
    $configuration = app(AgentCopilotConfiguration::class);

    expect($configuration->assessValues('', '', '', false)['status'])->toBe('unset')
        ->and($configuration->assessValues('openai', '', '', true))->toMatchArray([
            'status' => 'incomplete',
            'missing' => ['model'],
        ])
        ->and($configuration->assessValues('openai', 'gpt-5-mini', '', false))->toMatchArray([
            'status' => 'incomplete',
            'missing' => ['api_key'],
        ])
        ->and($configuration->assessValues('ollama', 'qwen3.5:4b', '', false)['status'])->toBe('ready')
        ->and($configuration->assessValues('openai-compatible', 'local', '', false))->toMatchArray([
            'status' => 'incomplete',
            'missing' => ['endpoint'],
        ])
        ->and($configuration->assessValues('future-provider', 'future-model', '', true)['status'])->toBe('unsupported');
});
