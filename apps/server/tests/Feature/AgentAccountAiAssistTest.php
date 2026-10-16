<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionPresenter;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Network\AiModelDiscovery;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsagePolicy;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'ai.providers.wayfindr.driver' => 'openai', 'ai.providers.wayfindr.models.text.default' => 'installation-model',
        'ai.providers.wayfindr.key' => 'installation-key-never-in-browser',
        'ai.providers.wayfindr.url' => 'http://private-installation.example.test:11434/v1',
        'ai.providers.wayfindr.openrouter.provider' => '',
        'wayfindr.ai.usage' => ['daily_requests' => 100, 'daily_tokens' => 50000, 'concurrency' => 2, 'max_output_tokens' => 2048],
    ]);
    app(OperatorSettings::class)->captureBaseline();
    app()->instance(AccountAiConnectionProbe::class, new class implements AccountAiConnectionProbe
    {
        public int $calls = 0;

        public ?Closure $during = null;

        public string $policy = 'admin-synthetic-policy-v1';

        public function policyRevision(): string
        {
            return $this->policy;
        }

        public function probe(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): AgentCopilotResult
        {
            $this->calls++;
            ($this->during ?? fn () => null)($connection, $context);

            return new AgentCopilotResult('synthetic-ack-never-in-browser', 'synthetic', 'synthetic');
        }
    });
    app()->instance(AiModelDiscovery::class, new class implements AiModelDiscovery
    {
        public int $calls = 0;

        public function discover(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): ?array
        {
            $this->calls++;

            return ['support-model:exact', 'support-model/version-2'];
        }
    });
});

function accountAiAdminOwner(): User
{
    return User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
}

function accountAiAdminInput(?string $revision = null, array $changes = []): array
{
    return [
        'expected_revision' => $revision, 'editor_token' => accountAiAdminEditorToken(), 'source' => 'account', 'name' => 'Support model',
        'provider' => 'openai-compatible', 'model' => 'support-model:exact', 'endpoint' => 'https://models.example.test/v1',
        'openrouter_provider' => '', 'api_key' => 'account-key-never-in-browser', ...$changes,
    ];
}

function accountAiAdminEditorToken(?User $actor = null): string
{
    $accountId = (int) ($actor ?? auth()->user())?->account_id;
    $record = AccountAiConnection::query()->where('account_id', $accountId)->first();

    return AccountAiConnections::editorToken($accountId, $record);
}

function accountAiAdminTestAndActivate(User $owner): AccountAiConnection
{
    test()->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->where('account_id', $owner->account_id)->sole();
    test()->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['api_key' => '']))->assertOk();

    return $record->refresh();
}

test('the account AI page is permission scoped and a GET never probes or discovers', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertViewIs('agent.account.ai-assist');
    expect(app(AccountAiConnectionProbe::class)->calls)->toBe(0)->and(app(AiModelDiscovery::class)->calls)->toBe(0);
    $agent = User::factory()->for($owner->account)->create(['account_role' => AccountRole::Agent]);
    $this->actingAs($agent)->get(route('dashboard.account.ai-assist.show'))->assertForbidden();
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput())->assertForbidden();
    $owner->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($owner)->get(route('dashboard.account.ai-assist.show'))->assertRedirect(route('login'));
});

test('the legacy accounting notice disappears after an explicit tested connection selection', function (string $source): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->get(route('dashboard.account.ai-assist.show'))->assertOk()
        ->assertSee('data-ai-legacy-notice', false)
        ->assertViewHas('assist', fn (array $assist): bool => $assist['active']['legacy'] && $assist['active']['available']);
    $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, [
        'source' => $source, 'api_key' => $source === 'account' ? 'account-key-never-in-browser' : '',
    ]))->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['source' => $source, 'api_key' => '']))->assertOk();
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk()
        ->assertDontSee('data-ai-legacy-notice', false)
        ->assertViewHas('assist', fn (array $assist): bool => ! $assist['active']['legacy'] && $assist['active']['available']);
})->with(['account', 'installation']);

test('a paused legacy default does not claim unmetered support calls remain available', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 0])->assertOk();
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk()
        ->assertDontSee('data-ai-legacy-notice', false)
        ->assertViewHas('assist', fn (array $assist): bool => $assist['active']['legacy'] && ! $assist['active']['available']);
});

test('safe presentation never exposes either credential private installation routes or other account consumption', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    DB::table('ai_usage_periods')->where('scope_key', 'installation')->update(['charged_requests' => 918273, 'charged_tokens' => 81726354]);
    $presented = app(AccountAiConnectionPresenter::class)->present($owner);
    $encoded = json_encode($presented, JSON_THROW_ON_ERROR);
    foreach (['account-key-never-in-browser', 'installation-key-never-in-browser', 'private-installation.example.test', 'synthetic-ack-never-in-browser', '918273', '81726354'] as $private) {
        expect($encoded)->not->toContain($private, 'Only account-owned browser-safe presentation may leave the presenter.');
    }
    expect($presented['usage'])->not->toHaveKey('installation')
        ->and($presented['form']['credential_status'])->toBe('saved')
        ->and($presented['form']['expected_revision'])->toBe($record->proposed_revision);
});

