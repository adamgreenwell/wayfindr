<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthentication;
use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use App\Support\Updates\InstallationCapabilities;
use App\Support\Updates\ManagedUpdateGate;
use App\Support\Updates\OperatorUpdateAuthorization;
use App\Support\Updates\OperatorUpdatePlanReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/** Exercise the real web CSRF pipeline instead of Laravel's test shortcut. */
class OperatorUpdateCsrfFixture extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

function operatorUpdateCapabilities(array $overrides = []): InstallationCapabilities
{
    return InstallationCapabilities::authenticatedHelper(array_replace_recursive([
        'ownership' => 'installer-managed',
        'installation_id' => '1567a42e-bcc8-4bf9-8a57-6a48d107aefe',
        'enrolled' => true, 'platform' => 'linux', 'architecture' => 'amd64',
        'image_reference' => 'ghcr.io/adamgreenwell/wayfindr:1.2.0@sha256:'.str_repeat('a', 64),
        'helper' => ['protocol' => 1, 'version' => '0.4.0', 'capabilities' => ['plan', 'status', 'start', 'history', 'cancel']],
    ], $overrides), 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.2.0@sha256:'.str_repeat('a', 64));
}

function operatorUpdatePost(string $path, array $payload = [])
{
    return test()->withSession(['_token' => str_repeat('f', 40)])
        ->postJson($path, $payload, ['X-CSRF-TOKEN' => str_repeat('f', 40)]);
}

function operatorUpdateReauthenticate(): void
{
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-update-password'])
        ->assertOk()->assertSessionHas(OperatorUpdateAuthorization::SESSION_KEY);
}

function operatorUpdateSnapshot(): array
{
    $test = test();
    $at = now()->timestamp;

    return [
        'schema' => 1, 'installation_id' => '1567a42e-bcc8-4bf9-8a57-6a48d107aefe',
        'revision' => 2, 'helper_version' => '0.4.0',
        'generation' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff', 'heartbeat_at' => $at, 'active_operation' => null,
        'operation' => [
            'operation_id' => $test->updateOperation, 'request_id' => $test->updateRequest,
            'release_tag' => 'v1.3.0', 'phase' => 'blocked', 'checkpoint' => 'plan_reported',
            'executor_generation' => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff', 'executor_version' => '0.4.0',
            'mutation_started' => false, 'created_at' => $at, 'updated_at' => $at, 'revision' => 2,
            'error' => 'execution_not_available',
            'source' => ['version' => '1.2.0', 'commit' => str_repeat('a', 40)],
            'target' => ['tag' => 'v1.3.0', 'version' => '1.3.0', 'commit' => str_repeat('b', 40), 'image_digest' => 'sha256:'.str_repeat('c', 64)],
            'plan_id' => $test->updatePlan,
            'events' => [
                ['revision' => 1, 'at' => $at, 'code' => 'operation_accepted', 'phase' => 'accepted'],
                ['revision' => 2, 'at' => $at, 'code' => 'plan_reported', 'phase' => 'blocked'],
            ],
            'operator' => [
                'prepare' => ['actor' => ['id' => (int) $test->updateOperator->id], 'at' => $at, 'revision' => 1],
                'start' => null, 'cancel' => null,
            ],
        ],
    ];
}

function operatorUpdateReview(array $overrides = []): array
{
    $review = array_replace_recursive([
        'schema' => 1, 'plan_id' => test()->updatePlan, 'status' => 'update_available',
        'release_requirements' => ['migration_blocked' => false],
    ], $overrides);
    $mock = Mockery::mock(OperatorUpdatePlanReview::class);
    $mock->shouldReceive('build')->with('v1.3.0', Mockery::type(InstallationCapabilities::class))->andReturn($review);
    app()->instance(OperatorUpdatePlanReview::class, $mock);

    return $review;
}

beforeEach(function (): void {
    $this->updateOriginalStorage = app()->storagePath();
    $this->updateStorage = sys_get_temp_dir().'/wayfindr-operator-updates-'.bin2hex(random_bytes(8));
    mkdir($this->updateStorage.'/framework', 0700, true);
    mkdir($this->updateStorage.'/app', 0700, true);
    app()->useStoragePath($this->updateStorage);
    app()->bind(PreventRequestForgery::class, OperatorUpdateCsrfFixture::class);
    config()->set('wayfindr.release.installation_profile', 'image');
    config()->set('wayfindr.updates.helper_enabled', true);
    config()->set('wayfindr.updates.installation_ownership', 'installer-managed');
    config()->set('wayfindr.updates.installation_id', '1567a42e-bcc8-4bf9-8a57-6a48d107aefe');
    $this->updateOperation = '11111111-2222-4333-8444-555555555555';
    $this->updateRequest = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $this->updatePlan = str_repeat('d', 64);
    $this->updateOperator = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Agent, 'platform_role' => PlatformRole::Operator,
        'password' => Hash::make('private-update-password'),
    ]);
    $this->updateHelper = Mockery::mock(HostUpdaterClient::class);
    $this->updateHelper->shouldReceive('capabilities')->andReturn(operatorUpdateCapabilities())->byDefault();
    app()->instance(HostUpdaterClient::class, $this->updateHelper);
});

