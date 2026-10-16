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
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
    ])->assertOk()->assertJsonPath('assist.limits.effective.daily_requests', 10)
        ->assertJsonPath('assist.limits.effective.daily_tokens', 50000)->assertJsonPath('assist.limits.effective.concurrency', 0);
    expect(AccountAiConnection::query()->count())->toBe(0);
    $this->putJson(route('dashboard.account.ai-assist.limits'), [
        'daily_requests' => 101, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null,
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
    $limits = ['daily_requests' => null, 'daily_tokens' => null, 'concurrency' => null, 'max_output_tokens' => null, $limitField => $invalidValue];
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
        $field => ['unexpected-array'],
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