test('testing preserves active processing until the exact tested form is saved', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())
        ->assertOk()->assertJsonPath('assist.state', 'ready')->assertJsonPath('assist.test.can_save', true);
    $record = AccountAiConnection::query()->sole();
    expect($record->active_revision)->toBeNull()->and($record->uses_installation_default)->toBeTrue();
    $revision = $record->proposed_revision;
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($revision, ['api_key' => '']))->assertOk();
    expect($record->fresh()->active_revision)->toBe($revision)->and($record->fresh()->uses_installation_default)->toBeFalse();
});

test('a fresh running connection test renders a disabled native Test button', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput())->assertOk();
    AccountAiConnection::query()->sole()->forceFill(['test_status' => 'running', 'test_started_at' => now()])->save();
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertViewHas('assist', fn (array $assist): bool => $assist['state'] === 'preparing');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $buttons = (new DOMXPath($document))->query('//button[@data-ai-action="test"]');
    expect($buttons->length)->toBe(1)
        ->and($buttons->item(0)->hasAttribute('disabled'))->toBeTrue('Preparing must disable native duplicate testing before any JavaScript runs.');
});

test('a display name change retains the ready draft and its exact test receipt', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $revision = $record->proposed_revision;
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput($revision, ['name' => 'New display name', 'api_key' => '']))
        ->assertOk()->assertJsonPath('assist.state', 'ready')->assertJsonPath('assist.test.can_save', true);
    expect($record->fresh()->proposed_revision)->toBe($revision)
        ->and($record->fresh()->tested_revision)->toBe($revision)
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1);
});

test('saving changed tested fields creates an untested draft and never activates it', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $activeRevision = $record->active_revision;
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['model' => 'untested-model', 'api_key' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors('connection');
    expect($record->fresh()->active_revision)->toBe($activeRevision, 'Editing a tested form cannot activate its newly created revision.')
        ->and($record->fresh()->proposed_revision)->not->toBe($activeRevision)
        ->and($record->fresh()->test_status)->toBe('untested');
});

test('two initial editors cannot overwrite the first proposal and stale discard is rejected', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput())->assertOk();
    $first = AccountAiConnection::query()->sole();
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput(null, ['model' => 'stale-editor']))
        ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    $this->postJson(route('dashboard.account.ai-assist.discard'), ['expected_revision' => (string) Str::uuid(), 'editor_token' => accountAiAdminEditorToken()])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    expect($first->fresh()->proposedConfiguration()['model'])->toBe('support-model:exact');
});

test('failed save reloads the newly saved draft so its corrected test can proceed', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $oldRevision = $record->proposed_revision;
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($oldRevision, ['model' => 'corrected-model', 'api_key' => '']))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('connection');
    expect(session('_old_input', []))->toBeEmpty('A post-proposal failure must reload saved fields and revision together.');
    $newRevision = $record->fresh()->proposed_revision;
    expect($newRevision)->not->toBe($oldRevision);
    $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput($newRevision, ['model' => 'corrected-model', 'api_key' => '']))->assertOk();
});

test('an incomplete first test can be corrected using the revision its failed action saved', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, ['model' => '']))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('connection');
    expect(session('_old_input', []))->toBeEmpty();
    $record = AccountAiConnection::query()->sole();
    $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput($record->proposed_revision, ['api_key' => '']))->assertOk();
});

test('a genuine stale editor retains its old revision and cannot overwrite the current draft', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $oldRevision = $record->proposed_revision;
    $oldToken = accountAiAdminEditorToken();
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput($oldRevision, ['model' => 'other-editor-model', 'api_key' => '']))->assertOk();
    $this->post(route('dashboard.account.ai-assist.propose'), accountAiAdminInput($oldRevision, ['editor_token' => $oldToken, 'model' => 'stale-editor-model', 'api_key' => '']))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('expected_revision');
    expect(session('_old_input.expected_revision'))->toBe($oldRevision, 'Stale fields must not gain a newer proposal revision implicitly.')
        ->and(session('_old_input.editor_token'))->toBe($oldToken)
        ->and($record->fresh()->proposedConfiguration()['model'])->toBe('other-editor-model');
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('stale')
        ->and($xpath->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))
        ->toBeTrue('Genuine stale connection edits must still withhold Save authority.')
        ->and($xpath->query('//input[@name="editor_token"]')->item(0)->getAttribute('value'))->toBe($oldToken);
});

test('clearing a proposed key and discarding that draft preserves the active credential', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $active = $record->active_revision;
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput($record->proposed_revision, ['api_key' => '', 'clear_api_key' => 1]))
        ->assertOk()->assertJsonPath('assist.form.credential_status', 'clear');
    expect($record->fresh()->active_revision)->toBe($active)
        ->and($record->fresh()->activeConfiguration()['api_key'])->toBe('account-key-never-in-browser');
    $this->postJson(route('dashboard.account.ai-assist.discard'), ['expected_revision' => $record->fresh()->proposed_revision, 'editor_token' => accountAiAdminEditorToken()])
        ->assertOk()->assertJsonPath('assist.form.credential_status', 'saved');
    expect($record->fresh()->proposedConfiguration())->toBeNull()
        ->and($record->fresh()->activeConfiguration()['api_key'])->toBe('account-key-never-in-browser');
});