afterEach(function (): void {
    app()->useStoragePath($this->updateOriginalStorage);
    File::deleteDirectory($this->updateStorage);
});

test('guest update requests cannot read helper state or dispatch an action', function (string $method, string $path): void {
    $this->updateHelper->shouldNotReceive('capabilities', 'status', 'history', 'logs', 'prepare', 'start', 'cancel');

    if ($method === 'get') {
        $this->getJson($path)->assertUnauthorized();
    } else {
        operatorUpdatePost($path)->assertUnauthorized();
    }
})->with([
    ['get', '/operator/updates'], ['get', '/operator/updates/status'], ['get', '/operator/updates/history'],
    ['post', '/operator/updates/reauthenticate'], ['post', '/operator/updates/plan'],
]);

test('tenant roles cannot inspect or mutate platform updates', function (AccountRole $role): void {
    $tenant = User::factory()->for(Account::factory())->create(['account_role' => $role]);
    $this->actingAs($tenant);
    $this->updateHelper->shouldNotReceive('capabilities', 'status', 'history', 'logs', 'prepare', 'start', 'cancel');

    $this->getJson('/operator/updates/status')->assertForbidden();
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'password'])->assertForbidden();
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])->assertForbidden();
})->with([AccountRole::Owner, AccountRole::Admin, AccountRole::Agent]);

test('a stale operator object cannot retain a revoked role or a deactivated login', function (string $change): void {
    $this->actingAs($this->updateOperator);
    User::query()->whereKey($this->updateOperator->id)->update($change === 'role'
        ? ['platform_role' => null] : ['deactivated_at' => now()]);
    $this->updateHelper->shouldNotReceive('capabilities', 'prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-update-password'])->assertForbidden();
})->with(['role', 'deactivated']);

test('CSRF verification runs for update actions with missing and wrong session tokens', function (?string $token): void {
    $this->actingAs($this->updateOperator)->withSession(['_token' => str_repeat('f', 40)]);
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');
    $this->postJson('/operator/updates/reauthenticate', ['current_password' => 'private-update-password'],
        $token === null ? [] : ['X-CSRF-TOKEN' => $token])->assertStatus(419);
    expect(session(OperatorUpdateAuthorization::SESSION_KEY))->toBeNull();
})->with([null, 'wrong-token']);

test('update mutation requires a recent reauthentication even for a signed-in operator', function (): void {
    $this->actingAs($this->updateOperator);
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(428)->assertJsonPath('reason', 'reauthentication_required');
});

test('wrong password never creates a reauthentication marker or exposes credentials', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password')
        ->assertDontSee('private-wrong-password')->assertSessionMissing(OperatorUpdateAuthorization::SESSION_KEY)
        ->assertSessionMissing('_old_input.current_password');
});

test('recent update reauthentication expires after five minutes', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $this->travel(301)->seconds();
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(428)->assertJsonPath('reason', 'reauthentication_required');
});

test('malformed future or boundary-expired reauthentication proof cannot authorize an update', function (string $case): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $proof = session(OperatorUpdateAuthorization::SESSION_KEY);
    match ($case) {
        'future' => $proof['at'] = now()->timestamp + 1,
        'boundary' => $proof['at'] = now()->timestamp - 300,
        'string-time' => $proof['at'] = (string) $proof['at'],
        'extra' => $proof['force'] = true,
        'fingerprint' => $proof['fingerprint'] = str_repeat('0', 64),
    };
    $this->withSession([OperatorUpdateAuthorization::SESSION_KEY => $proof]);
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(428)->assertJsonPath('reason', 'reauthentication_required')
        ->assertSessionMissing(OperatorUpdateAuthorization::SESSION_KEY);
})->with(['future', 'boundary', 'string-time', 'extra', 'fingerprint']);

