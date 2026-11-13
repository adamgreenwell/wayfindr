<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use App\Support\Updates\InstallationCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function updatePlanCommandHttpFixture(array $declaration = []): array
{
    $api = 'https://api.github.com/repos/adamgreenwell/wayfindr';
    $releases = 'https://github.com/adamgreenwell/wayfindr/releases';
    $commit = str_repeat('b', 40);
    $manifest = ReleaseManifest::build(array_replace(['minimum_upgrade_from' => null, 'actions' => []], $declaration), '0.2.0', $commit);
    $source = ReleaseManifest::build(['minimum_upgrade_from' => null, 'actions' => []], '0.1.0', str_repeat('a', 40));
    $manifestBody = json_encode($manifest, JSON_THROW_ON_ERROR)."\n";
    $digestBody = 'sha256:'.str_repeat('1', 64)."\n";
    $manifestUrl = $releases.'/download/v0.2.0/release-manifest.json';
    $digestUrl = $releases.'/download/v0.2.0/release-image-digest.txt';
    $release = [
        'id' => 200,
        'tag_name' => 'v0.2.0',
        'draft' => false,
        'prerelease' => false,
        'html_url' => $releases.'/tag/v0.2.0',
        'body' => 'Review the published update notes.',
        'assets' => [
            ['name' => 'release-manifest.json', 'state' => 'uploaded', 'browser_download_url' => $manifestUrl, 'digest' => 'sha256:'.hash('sha256', $manifestBody)],
            ['name' => 'release-image-digest.txt', 'state' => 'uploaded', 'browser_download_url' => $digestUrl, 'digest' => 'sha256:'.hash('sha256', $digestBody)],
        ],
    ];
    $committedTarget = $manifest;
    $committedTarget['commit'] = '';

    return [
        $api.'/releases/latest' => Http::response($release),
        $api.'/releases/tags/v0.2.0' => Http::response($release),
        $api.'/git/ref/tags/v0.2.0' => Http::response([
            'ref' => 'refs/tags/v0.2.0',
            'object' => ['type' => 'commit', 'sha' => $commit],
        ]),
        $manifestUrl => Http::response($manifestBody),
        $digestUrl => Http::response($digestBody),
        'https://raw.githubusercontent.com/adamgreenwell/wayfindr/'.$commit.'/releases/history.json' => Http::response(json_encode([
            'schema' => ReleaseManifest::SCHEMA,
            'releases' => [$source, $committedTarget],
        ], JSON_THROW_ON_ERROR)),
    ];
}

beforeEach(function (): void {
    $this->updatePlanCommandDirectory = sys_get_temp_dir().'/wayfindr-plan-command-'.bin2hex(random_bytes(6));
    mkdir($this->updatePlanCommandDirectory, 0700, true);
    config()->set([
        'wayfindr.release.state_path' => $this->updatePlanCommandDirectory.'/state.json',
        'wayfindr.release.manifest_path' => $this->updatePlanCommandDirectory.'/live-manifest.json',
        'wayfindr.release.history_path' => $this->updatePlanCommandDirectory.'/live-history.json',
        'wayfindr.release.installation_profile' => 'image',
        'wayfindr.release.version' => 'v0.1.0',
        'wayfindr.release.commit' => str_repeat('a', 40),
        'wayfindr.release.acknowledged_actions' => '0.1.0/prior-work',
        'wayfindr.release.upgrade_from' => null,
        'wayfindr.updates.installation_ownership' => 'installer-managed',
        'wayfindr.updates.installation_id' => 'command-test-installation',
        'wayfindr.updates.image_reference' => 'ghcr.io/adamgreenwell/wayfindr:0.1.0',
        'wayfindr.updates.helper_enabled' => false,
    ]);
    app()->instance(UpgradeContext::class, new UpgradeContext);
    $source = ReleaseManifest::build(['minimum_upgrade_from' => null, 'actions' => []], '0.1.0', str_repeat('a', 40));
    file_put_contents($this->updatePlanCommandDirectory.'/live-manifest.json', json_encode($source, JSON_THROW_ON_ERROR));
    file_put_contents($this->updatePlanCommandDirectory.'/live-history.json', json_encode(['schema' => 1, 'releases' => [$source]], JSON_THROW_ON_ERROR));
    file_put_contents($this->updatePlanCommandDirectory.'/state.json', json_encode([
        'version' => '0.1.0',
        'commit' => str_repeat('a', 40),
        'satisfied_through' => '0.1.0',
        'installation_profile' => 'image',
        'fresh_install' => false,
    ], JSON_THROW_ON_ERROR)."\n");
    Http::preventStrayRequests();
});