test('disconnect clears credentials without preselecting or falling back to installation', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $this->postJson(route('dashboard.account.ai-assist.disconnect'), ['expected_revision' => $record->proposed_revision, 'editor_token' => accountAiAdminEditorToken(), 'account_id' => Account::factory()->create()->id])
        ->assertOk()->assertJsonPath('assist.form.source', '')->assertJsonPath('assist.active.configured', false)
        ->assertJsonPath('assist.active.available', false);
    expect($record->fresh()->proposedConfiguration())->toBeNull()->and($record->fresh()->activeConfiguration())->toBeNull()
        ->and(app(AgentCopilotConnectionResolver::class)->forAccount((int) $owner->account_id))->toBeNull();
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 1, 'editor_token' => accountAiAdminEditorToken()])->assertUnprocessable();
    expect(config('ai.providers.wayfindr.key'))->toBe('installation-key-never-in-browser');
});

test('installation selection stores a reference and never lets account controls erase the operator key', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, ['source' => 'installation', 'api_key' => '', 'clear_api_key' => 1]))->assertOk();
    $record = AccountAiConnection::query()->sole();
    expect($record->proposedConfiguration())->toHaveKey('installation_revision')->not->toHaveKey('api_key');
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['source' => 'installation', 'api_key' => '']))->assertOk();
    $this->postJson(route('dashboard.account.ai-assist.disconnect'), ['expected_revision' => $record->proposed_revision, 'editor_token' => accountAiAdminEditorToken()])->assertOk();
    expect(config('ai.providers.wayfindr.key'))->toBe('installation-key-never-in-browser');
});

test('clearing a required credential cannot replace the active processor with a keyless proposal', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, ['provider' => 'openai']))->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['provider' => 'openai', 'api_key' => '']))->assertOk();
    $activeRevision = $record->fresh()->active_revision;
    $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput($record->proposed_revision, ['provider' => 'openai', 'api_key' => '', 'clear_api_key' => 1]))->assertUnprocessable();
    expect($record->fresh()->active_revision)->toBe($activeRevision)
        ->and($record->fresh()->activeConfiguration()['api_key'])->toBe('account-key-never-in-browser')
        ->and($record->fresh()->proposedConfiguration()['api_key'])->toBe('');
});

test('discovery accepts a blank model and renders exact suggestions without persisting a catalogue', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.discover'), accountAiAdminInput(null, ['model' => '']))
        ->assertOk()->assertJsonPath('models.0', 'support-model:exact')->assertJsonPath('models.1', 'support-model/version-2');
    $record = AccountAiConnection::query()->sole();
    expect($record->active_revision)->toBeNull()->and($record->test_status)->toBe('untested')
        ->and($record->proposedConfiguration()['model'])->toBe('')->and(app(AccountAiConnectionProbe::class)->calls)->toBe(0);
});

test('invalid endpoint credentials and keys never enter old input or JSON errors', function (string $endpoint): void {
    $owner = accountAiAdminOwner();
    $input = accountAiAdminInput(null, ['endpoint' => $endpoint, 'api_key' => 'private-key-marker']);
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.propose'), $input)
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('endpoint');
    expect(session('_old_input'))->not->toHaveKey('api_key')->not->toHaveKey('endpoint');
    $this->postJson(route('dashboard.account.ai-assist.propose'), $input)->assertUnprocessable()
        ->assertDontSee('private-key-marker')->assertDontSee('private-url-marker');
    expect(AccountAiConnection::query()->count())->toBe(0);
})->with(['https://user:private-url-marker@models.example.test/v1', 'https://models.example.test/v1?key=private-url-marker', 'https://models.example.test/v1#private-url-marker']);

test('new account usage limits can inherit narrow or explicitly prohibit before a connection exists', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->putJson(route('dashboard.account.ai-assist.limits'), [
        'daily_requests' => 10, 'daily_tokens' => null, 'concurrency' => 0, 'max_output_tokens' => 64,
        'limits_token' => accountAiAdminLimitsToken($owner),
    ])->assertOk()->assertJsonPath('assist.limits.effective.daily_requests', 10)
        ->assertJsonPath('assist.limits.effective.daily_tokens', 50000)->assertJsonPath('assist.limits.effective.concurrency', 0);
    expect(AccountAiConnection::query()->count())->toBe(0);
    $this->putJson(route('dashboard.account.ai-assist.limits'), [
        'daily_requests' => 101, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null,
        'limits_token' => accountAiAdminLimitsToken($owner),
    ])->assertUnprocessable()->assertJsonValidationErrors('daily_requests');
});