test('reauthentication markers belong to one operator and one current MFA configuration', function (string $change): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();

    if ($change === 'user') {
        $other = User::factory()->for(Account::factory())->create([
            'platform_role' => PlatformRole::Operator, 'password' => $this->updateOperator->getAuthPassword(),
        ]);
        $this->actingAs($other);
    } else {
        $this->updateOperator->forceFill([
            'two_factor_secret' => app(TwoFactorAuthentication::class)->generateSecret(),
            'two_factor_confirmed_at' => now(), 'two_factor_last_used_timestep' => 0,
        ])->save();
    }
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(428)->assertJsonPath('reason', 'reauthentication_required');
})->with(['user', 'mfa']);

test('MFA-enrolled operators must present a fresh factor and cannot replay the same TOTP', function (): void {
    $secret = app(TwoFactorAuthentication::class)->generateSecret();
    $this->updateOperator->forceFill([
        'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
        'two_factor_last_used_timestep' => 0,
    ])->save();
    $this->actingAs($this->updateOperator);
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-update-password'])
        ->assertUnprocessable()->assertSessionMissing(OperatorUpdateAuthorization::SESSION_KEY);
    $code = app(Google2FA::class)->getCurrentOtp($secret);
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-update-password', 'one_time_code' => $code])
        ->assertOk()->assertSessionHas(OperatorUpdateAuthorization::SESSION_KEY)->assertDontSee($code, false)->assertDontSee($secret);
    expect($this->updateOperator->fresh()->two_factor_last_used_timestep)->toBeGreaterThan(0);
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-update-password', 'one_time_code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors('one_time_code')->assertDontSee($code, false);
});

test('changed password credentials invalidate a recent update proof even with a stale authenticated user', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    User::query()->whereKey($this->updateOperator->id)->update(['password' => Hash::make('replacement-private-password')]);
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(428)->assertJsonPath('reason', 'reauthentication_required');
});

test('fresh authority checks reject role revocation or deactivation after an earlier proof', function (string $change): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    User::query()->whereKey($this->updateOperator->id)->update($change === 'role'
        ? ['platform_role' => null] : ['deactivated_at' => now()]);
    $this->updateHelper->shouldNotReceive('capabilities', 'prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertForbidden()->assertJsonPath('reason', 'operator_required');
})->with(['role', 'deactivated']);

test('a stale loaded account cannot bypass a newly required two-factor policy', function (): void {
    $this->updateOperator->load('account');
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    Account::query()->whereKey($this->updateOperator->account_id)->update(['requires_two_factor' => true]);
    $this->updateHelper->shouldNotReceive('capabilities', 'prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertForbidden()->assertJsonPath('reason', 'two_factor_required');
});

test('a failed fresh password proof also revokes the earlier update proof', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    operatorUpdatePost('/operator/updates/reauthenticate', ['current_password' => 'private-wrong-password'])
        ->assertUnprocessable()->assertSessionMissing(OperatorUpdateAuthorization::SESSION_KEY);
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])->assertStatus(428);
});

test('reauthentication rejects malformed proof requests and never flashes a factor', function (array $payload): void {
    $this->actingAs($this->updateOperator);
    operatorUpdatePost('/operator/updates/reauthenticate', $payload)->assertUnprocessable()
        ->assertSessionMissing(OperatorUpdateAuthorization::SESSION_KEY)
        ->assertSessionMissing('_old_input.current_password')->assertSessionMissing('_old_input.one_time_code');
})->with([
    [['current_password' => ['private-secret']]],
    [['current_password' => 'private-update-password', 'one_time_code' => ['private-factor']]],
    [['current_password' => 'private-update-password', 'force' => true]],
]);

test('strict plan payloads refuse arbitrary executable input and noncanonical release selectors', function (array $changes): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/plan', array_replace(['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest], $changes))
        ->assertUnprocessable();
})->with([
    [['release_tag' => 'latest']], [['release_tag' => '1.3.0']], [['release_tag' => 'v01.3.0']],
    [['release_tag' => 'v1.3.0-beta.1']], [['release_tag' => 'v1.3.0+private']],
    [['release_tag' => ['v1.3.0']]], [['request_id' => 'short']], [['request_id' => strtoupper('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')]],
    [['command' => 'private-arbitrary-command']], [['image' => 'private-image']], [['path' => '/private/path']],
    [['force' => true]], [['actor' => ['id' => 123]]],
]);