afterEach(function (): void {
    File::deleteDirectory($this->updatePlanCommandDirectory);
});

test('an enrolled CLI plan with plan and status only remains ineligible for operator execution', function (string $helperVersion): void {
    config()->set('wayfindr.updates.helper_enabled', true);
    $installation = InstallationCapabilities::authenticatedHelper([
        'ownership' => 'installer-managed',
        'installation_id' => 'command-test-installation',
        'enrolled' => true,
        'helper' => ['protocol' => 1, 'version' => $helperVersion, 'capabilities' => ['plan', 'status']],
        'managed_policy' => [],
    ], 'image', 'linux', 'amd64', 'ghcr.io/adamgreenwell/wayfindr:0.1.0');
    $client = Mockery::mock(HostUpdaterClient::class);
    $client->shouldReceive('capabilities')->once()->with('image')->andReturn($installation);
    app()->instance(HostUpdaterClient::class, $client);
    Http::fake(updatePlanCommandHttpFixture());

    $exit = Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['installation']['helper']['authenticated'])->toBeTrue()
        ->and($plan['installation']['enrolled'])->toBeTrue()
        ->and($plan['managed']['execution_available'])->toBeFalse()
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('helper_capability_missing:start', 'helper_capability_missing:history', 'helper_capability_missing:cancel')
        ->and($plan['managed']['blockers'])->not->toContain('helper_not_authenticated');

    if ($helperVersion !== InstallationCapabilities::MINIMUM_HELPER_VERSION) {
        expect($plan['managed']['blockers'])->toContain('helper_version_unsupported');
    }
})->with(['0.1.0', '0.3.0', InstallationCapabilities::MINIMUM_HELPER_VERSION]);

test('an enabled helper authentication failure refuses the CLI plan without falling back to claims', function (bool $json): void {
    config()->set('wayfindr.updates.helper_enabled', true);
    $client = Mockery::mock(HostUpdaterClient::class);
    $client->shouldReceive('capabilities')->once()->with('image')->andThrow(new HostUpdaterException('helper_authentication_failed'));
    app()->instance(HostUpdaterClient::class, $client);
    Http::fake();

    $exit = Artisan::call('wayfindr:update-plan', $json ? ['--json' => true] : []);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('helper_authentication_failed')
        ->and($output)->not->toContain('update_available', 'up_to_date', 'command-test-installation');
    Http::assertNothingSent();

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'schema' => 1, 'status' => 'failed', 'reason' => 'helper_authentication_failed',
        ]);
    }
})->with([true, false]);

test('configured helper authentication and enrollment bits cannot authenticate a disabled helper', function (): void {
    config()->set('wayfindr.updates.helper', [
        'authenticated' => true, 'protocol' => 1, 'version' => '0.1.0', 'capabilities' => ['plan', 'apply', 'status', 'recover'],
    ]);
    config()->set('wayfindr.updates.enrolled', true);
    $client = Mockery::mock(HostUpdaterClient::class);
    $client->shouldNotReceive('capabilities');
    app()->instance(HostUpdaterClient::class, $client);
    Http::fake(updatePlanCommandHttpFixture());

    $exit = Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['installation']['helper']['authenticated'])->toBeFalse()
        ->and($plan['installation']['enrolled'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('helper_not_authenticated', 'helper_not_enrolled')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['execution_available'])->toBeFalse();
});