test('usage limit errors preserve the connection state endpoint and native actions', function (string $state, string $limitField, int $invalidValue): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $record->forceFill(match ($state) {
        'preparing' => ['test_status' => 'running', 'test_started_at' => now()],
        'stale' => ['tested_policy_revision' => 'superseded-policy'],
        'unavailable' => ['test_status' => 'unavailable', 'test_failure_code' => 'key_rejected'],
        'untested' => ['test_status' => 'untested', 'tested_revision' => null, 'tested_fingerprint' => null, 'tested_policy_revision' => null, 'tested_at' => null],
        default => [],
    })->save();
    $revision = $record->proposed_revision;
    $editorToken = accountAiAdminEditorToken();
    $limits = ['daily_requests' => null, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null, $limitField => $invalidValue,
        'limits_token' => accountAiAdminLimitsToken($owner)];
    // Extra connection fields on this route are not connection edits either.
    $this->put(route('dashboard.account.ai-assist.limits'), [
        ...accountAiAdminInput($revision, ['model' => 'irrelevant-limit-field', 'endpoint' => '']), ...$limits,
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors($limitField);
    $oldInput = session('_old_input', []);
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))
        ->toBe($state, 'A usage-limit validation error must preserve the independently tested connection state.')
        ->and($xpath->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))
        ->toBe($state !== 'ready', 'Limit errors must not remove Save authority from a Ready connection.')
        ->and($xpath->query('//button[@data-ai-action="test"]')->item(0)->hasAttribute('disabled'))
        ->toBe($state === 'preparing', 'Limit errors must not unlock duplicate testing of a Preparing connection.')
        ->and($xpath->query('//input[@name="endpoint"]')->item(0)->getAttribute('value'))
        ->toBe('https://models.example.test/v1', 'A separate form error must never blank the saved draft endpoint.')
        ->and($xpath->query('//input[@name="model"]')->item(0)->getAttribute('value'))->toBe('support-model:exact')
        ->and($xpath->query('//input[@name="expected_revision"]')->item(0)->getAttribute('value'))->toBe($revision)
        ->and($xpath->query('//input[@name="editor_token"]')->item(0)->getAttribute('value'))->toBe($editorToken)
        ->and($oldInput)->toBe($limits)
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1)
        ->and(app(AiModelDiscovery::class)->calls)->toBe(0);
    if ($state === 'ready') {
        $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($revision, ['api_key' => '']))
            ->assertOk()->assertJsonPath('assist.active.configured', true);
        expect($record->fresh()->active_revision)->toBe($revision)
            ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1);
    }
})->with(['ready', 'preparing', 'stale', 'unavailable', 'untested'])
    ->with(['operator ceiling' => ['daily_requests', 101], 'field validation' => ['daily_tokens', -1]]);

test('malformed array fields never survive validation into old input or break the redirected page', function (string $field): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.propose'), accountAiAdminInput(null, [$field => ['unexpected-array']]))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors($field);
    expect(session('_old_input', []))->not->toHaveKey($field, 'Malformed field arrays must never reach the Blade scalar fields.');
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
})->with(['source', 'name', 'provider', 'model', 'endpoint', 'openrouter_provider', 'expected_revision', 'editor_token', 'clear_api_key']);

test('malformed usage limit arrays never survive validation into a number input', function (string $field): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->put(route('dashboard.account.ai-assist.limits'), [
        'daily_requests' => null, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null,
        $field => ['unexpected-array'], 'limits_token' => accountAiAdminLimitsToken($owner),
    ])->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors($field);
    expect(session('_old_input', []))->not->toHaveKey($field);
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
})->with(['daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens']);

test('a scoped connection manager can draft and test but cannot reroute inaccessible sites', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create(['permissions' => [AccountPermission::ManageAiConnections->value]]);
    $manager = User::factory()->for($account)->create(['account_role' => AccountRole::Agent, 'custom_role_id' => $role->id]);
    $other = User::factory()->for($account)->create();
    Site::factory()->for($account)->create()->supportAgents()->attach($other);
    $this->actingAs($manager)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['api_key' => '']))->assertForbidden();
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 0])->assertOk();
    $this->postJson(route('dashboard.account.ai-assist.disconnect'), ['expected_revision' => $record->proposed_revision, 'editor_token' => accountAiAdminEditorToken()])->assertOk();
});

test('a stale disconnect cannot erase another managers newly tested connection', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $oldRevision = $record->proposed_revision;
    $newInput = accountAiAdminInput($oldRevision, ['model' => 'new-reviewed-model', 'api_key' => 'new-reviewed-key']);
    $this->postJson(route('dashboard.account.ai-assist.test'), $newInput)->assertOk();
    $newRevision = $record->fresh()->proposed_revision;
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($newRevision, ['model' => 'new-reviewed-model', 'api_key' => '']))->assertOk();
    $this->postJson(route('dashboard.account.ai-assist.disconnect'), ['expected_revision' => $oldRevision, 'editor_token' => accountAiAdminEditorToken()])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    expect($record->fresh()->enabled)->toBeTrue()
        ->and($record->fresh()->active_revision)->toBe($newRevision, 'A stale disconnect must not erase a newer active connection.')
        ->and($record->fresh()->activeConfiguration()['api_key'])->toBe('new-reviewed-key')
        ->and($record->fresh()->proposedConfiguration()['api_key'])->toBe('new-reviewed-key');
});