test('helper absence or an external deployment owner cannot admit operator execution', function (string $case): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $this->updateHelper->shouldNotReceive('prepare', 'start', 'cancel');

    if ($case === 'disabled') {
        config()->set('wayfindr.updates.helper_enabled', false);
        $this->updateHelper->shouldNotReceive('capabilities');
    } elseif ($case === 'external') {
        config()->set('wayfindr.updates.installation_ownership', 'external-docker');
        $this->updateHelper->shouldNotReceive('capabilities');
    } elseif ($case === 'unavailable') {
        $this->updateHelper->shouldReceive('capabilities')->once()->andThrow(new HostUpdaterException('helper_unavailable'));
    } else {
        $this->updateHelper->shouldReceive('capabilities')->once()->andReturn(operatorUpdateCapabilities(['helper' => ['version' => '0.3.0']]));
    }
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
})->with(['disabled', 'external', 'unavailable', 'old-helper']);

test('plan and recheck send only the canonical target, idempotency key, and current operator to the helper', function (string $path): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $this->updateHelper->shouldReceive('prepare')->once()->with('v1.3.0', $this->updateRequest, (int) $this->updateOperator->id)->andReturn($snapshot);

    operatorUpdatePost($path, ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])->assertAccepted()
        ->assertJsonPath('snapshot.operation.operation_id', $this->updateOperation)->assertJsonPath('audit_mirrored', true)
        ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('private-update-password');
    expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(2);
})->with(['/operator/updates/plan', '/operator/updates/recheck']);

test('plan review binds fresh release evidence to the exact prepared journal plan', function (): void {
    $this->actingAs($this->updateOperator);
    $snapshot = operatorUpdateSnapshot();
    $review = operatorUpdateReview();
    $this->updateHelper->shouldReceive('status')->once()->with($this->updateOperation)->andReturn($snapshot);

    $this->getJson('/operator/updates/'.$this->updateOperation.'/review')->assertOk()
        ->assertJsonPath('review', $review)->assertJsonPath('execution_available', true)->assertJsonPath('audit_mirrored', true)
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('stale journal or rebuilt plan facts cannot be used to start', function (string $case): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $this->updateHelper->shouldReceive('status')->once()->with($this->updateOperation)->andReturn($snapshot);
    $this->updateHelper->shouldNotReceive('start');
    $plan = $case === 'submitted' ? str_repeat('e', 64) : $this->updatePlan;
    if ($case !== 'submitted') {
        operatorUpdateReview(match ($case) {
            'rebuilt' => ['plan_id' => str_repeat('e', 64)],
            'requirement' => ['release_requirements' => ['migration_blocked' => true]],
            'identity' => ['status' => 'identity_conflict'],
        });
    }

    operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/start', ['plan_id' => $plan, 'request_id' => $this->updateRequest])
        ->assertConflict()->assertJsonPath('reason', 'stale_plan');
})->with(['submitted', 'rebuilt', 'requirement', 'identity']);

test('a preparation that is not ready cannot be reviewed or started', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $snapshot['operation']['phase'] = 'preparing';
    $snapshot['operation']['checkpoint'] = 'prepare_started';
    $snapshot['operation']['error'] = null;
    $this->updateHelper->shouldReceive('status')->twice()->with($this->updateOperation)->andReturn($snapshot);
    $this->updateHelper->shouldNotReceive('start');
    $this->getJson('/operator/updates/'.$this->updateOperation.'/review')->assertConflict()->assertJsonPath('reason', 'plan_not_ready');
    operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/start', ['plan_id' => $this->updatePlan, 'request_id' => $this->updateRequest])
        ->assertConflict()->assertJsonPath('reason', 'plan_not_ready');
});

test('repeat start admissions use the durable receipt without rechecking a replaced source', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $ready = operatorUpdateSnapshot();
    $started = $ready;
    $started['active_operation'] = $this->updateOperation;
    $started['operation']['phase'] = 'downloading';
    $started['operation']['checkpoint'] = 'apply_started';
    $started['operation']['error'] = null;
    $started['operation']['operator']['start'] = [
        'request_id' => $this->updateRequest, 'plan_id' => $this->updatePlan,
        'actor' => ['id' => (int) $this->updateOperator->id], 'at' => now()->timestamp, 'revision' => 3,
    ];
    operatorUpdateReview();
    $this->updateHelper->shouldReceive('status')->twice()->with($this->updateOperation)->andReturn($ready, $started);
    $this->updateHelper->shouldReceive('start')->twice()->with($this->updateOperation, $this->updateRequest, $this->updatePlan, (int) $this->updateOperator->id)->andReturn($started);

    foreach (range(1, 2) as $attempt) {
        operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/start', ['plan_id' => $this->updatePlan, 'request_id' => $this->updateRequest])
            ->assertAccepted()->assertJsonPath('snapshot.operation.operation_id', $this->updateOperation);
    }
    expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(2);
});