test('the CLI emits a complete metadata review while preserving live release files configuration and acknowledgements', function (): void {
    Http::fake(updatePlanCommandHttpFixture());
    $configuration = config('wayfindr.release');
    $paths = [
        config('wayfindr.release.state_path'),
        config('wayfindr.release.manifest_path'),
        config('wayfindr.release.history_path'),
    ];
    $files = array_map(static fn (string $path): array => [file_get_contents($path), fileperms($path)], $paths);
    $context = app(UpgradeContext::class);
    $live = app(UpgradeGuard::class)->assess();

    $exit = Artisan::call('wayfindr:update-plan', ['--ref' => 'v0.2.0', '--json' => true]);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['status'])->toBe('update_available')
        ->and($plan['target']['tag'])->toBe('v0.2.0')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['execution_available'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('helper_not_authenticated', 'target_platform_unverified')
        ->and($plan['manual']['helper_required'])->toBeFalse()
        ->and($plan['manual']['new_backup_setup_required'])->toBeFalse()
        ->and(config('wayfindr.release'))->toBe($configuration)
        ->and($context->wasFreshInstall())->toBeNull()
        ->and(app(UpgradeGuard::class)->assess())->toBe($live);
    foreach ($paths as $index => $path) {
        expect([file_get_contents($path), fileperms($path)])->toBe($files[$index]);
    }
    Http::assertSentCount(5);
});

test('repeated CLI reviews of identical verified facts retain the same receipt', function (): void {
    Http::fake(updatePlanCommandHttpFixture());
    Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($second['plan_id'])->toBe($first['plan_id']);
});

test('metadata failure emits a sanitized failed JSON result and never up to date', function (int $status, string $reason): void {
    Http::fake(['https://api.github.com/repos/adamgreenwell/wayfindr/releases/latest' => Http::response([
        'message' => 'provider-private-token and internal response details',
    ], $status)]);

    $exit = Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $output = Artisan::output();
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result)->toBe(['schema' => 1, 'status' => 'failed', 'reason' => $reason])
        ->and($output)->not->toContain('provider-private-token', 'up_to_date', 'internal response details');
})->with([
    'missing release' => [404, 'metadata_missing'],
    'forbidden release' => [403, 'metadata_forbidden'],
    'rate limited release' => [429, 'metadata_rate_limited'],
    'provider unavailable' => [503, 'metadata_unavailable'],
]);

test('connection exceptions are sanitized in JSON and human-readable CLI output', function (bool $json): void {
    Http::fake(static function (): never {
        throw new ConnectionException('https://internal.example.test?token=provider-private-token');
    });

    $exit = Artisan::call('wayfindr:update-plan', $json ? ['--json' => true] : []);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('metadata_unavailable')
        ->and($output)->not->toContain('provider-private-token', 'internal.example.test');
    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('failed');
    }
})->with([true, false]);

test('the CLI refuses floating development and prerelease refs before requesting metadata', function (string $ref): void {
    Http::fake();

    $exit = Artisan::call('wayfindr:update-plan', ['--ref' => $ref, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['status'])->toBe('failed')
        ->and($result['reason'])->toBe('invalid_target');
    Http::assertNothingSent();
})->with(['main', 'latest', '0.2', 'v0.2.0-rc.1', '0.2.0-dev+abc123']);

test('an outstanding release prerequisite is a reviewable refusal with the guard exit code', function (): void {
    Http::fake(updatePlanCommandHttpFixture(['actions' => [[
        'id' => 'command-prerequisite',
        'summary' => 'Perform the required work.',
        'detail' => 'The requirement belongs to the release.',
        'phase' => 'before-pull',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]]));

    $exit = Artisan::call('wayfindr:update-plan', ['--json' => true]);
    $plan = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(UpgradeGuard::EXIT_BLOCKED)
        ->and($plan['status'])->toBe('blocked')
        ->and($plan['release_requirements']['actions'][0]['id'])->toBe('command-prerequisite')
        ->and(config('wayfindr.release.acknowledged_actions'))->toBe('0.1.0/prior-work');
});