test('a null proposal cannot revive an old editor after another connection was activated and its draft discarded', function (): void {
    $owner = accountAiAdminOwner();
    $oldToken = accountAiAdminEditorToken($owner);
    $record = accountAiAdminTestAndActivate($owner);
    $activeRevision = $record->active_revision;
    $this->postJson(route('dashboard.account.ai-assist.discard'), ['expected_revision' => $record->proposed_revision, 'editor_token' => accountAiAdminEditorToken()])->assertOk();
    expect($record->fresh()->proposed_revision)->toBeNull();
    $this->postJson(route('dashboard.account.ai-assist.propose'), accountAiAdminInput(null, ['editor_token' => $oldToken, 'model' => 'stale-null-editor']))
        ->assertUnprocessable()->assertJsonValidationErrors('editor_token');
    $this->postJson(route('dashboard.account.ai-assist.disconnect'), ['expected_revision' => null, 'editor_token' => $oldToken])
        ->assertUnprocessable()->assertJsonValidationErrors('editor_token');
    expect($record->fresh()->active_revision)->toBe($activeRevision, 'A recurring null proposal must not authorize an old screen against a new active connection.')
        ->and($record->fresh()->proposedConfiguration())->toBeNull();
});

test('a stale screen cannot re-enable a replacement processor but can still pause processing', function (): void {
    $owner = accountAiAdminOwner();
    $record = accountAiAdminTestAndActivate($owner);
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 0])->assertOk();
    $oldToken = accountAiAdminEditorToken();
    $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput($record->proposed_revision, ['model' => 'replacement-model', 'api_key' => '']))->assertOk();
    $newRevision = $record->fresh()->proposed_revision;
    $this->postJson(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($newRevision, ['model' => 'replacement-model', 'api_key' => '']))->assertOk();
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 1, 'editor_token' => $oldToken])
        ->assertUnprocessable()->assertJsonValidationErrors('editor_token');
    expect($record->fresh()->enabled)->toBeFalse('A stale Enable button cannot resume a different processor.');
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 0, 'editor_token' => $oldToken])->assertOk();
    $this->postJson(route('dashboard.account.ai-assist.enabled'), ['enabled' => 1, 'editor_token' => accountAiAdminEditorToken()])->assertOk();
});

test('disconnect retires a null legacy editor token even before any account processor was selected', function (): void {
    $owner = accountAiAdminOwner();
    $oldToken = accountAiAdminEditorToken($owner);
    app(AccountAiConnections::class)->disconnect($owner, (int) $owner->account_id);
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, ['source' => 'installation', 'editor_token' => $oldToken]))
        ->assertUnprocessable()->assertJsonValidationErrors('editor_token');
    expect(app(AccountAiConnectionProbe::class)->calls)->toBe(0)
        ->and(AccountAiConnection::query()->sole()->uses_installation_default)->toBeFalse();
});

test('duplicate connection tests and mid-test management revocation do not attest success', function (): void {
    $owner = accountAiAdminOwner();
    app(AccountAiConnectionProbe::class)->during = function () use ($owner): void {
        $revision = AccountAiConnection::query()->sole()->proposed_revision;
        $this->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput($revision, ['api_key' => '']))->assertUnprocessable();
        $owner->forceFill(['account_role' => AccountRole::Agent])->save();
    };
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertForbidden();
    expect(app(AccountAiConnectionProbe::class)->calls)->toBe(1)
        ->and(AccountAiConnection::query()->sole()->tested_revision)->toBeNull();
});

test('provider failures and corrupt failure metadata expose only a safe reason', function (): void {
    $owner = accountAiAdminOwner();
    app(AccountAiConnectionProbe::class)->during = fn () => throw new AiNetworkException('key_rejected');
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())
        ->assertOk()->assertJsonPath('assist.state', 'unavailable')->assertJsonPath('assist.test.failure_code', 'key_rejected');
    $record = AccountAiConnection::query()->sole();
    $record->forceFill(['test_failure_code' => 'raw-private-provider-marker'])->save();
    expect(app(AccountAiConnectionPresenter::class)->present($owner)['test']['failure_code'])->toBe('provider_unavailable');
});

function accountAiAdminRenderedFields(TestResponse $response): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());

    return new DOMXPath($document);
}

test('correcting a failed cosmetic name can save the same tested draft without another probe', function (array $normalizedChanges): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $revision = $record->proposed_revision;
    $token = accountAiAdminEditorToken();
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($revision, [
        'name' => str_repeat('n', 101), 'api_key' => '', ...$normalizedChanges,
    ]))->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('name');
    expect($record->fresh()->proposed_revision)->toBe($revision)
        ->and($record->fresh()->tested_revision)->toBe($revision)
        ->and(session('_old_input._ai_assist_credential_edit'))->toBeFalse();
    $fields = accountAiAdminRenderedFields($this->get(route('dashboard.account.ai-assist.show'))->assertOk());
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('ready', 'A rejected cosmetic name must not invalidate a successful processing test.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeFalse()
        ->and($fields->query('//input[@name="name"]')->item(0)->getAttribute('value'))->toBe(str_repeat('n', 101))
        ->and($fields->query('//input[@name="expected_revision"]')->item(0)->getAttribute('value'))->toBe($revision)
        ->and($fields->query('//input[@name="editor_token"]')->item(0)->getAttribute('value'))->toBe($token)
        ->and($fields->query('//select[@name="provider"]/option[@selected]')->item(0)->getAttribute('value'))->toBe('openai-compatible');
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($revision, ['name' => 'Corrected name', 'api_key' => '']))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasNoErrors();
    expect($record->fresh()->active_revision)->toBe($revision)
        ->and($record->fresh()->name)->toBe('Corrected name')
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1, 'Name correction must not require a charged connection retest.');
})->with([
    'unchanged processing' => [[]],
    'provider normalization' => [['provider' => ' OPENAI-COMPATIBLE ']],
    'model normalization' => [['model' => ' support-model:exact ']],
    'endpoint normalization' => [['endpoint' => 'https://models.example.test/v1///']],
]);