test('the helper remains the cancellation authority and refuses schema-risk cancellation', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $snapshot['operation']['mutation_started'] = true;
    $this->updateHelper->shouldReceive('status')->once()->with($this->updateOperation)->andReturn($snapshot);
    $this->updateHelper->shouldReceive('cancel')->once()->with($this->updateOperation, $this->updateRequest, $this->updatePlan, (int) $this->updateOperator->id)
        ->andThrow(new HostUpdaterException('helper_refused:cancel_unavailable'));
    operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/cancel', ['plan_id' => $this->updatePlan, 'request_id' => $this->updateRequest])
        ->assertConflict()->assertJsonPath('reason', 'helper_refused:cancel_unavailable');
});

test('cancel forwards only the frozen plan identity and authenticated actor', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $this->updateHelper->shouldReceive('status')->once()->with($this->updateOperation)->andReturn($snapshot);
    $this->updateHelper->shouldReceive('cancel')->once()->with($this->updateOperation, $this->updateRequest, $this->updatePlan, (int) $this->updateOperator->id)->andReturn($snapshot);
    $this->updateHelper->shouldNotReceive('start');

    operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/cancel', ['plan_id' => $this->updatePlan, 'request_id' => $this->updateRequest])
        ->assertAccepted()->assertJsonPath('snapshot.operation.operation_id', $this->updateOperation);
});

test('helper idempotency conflicts surface without a new admission attempt', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $this->updateHelper->shouldReceive('prepare')->once()->with('v1.3.0', $this->updateRequest, (int) $this->updateOperator->id)
        ->andThrow(new HostUpdaterException('helper_refused:idempotency_conflict'));
    operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])
        ->assertConflict()->assertJsonPath('reason', 'helper_refused:idempotency_conflict');
    expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(0);
});

test('start and cancel payloads cannot choose an executable target or bypass the reviewed plan', function (string $action, array $changes): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $this->updateHelper->shouldNotReceive('status', 'start', 'cancel');

    operatorUpdatePost('/operator/updates/'.$this->updateOperation.'/'.$action,
        array_replace(['plan_id' => $this->updatePlan, 'request_id' => $this->updateRequest], $changes))->assertUnprocessable();
})->with(['start', 'cancel'])->with([
    [['plan_id' => 'short']], [['plan_id' => str_repeat('D', 64)]], [['request_id' => 'not-a-uuid']],
    [['force' => true]], [['image' => 'private-image']], [['command' => 'private-command']], [['release_tag' => 'v1.9.0']],
]);

test('status and helper history remain available after database audit writes fail without replaying admission', function (): void {
    $this->actingAs($this->updateOperator);
    operatorUpdateReauthenticate();
    $snapshot = operatorUpdateSnapshot();
    $history = [
        'schema' => 1, 'installation_id' => $snapshot['installation_id'], 'revision' => $snapshot['revision'], 'active_operation' => null,
        'cursor' => 0, 'next_cursor' => 1, 'has_more' => false, 'operations' => [$snapshot['operation']],
    ];
    $this->updateHelper->shouldReceive('prepare')->once()->with('v1.3.0', $this->updateRequest, (int) $this->updateOperator->id)->andReturn($snapshot);
    $this->updateHelper->shouldReceive('status')->once()->with($this->updateOperation)->andReturn($snapshot);
    $this->updateHelper->shouldReceive('history')->once()->with(0, 20)->andReturn($history);
    // A missing audit column produces a real SQL failure on both PostgreSQL
    // and SQLite, while user/session authority tables remain usable.
    Schema::table('audit_events', function (Blueprint $table): void {
        $table->dropUnique(['managed_update_event_key']);
        $table->dropColumn('managed_update_event_key');
    });

    try {
        operatorUpdatePost('/operator/updates/plan', ['release_tag' => 'v1.3.0', 'request_id' => $this->updateRequest])->assertAccepted()
            ->assertJsonPath('snapshot.operation.operation_id', $this->updateOperation)->assertJsonPath('audit_mirrored', false)
            ->assertDontSee('managed_update_event_key')->assertDontSee('SQLSTATE');
        $this->getJson('/operator/updates/status?operation_id='.$this->updateOperation)->assertOk()
            ->assertJsonPath('snapshot', $snapshot)->assertJsonPath('audit_mirrored', false)->assertDontSee('managed_update_event_key')->assertDontSee('SQLSTATE');
        $this->getJson('/operator/updates/history')->assertOk()
            ->assertJsonPath('snapshot', $history)->assertJsonPath('audit_mirrored', false)->assertDontSee('managed_update_event_key')->assertDontSee('SQLSTATE');
        expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(0);
    } finally {
        Schema::table('audit_events', fn (Blueprint $table) => $table->string('managed_update_event_key', 64)->nullable()->unique());
    }
});

