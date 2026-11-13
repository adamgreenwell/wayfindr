<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeContext;
use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use App\Support\Updates\InstallationCapabilities;
use App\Support\Updates\ManagedUpdateGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function updateConsoleCapabilities(): InstallationCapabilities
{
    return InstallationCapabilities::authenticatedHelper([
        'ownership' => 'installer-managed',
        'installation_id' => '1567a42e-bcc8-4bf9-8a57-6a48d107aefe',
        'enrolled' => true,
        'helper' => ['protocol' => 1, 'version' => '0.4.0', 'capabilities' => ['plan', 'status', 'start', 'history', 'cancel']],
    ], 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:1.2.0@sha256:'.str_repeat('a', 64));
}

/** Real public-catalog parsing and local planning, without external network access. */
function fakeUpdateConsoleCandidate(bool $blocked = false): void
{
    $commit = str_repeat('b', 40);
    $api = 'https://api.github.com/repos/adamgreenwell/wayfindr';
    $releases = 'https://github.com/adamgreenwell/wayfindr/releases';
    $manifestUrl = $releases.'/download/v1.3.0/release-manifest.json';
    $digestUrl = $releases.'/download/v1.3.0/release-image-digest.txt';
    $target = ReleaseManifest::build([
        'minimum_upgrade_from' => null,
        'actions' => $blocked ? [[
            'id' => 'required-work', 'summary' => 'Complete required release work.',
            'detail' => 'Follow the declared release instructions.', 'phase' => 'before-pull',
            'depends_on_release' => 'none', 'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ]] : [],
        'notices' => [[
            'id' => 'read-the-notes', 'summary' => 'Review the published release notes.',
            'detail' => 'This is an advisory notice.',
            'applicability' => ['type' => 'always'], 'verification' => ['type' => 'attest'],
        ]],
    ], '1.3.0', $commit);
    $historyTarget = $target;
    $historyTarget['commit'] = '';
    $manifestBody = json_encode($target, JSON_THROW_ON_ERROR);
    $digestBody = 'sha256:'.str_repeat('c', 64)."\n";
    $release = [
        'id' => 123, 'tag_name' => 'v1.3.0', 'draft' => false, 'prerelease' => false,
        'html_url' => $releases.'/tag/v1.3.0', 'body' => 'Published release notes for the operator to review.',
        'assets' => [
            ['name' => 'release-manifest.json', 'state' => 'uploaded', 'browser_download_url' => $manifestUrl, 'digest' => 'sha256:'.hash('sha256', $manifestBody)],
            ['name' => 'release-image-digest.txt', 'state' => 'uploaded', 'browser_download_url' => $digestUrl, 'digest' => 'sha256:'.hash('sha256', $digestBody)],
        ],
    ];

    Http::fake([
        $api.'/releases/latest' => Http::response($release),
        $api.'/git/ref/tags/v1.3.0' => Http::response(['ref' => 'refs/tags/v1.3.0', 'object' => ['type' => 'commit', 'sha' => $commit]]),
        $manifestUrl => Http::response($manifestBody),
        $digestUrl => Http::response($digestBody),
        'https://raw.githubusercontent.com/adamgreenwell/wayfindr/'.$commit.'/releases/history.json' => Http::response([
            'schema' => ReleaseManifest::SCHEMA,
            'releases' => [
                ReleaseManifest::build(['actions' => []], '0.1.0', ''),
                ReleaseManifest::build(['actions' => []], '1.2.0', ''),
                $historyTarget,
            ],
        ]),
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->consoleOriginalStorage = app()->storagePath();
    $this->consoleStorage = sys_get_temp_dir().'/wayfindr-update-console-'.bin2hex(random_bytes(8));
    mkdir($this->consoleStorage.'/framework', 0700, true);
    mkdir($this->consoleStorage.'/app', 0700, true);
    app()->useStoragePath($this->consoleStorage);
    config()->set([
        'wayfindr.release.version' => '1.2.0', 'wayfindr.release.commit' => str_repeat('a', 40),
        'wayfindr.release.installation_profile' => 'image',
        'wayfindr.release.state_path' => $this->consoleStorage.'/release-state.json',
        'wayfindr.release.manifest_path' => $this->consoleStorage.'/release-manifest.json',
        'wayfindr.release.history_path' => $this->consoleStorage.'/release-history.json',
        'wayfindr.release.upgrade_from' => null, 'wayfindr.release.acknowledged_actions' => null,
        'wayfindr.updates.helper_enabled' => true,
        'wayfindr.updates.installation_ownership' => 'installer-managed',
        'wayfindr.updates.installation_id' => '1567a42e-bcc8-4bf9-8a57-6a48d107aefe',
        'wayfindr.updates.helper_token_path' => '/private/never-display-helper-token',
        'wayfindr.updates.helper_socket' => '/private/never-display-helper-socket',
    ]);
    file_put_contents(config('wayfindr.release.state_path'), json_encode([
        'version' => '1.2.0', 'commit' => str_repeat('a', 40), 'satisfied_through' => '1.2.0',
        'installation_profile' => 'image', 'fresh_install' => false,
    ], JSON_THROW_ON_ERROR)."\n");
    app()->instance(UpgradeContext::class, new UpgradeContext);
    $this->consoleOperator = User::factory()->for(Account::factory())->create(['platform_role' => PlatformRole::Operator]);
    $this->consoleHelper = Mockery::mock(HostUpdaterClient::class);
    $this->consoleHelper->shouldNotReceive('prepare', 'start', 'cancel', 'status', 'history', 'logs');
    app()->instance(HostUpdaterClient::class, $this->consoleHelper);
});

afterEach(function (): void {
    app()->useStoragePath($this->consoleOriginalStorage);
    File::deleteDirectory($this->consoleStorage);
});

test('the update page returns guests to login and candidate JSON refuses them', function (): void {
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->get(route('operator.updates.index'))->assertRedirect(route('login'))->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/operator/updates/candidate')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
});

test('tenant roles cannot open the update page or review its candidate', function (AccountRole $role): void {
    $tenant = User::factory()->for(Account::factory())->create(['account_role' => $role]);
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($tenant)->get(route('operator.updates.index'))->assertForbidden();
    $this->getJson(route('operator.updates.candidate'))->assertForbidden();
    Http::assertNothingSent();
})->with([AccountRole::Owner, AccountRole::Admin, AccountRole::Agent]);

test('the update console obeys account MFA enrollment policy', function (): void {
    $this->consoleOperator->account->update(['requires_two_factor' => true]);
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->get(route('operator.updates.index'))->assertRedirect(route('dashboard.profile.show'));
    Http::assertNothingSent();
});

test('the update console rechecks revoked operator authority before rendering bootstrap', function (): void {
    $this->actingAs($this->consoleOperator);
    User::query()->whereKey($this->consoleOperator->id)->update(['platform_role' => null]);
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->get(route('operator.updates.index'))->assertForbidden()->assertJsonPath('reason', 'operator_required');
    Http::assertNothingSent();
});

test('the update page bootstraps only local release facts and fixed authenticated routes', function (): void {
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->get(route('operator.updates.index'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertViewIs('operator.updates')
        ->assertViewHas('updateBootstrap', function (array $bootstrap): bool {
            expect(array_keys($bootstrap))->toBe(['schema', 'actor_id', 'current', 'two_factor_required', 'urls'])
                ->and($bootstrap['schema'])->toBe(1)
                ->and($bootstrap['actor_id'])->toBe((int) $this->consoleOperator->id)
                ->and($bootstrap['current'])->toBe(['version' => '1.2.0', 'commit' => str_repeat('a', 40), 'runtime_profile' => 'image'])
                ->and($bootstrap['two_factor_required'])->toBeFalse();
            foreach (['capabilities', 'candidate', 'reauthenticate', 'plan', 'recheck', 'status', 'history'] as $name) {
                expect($bootstrap['urls'][$name])->toBe(route('operator.updates.'.$name));
            }
            foreach (['review', 'start', 'cancel', 'events'] as $name) {
                expect($bootstrap['urls'][$name])->toBe(route('operator.updates.'.$name, ['operation' => '__OPERATION__']));
            }

            return true;
        })
        ->assertDontSee('/private/never-display-helper-token')->assertDontSee('/private/never-display-helper-socket')
        ->assertDontSee($this->consoleOperator->getAuthPassword())->assertDontSee('two_factor_secret');
    Http::assertNothingSent();
});

test('the operator sidebar reaches the update console and marks it as the current section', function (): void {
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->get(route('operator.updates.index'))->assertOk()
        ->assertSee('wf-crumb-current">Updates', false)
        ->assertSeeInOrder(['class="wf-context-link"', route('operator.updates.index'), 'aria-current="page"'], false);
});

test('the console requests a fresh MFA code for an enrolled operator without bootstrapping MFA material', function (): void {
    $this->consoleOperator->forceFill([
        'two_factor_secret' => 'private-never-display-mfa-secret',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['private-never-display-recovery-code'],
    ])->save();
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->get(route('operator.updates.index'))->assertOk()
        ->assertViewHas('updateBootstrap', fn (array $bootstrap): bool => $bootstrap['two_factor_required'] === true)
        ->assertSee('name="one_time_code"', false)
        ->assertDontSee('private-never-display-mfa-secret')->assertDontSee('private-never-display-recovery-code');
    Http::assertNothingSent();
});

test('candidate reads latest stable notes requirements and identity without preparation or writes', function (bool $blocked): void {
    fakeUpdateConsoleCandidate($blocked);
    $before = file_get_contents(config('wayfindr.release.state_path'));
    $this->consoleHelper->shouldReceive('capabilities')->once()->andReturn(updateConsoleCapabilities());
    $response = $this->actingAs($this->consoleOperator)->getJson(route('operator.updates.candidate'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('schema', 1)
        ->assertJsonPath('review.target.tag', 'v1.3.0')->assertJsonPath('review.target.commit', str_repeat('b', 40))
        ->assertJsonPath('review.target.image_digest', 'sha256:'.str_repeat('c', 64))
        ->assertJsonPath('review.target.release_notes', 'Published release notes for the operator to review.')
        ->assertJsonPath('review.release_requirements.migration_blocked', $blocked)
        ->assertJsonPath('review.advisory_notices.0.summary', 'Review the published release notes.')
        ->assertJsonPath('review.managed.execution_available', false)
        ->assertJsonPath('managed_execution_available', true);
    if ($blocked) {
        $response->assertJsonPath('review.status', 'blocked')->assertJsonPath('review.release_requirements.actions.0.id', 'required-work');
    } else {
        $response->assertJsonPath('review.status', 'update_available');
    }
    expect($response->json('review.managed.blockers'))->toContain('target_platform_unverified')
        ->and(file_get_contents(config('wayfindr.release.state_path')))->toBe($before)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(is_file(config('wayfindr.release.manifest_path')))->toBeFalse();
    Http::assertSentCount(5);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/releases/latest'));
    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/releases/tags/'));
})->with([false, true]);

test('candidate retains a full manual review for externally owned installations', function (string $ownership): void {
    fakeUpdateConsoleCandidate();
    config()->set('wayfindr.updates.installation_ownership', $ownership);
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->getJson(route('operator.updates.candidate'))->assertOk()
        ->assertJsonPath('managed_execution_available', false)->assertJsonPath('reason', 'installation_ineligible')
        ->assertJsonPath('review.installation.ownership', $ownership)->assertJsonPath('review.target.tag', 'v1.3.0')
        ->assertJsonPath('review.managed.eligible', false);
    expect(AuditEvent::query()->count())->toBe(0);
})->with(['external-docker', 'source-build', 'host-php', 'deployment-platform', 'hosting-managed']);

test('candidate retains manual review when the helper is unavailable', function (): void {
    fakeUpdateConsoleCandidate();
    $this->consoleHelper->shouldReceive('capabilities')->once()->andThrow(new HostUpdaterException('helper_unavailable', 'Private transport error.'));
    $this->actingAs($this->consoleOperator)->getJson(route('operator.updates.candidate'))->assertOk()
        ->assertJsonPath('managed_execution_available', false)->assertJsonPath('reason', 'helper_unavailable')
        ->assertJsonPath('review.target.tag', 'v1.3.0')->assertJsonPath('review.installation.helper.authenticated', false)
        ->assertDontSee('Private transport error.');
});

test('candidate metadata failure keeps controls unavailable with a safe reason', function (int $status, string $reason): void {
    $this->consoleHelper->shouldReceive('capabilities')->andReturn(updateConsoleCapabilities());
    Http::fake(['https://api.github.com/repos/adamgreenwell/wayfindr/releases/latest' => Http::response('Private provider response.', $status)]);
    $this->actingAs($this->consoleOperator)->get(route('operator.updates.candidate'))->assertStatus(503)
        ->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('reason', $reason)->assertJsonPath('managed_execution_available', false)
        ->assertDontSee('Private provider response.');
    Http::assertSentCount(1);
})->with([[503, 'metadata_unavailable'], [404, 'metadata_missing'], [429, 'metadata_rate_limited']]);

test('candidate rejects caller selected targets and arbitrary metadata sources', function (string $query): void {
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->getJson(route('operator.updates.candidate').'?'.$query)->assertUnprocessable();
    Http::assertNothingSent();
})->with(['release_tag=v1.3.0', 'url=https%3A%2F%2Funtrusted.example', 'force=true']);

test('managed maintenance keeps both the update page and candidate behind the global hold', function (string $name): void {
    app(ManagedUpdateGate::class)->enter('11111111-2222-4333-8444-555555555555');
    $this->consoleHelper->shouldNotReceive('capabilities');
    $this->actingAs($this->consoleOperator)->get(route($name))->assertStatus(503);
    Http::assertNothingSent();
})->with(['operator.updates.index', 'operator.updates.candidate']);