test('a failed cosmetic name preserves normalized OpenRouter upstream test authority', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner);
    $input = accountAiAdminInput(null, ['provider' => 'openrouter', 'endpoint' => '', 'openrouter_provider' => 'openai']);
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), $input)->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->post(route('dashboard.account.ai-assist.activate'), [
        ...$input, 'expected_revision' => $record->proposed_revision, 'editor_token' => accountAiAdminEditorToken(),
        'name' => str_repeat('n', 101), 'openrouter_provider' => ' OPENAI ', 'api_key' => '',
    ])->assertSessionHasErrors('name');
    $fields = accountAiAdminRenderedFields($this->get(route('dashboard.account.ai-assist.show'))->assertOk());
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('ready', 'Normalized upstream spelling must retain the same processing revision.');
});

test('failed credential-only or clear edits retain a nonsecret stale fence', function (array $changes, string $error): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, $changes))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors($error);
    expect(session('_old_input'))->not->toHaveKey('api_key')
        ->and(session('_old_input._ai_assist_credential_edit'))->toBeTrue('Failed credential edits must retain only their boolean stale fence.');
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertDontSee('failed-secret-marker');
    $fields = accountAiAdminRenderedFields($response);
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('stale', 'A redacted credential edit must still withhold Save authority.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeTrue()
        ->and($fields->query('//input[@name="api_key"]')->item(0)->getAttribute('value'))->toBe('')
        ->and($record->fresh()->tested_revision)->toBe($record->proposed_revision)
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1);
})->with([
    'oversized replacement only' => [['name' => 'Support model', 'api_key' => 'failed-secret-marker'.str_repeat('k', 8192)], 'api_key'],
    'replacement with invalid name' => [['name' => str_repeat('n', 101), 'api_key' => 'failed-secret-marker'], 'name'],
    'clear with invalid name' => [['name' => str_repeat('n', 101), 'api_key' => '', 'clear_api_key' => 1], 'name'],
]);

test('failed cosmetic installation edits ignore irrelevant account processing fields and keys', function (): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput(null, ['source' => 'installation']))->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, [
        'source' => 'installation', 'name' => str_repeat('n', 101), 'provider' => 'ollama', 'model' => 'irrelevant-model',
        'endpoint' => 'https://irrelevant.example.test', 'api_key' => 'failed-secret-marker', 'clear_api_key' => 1,
    ]))->assertSessionHasErrors('name');
    expect(session('_old_input'))->not->toHaveKey('api_key')->not->toHaveKey('_ai_assist_credential_edit');
    $fields = accountAiAdminRenderedFields($this->get(route('dashboard.account.ai-assist.show'))->assertOk());
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('ready', 'Installation proposals ignore account-only processing values.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeFalse();
});

test('same processing fields cannot revive mismatched reviewed authority after name validation', function (array $authority): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $input = accountAiAdminInput($record->proposed_revision, ['name' => str_repeat('n', 101), 'api_key' => '', ...$authority]);
    $this->post(route('dashboard.account.ai-assist.activate'), $input)->assertSessionHasErrors('name');
    $old = session('_old_input');
    $fields = accountAiAdminRenderedFields($this->get(route('dashboard.account.ai-assist.show'))->assertOk());
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('stale', 'Unchanged fields cannot replace a mismatched reviewed revision or token.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeTrue()
        ->and($old)->toHaveKeys(['expected_revision', 'editor_token'])
        ->and($fields->query('//input[@name="expected_revision"]')->item(0)->getAttribute('value'))->toBe((string) $input['expected_revision'])
        ->and($fields->query('//input[@name="editor_token"]')->item(0)->getAttribute('value'))->toBe($input['editor_token']);
})->with([
    'meaningful null revision' => [['expected_revision' => null]],
    'mismatched editor token' => [['editor_token' => str_repeat('a', 64)]],
]);

test('failed name validation still withholds tests after actual processing changes', function (array $changes): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['name' => str_repeat('n', 101), 'api_key' => '', ...$changes]))
        ->assertSessionHasErrors('name');
    $fields = accountAiAdminRenderedFields($this->get(route('dashboard.account.ai-assist.show'))->assertOk());
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('stale', 'Processing edits must invalidate readiness even when name validation fails first.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeTrue();
})->with([
    'source' => [['source' => 'installation']],
    'provider' => [['provider' => 'gemini']],
    'model' => [['model' => 'different-model']],
    'endpoint' => [['endpoint' => 'https://different.example.test/v1']],
    'upstream' => [['openrouter_provider' => 'different-upstream']],
]);