test('status and history mirror safe host events once with no tenant ownership or secret metadata', function (): void {
    $this->actingAs($this->updateOperator);
    $snapshot = operatorUpdateSnapshot();
    $this->updateHelper->shouldReceive('status')->twice()->with(null)->andReturn($snapshot);
    foreach (range(1, 2) as $attempt) {
        $this->getJson('/operator/updates/status')->assertOk()->assertJsonPath('audit_mirrored', true);
    }
    $events = AuditEvent::query()->where('action', 'like', 'operator_update.%')->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->account_id)->toBeNull()->and($event->site_id)->toBeNull()
            ->and($event->actor_id)->toBe($this->updateOperator->id)
            ->and($event->metadata['operation_id'])->toBe($this->updateOperation)
            ->and(json_encode($event->metadata))->not->toContain('private-update-password', 'token', '/run/', 'APP_KEY', 'command');
    }
});

test('bounded status history and event parameters refuse unknown or excessive requests', function (string $path): void {
    $this->actingAs($this->updateOperator);
    $this->updateHelper->shouldNotReceive('status', 'history', 'logs');
    $this->getJson($path)->assertUnprocessable();
})->with([
    '/operator/updates/status?operation_id=not-an-operation', '/operator/updates/status?force=true',
    '/operator/updates/history?cursor=-1', '/operator/updates/history?limit=51', '/operator/updates/history?limit[]=20',
    '/operator/updates/11111111-2222-4333-8444-555555555555/events?limit=101',
    '/operator/updates/11111111-2222-4333-8444-555555555555/events?cursor=-1',
]);

test('operator event polling uses the requested bounded cursor without writing application audit records', function (): void {
    $this->actingAs($this->updateOperator);
    $events = ['operation_id' => $this->updateOperation, 'events' => [], 'next_cursor' => 20, 'has_more' => false];
    $this->updateHelper->shouldReceive('logs')->once()->with($this->updateOperation, 20, 100)->andReturn($events);
    $this->getJson('/operator/updates/'.$this->updateOperation.'/events?cursor=20&limit=100')->assertOk()
        ->assertJsonPath('events', $events)->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(0);
});

test('update errors remain JSON and uncached when the client omits Accept', function (string $case): void {
    if ($case === 'guest') {
        $response = $this->get('/operator/updates/status');
        $response->assertUnauthorized();
    } else {
        $this->actingAs($this->updateOperator);
        $response = $this->get('/operator/updates/status?operation_id=invalid');
        $response->assertUnprocessable();
    }

    $response->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private');
})->with(['guest', 'validation']);

test('managed maintenance blocks every operator update HTTP endpoint and never opens a write bypass', function (string $method, string $path): void {
    $this->actingAs($this->updateOperator);
    app(ManagedUpdateGate::class)->enter($this->updateOperation);
    $this->updateHelper->shouldNotReceive('capabilities', 'status', 'history', 'logs', 'prepare', 'start', 'cancel');
    $response = $method === 'get' ? $this->getJson($path) : operatorUpdatePost($path);
    $response->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
    expect(AuditEvent::query()->where('action', 'like', 'operator_update.%')->count())->toBe(0);
})->with([
    ['get', '/operator/updates/status'], ['get', '/operator/updates/history'],
    ['get', '/operator/updates/11111111-2222-4333-8444-555555555555/events'],
    ['post', '/operator/updates/reauthenticate'], ['post', '/operator/updates/plan'],
    ['post', '/operator/updates/11111111-2222-4333-8444-555555555555/start'],
    ['post', '/operator/updates/11111111-2222-4333-8444-555555555555/cancel'],
]);