test('redacted unsafe account endpoints withhold Save without preserving their secrets', function (string $endpoint): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->post(route('dashboard.account.ai-assist.activate'), accountAiAdminInput($record->proposed_revision, ['endpoint' => $endpoint, 'api_key' => '']))
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('endpoint');
    expect(session('_old_input'))->not->toHaveKey('endpoint')
        ->and(session('_old_input._ai_assist_redacted_endpoint_edit'))->toBeTrue('Redacted processing changes need a content-free stale fence.');
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertDontSee('redacted-endpoint-marker');
    $fields = accountAiAdminRenderedFields($response);
    expect($fields->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('stale', 'Redacting an unsafe endpoint must not restore Ready for the old endpoint.')
        ->and($fields->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeTrue()
        ->and($record->fresh()->active_revision)->toBeNull()
        ->and($record->fresh()->tested_revision)->toBe($record->proposed_revision)
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(1);
})->with([
    'https://user:redacted-endpoint-marker@models.example.test/v1',
    'https://models.example.test/v1?key=redacted-endpoint-marker',
    'https://models.example.test/v1#redacted-endpoint-marker',
]);

test('native model discovery redirects to one GET so refresh and history never repeat provider work', function (): void {
    $owner = accountAiAdminOwner();
    $response = $this->actingAs($owner)->post(route('dashboard.account.ai-assist.discover'), accountAiAdminInput(null, ['model' => '']));
    $response->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHas('status', 'ai_assist.flash.discovery_finished');
    $record = AccountAiConnection::query()->sole();
    $receipt = session('ai_assist_discovery_models');
    expect($receipt['account_id'])->toBe((int) $owner->account_id)
        ->and($receipt['proposed_revision'])->toBe($record->proposed_revision)
        ->and($receipt['editor_token'])->toBe(accountAiAdminEditorToken())
        ->and($receipt['models'])->toBe(['support-model:exact', 'support-model/version-2']);
    $this->get($response->headers->get('Location'))->assertOk()
        ->assertViewHas('assist', fn (array $assist): bool => $assist['models'] === ['support-model:exact', 'support-model/version-2'])
        ->assertSee('value="support-model:exact"', false);
    // Both a refresh and a restored-page reload target this GET, not /discover.
    $refreshed = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    expect($refreshed->viewData('assist')['models'])->toBe([], 'Discovery suggestions must be consumed once instead of replayed from session history.');
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    expect(app(AiModelDiscovery::class)->calls)->toBe(1, 'Reloading discovery results must not consume another external request.')
        ->and(app(AccountAiConnectionProbe::class)->calls)->toBe(0)
        ->and($record->fresh()->proposedConfiguration()['model'])->toBe('')
        ->and(session()->has('ai_assist_discovery_models'))->toBeFalse();
});

test('redirected suggestions are withdrawn when the proposal or reviewed connection scope changes', function (string $change): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.discover'), accountAiAdminInput())->assertRedirect();
    $record = AccountAiConnection::query()->sole();
    if ($change === 'proposal') {
        $input = accountAiAdminInput($record->proposed_revision, ['model' => 'new-reviewed-model', 'api_key' => '']);
        app(AccountAiConnections::class)->proposeForRevision($owner, (int) $owner->account_id, $input, $record->proposed_revision, $input['editor_token']);
    } else {
        app(AccountAiConnections::class)->setEnabled($owner, (int) $owner->account_id, false);
    }
    expect(session('ai_assist_discovery_models'))->not->toBeNull('The old receipt must still be present so its binding is actually exercised.');
    $refreshed = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    expect($refreshed->viewData('assist')['models'])->toBe([], 'Suggestions from an obsolete proposal or reviewed connection scope must be withdrawn.')
        ->and(app(AiModelDiscovery::class)->calls)->toBe(1);
})->with(['proposal', 'enablement']);

test('discovery flash receipts remain bound to account and proposal independently', function (string $field): void {
    $owner = accountAiAdminOwner();
    $this->actingAs($owner)->postJson(route('dashboard.account.ai-assist.test'), accountAiAdminInput())->assertOk();
    $record = AccountAiConnection::query()->sole();
    $this->withSession(['ai_assist_discovery_models' => [
        'account_id' => (int) $owner->account_id, 'proposed_revision' => $record->proposed_revision,
        'editor_token' => accountAiAdminEditorToken(), 'models' => ['foreign-catalogue-marker'],
        $field => $field === 'account_id' ? (int) Account::factory()->create()->id : (string) Str::uuid(),
    ]])->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertDontSee('foreign-catalogue-marker')
        ->assertViewHas('assist', fn (array $assist): bool => $assist['models'] === []);
})->with(['account_id', 'proposed_revision']);

test('browser discovery flash carries only bounded valid model identifiers', function (): void {
    $owner = accountAiAdminOwner();
    app()->instance(AiModelDiscovery::class, new class implements AiModelDiscovery
    {
        public function discover(ResolvedAgentCopilotConnection $connection, ?AiExecutionContext $context = null): ?array
        {
            return [str_repeat('x', 256), 'invalid model with spaces', ...array_map(fn (int $id): string => 'valid-model-'.$id, range(1, 150))];
        }
    });
    $this->actingAs($owner)->post(route('dashboard.account.ai-assist.discover'), accountAiAdminInput())->assertRedirect();
    $models = session('ai_assist_discovery_models.models');
    expect($models)->toHaveCount(98, 'Only the first bounded hundred catalogue entries may reach session storage.')
        ->and($models[0])->toBe('valid-model-1')->and(end($models))->toBe('valid-model-98')
        ->and(json_encode(session('ai_assist_discovery_models')))->not->toContain('account-key-never-in-browser');
    $this->get(route('dashboard.account.ai-assist.show'))->assertOk()->assertViewHas('assist', fn (array $assist): bool => count($assist['models']) === 98);
});

test('a stale full limits form cannot restore another managers narrowed token cap', function (): void {
    $owner = accountAiAdminOwner();
    $manager = User::factory()->for($owner->account)->create(['account_role' => AccountRole::Owner]);
    $policy = app(AiUsagePolicy::class);
    $original = ['daily_requests' => 10, 'daily_tokens' => 1000, 'concurrency' => 1, 'max_output_tokens' => 64];
    $policy->setAccountLimits($owner, (int) $owner->account_id, $original);
    $oldToken = $policy->accountSettings((int) $owner->account_id)['limits_token'];
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_tokens' => 100]);
    $response = $this->actingAs($manager)->putJson(route('dashboard.account.ai-assist.limits'), [
        ...$original, 'concurrency' => 2, 'limits_token' => $oldToken,
    ]);
    $this->assertSame(422, $response->getStatusCode(), 'A stale full limits form must not restore another manager’s narrowed token cap.');
    $response->assertJsonValidationErrors('limits_token');
    expect($policy->accountSettings((int) $owner->account_id)['overrides'])->toBe([...$original, 'daily_tokens' => 100]);
});

function accountAiAdminLimitsToken(User $actor): string
{
    return app(AiUsagePolicy::class)->accountSettings((int) $actor->account_id)['limits_token'];
}

test('identical reviewed limits forms remain valid and trusted partial patches retain compatibility', function (): void {
    $owner = accountAiAdminOwner();
    $values = ['daily_requests' => 10, 'daily_tokens' => 1000, 'concurrency' => 1, 'max_output_tokens' => 64];
    $policy = app(AiUsagePolicy::class);
    $policy->setAccountLimits($owner, (int) $owner->account_id, $values);
    $token = accountAiAdminLimitsToken($owner);
    $this->actingAs($owner)->putJson(route('dashboard.account.ai-assist.limits'), [...$values, 'limits_token' => $token])
        ->assertOk()->assertJsonPath('assist.limits.limits_token', $token);
    $this->putJson(route('dashboard.account.ai-assist.limits'), [...$values, 'limits_token' => $token])->assertOk();
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['concurrency' => 0]);
    expect($policy->accountSettings((int) $owner->account_id)['overrides'])->toBe([...$values, 'concurrency' => 0]);
});

test('failed limits tokens never remint authority or dirty the independent tested connection', function (mixed $token, bool $present): void {
    $owner = accountAiAdminOwner();
    accountAiAdminTestAndActivate($owner);
    $values = ['daily_requests' => 10, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null];
    $this->put(route('dashboard.account.ai-assist.limits'), [...$values, ...($present ? ['limits_token' => $token] : [])])
        ->assertRedirect(route('dashboard.account.ai-assist.show'))->assertSessionHasErrors('limits_token');
    $oldToken = is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1 ? $token : '';
    expect(session('_old_input'))->toHaveKey('limits_token')
        ->and(session('_old_input.limits_token'))->toBe($oldToken, 'Failed limits authority must remain exact or explicitly empty on redirect.');
    $response = $this->get(route('dashboard.account.ai-assist.show'))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//input[@name="limits_token"]')->item(0)->getAttribute('value'))->toBe($oldToken, 'A failed native limits form must never receive fresh authority.')
        ->and($xpath->query('//*[@data-ai-test-status]')->item(0)->getAttribute('data-state'))->toBe('ready')
        ->and($xpath->query('//button[@data-ai-action="activate"]')->item(0)->hasAttribute('disabled'))->toBeFalse()
        ->and($xpath->query('//input[@name="endpoint"]')->item(0)->getAttribute('value'))->toBe('https://models.example.test/v1');
    $this->putJson(route('dashboard.account.ai-assist.limits'), [...$values, 'limits_token' => $oldToken])
        ->assertUnprocessable()->assertJsonValidationErrors('limits_token');
    expect(DB::table('account_ai_usage_limits')->where('account_id', $owner->account_id)->exists())->toBeFalse();
})->with([[null, false], [null, true], ['', true], ['invalid-token', true], [['malformed'], true], [str_repeat('a', 64), true]]);

test('a limits form token cannot be replayed across accounts', function (): void {
    $owner = accountAiAdminOwner();
    $foreign = accountAiAdminOwner();
    $this->actingAs($owner)->putJson(route('dashboard.account.ai-assist.limits'), [
        'daily_requests' => 10, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null,
        'limits_token' => accountAiAdminLimitsToken($foreign), 'account_id' => $foreign->account_id,
    ])->assertUnprocessable()->assertJsonValidationErrors('limits_token');
    expect(DB::table('account_ai_usage_limits')->count())->toBe(0);
});
