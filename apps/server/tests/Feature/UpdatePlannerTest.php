<?php

declare(strict_types=1);

use App\Support\Release\CheckRegistry;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeContext;
use App\Support\Updates\InstallationCapabilities;
use App\Support\Updates\ReleaseCatalog;
use App\Support\Updates\UpdatePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function updatePlannerManifest(string $version = '0.2.0', array $declaration = [], string $commit = ''): array
{
    return ReleaseManifest::build(array_replace([
        'minimum_upgrade_from' => null,
        'actions' => [],
    ], $declaration), $version, $commit === '' ? str_repeat('b', 40) : $commit);
}

function updatePlannerCatalog(array $overrides = []): ReleaseCatalog
{
    $target = $overrides['targetManifest'] ?? updatePlannerManifest();
    $history = $overrides['history'] ?? [updatePlannerManifest('0.1.0', commit: str_repeat('a', 40)), $target];

    return new ReleaseCatalog(
        targetManifest: $target,
        history: $history,
        tag: $overrides['tag'] ?? 'v'.$target['version'],
        imageDigest: $overrides['imageDigest'] ?? 'sha256:'.str_repeat('1', 64),
        releaseNotes: $overrides['releaseNotes'] ?? 'The selected release notes.',
        releaseUrl: $overrides['releaseUrl'] ?? 'https://github.com/adamgreenwell/wayfindr/releases/tag/v'.$target['version'],
        provenance: $overrides['provenance'] ?? [
            'history_complete' => true,
            'commit' => $target['commit'],
            'manifest_sha256' => hash('sha256', json_encode($target, JSON_THROW_ON_ERROR)),
            'history_sha256' => hash('sha256', json_encode($history, JSON_THROW_ON_ERROR)),
        ],
        platforms: $overrides['platforms'] ?? [
            'linux/amd64' => [
                'manifest_digest' => 'sha256:'.str_repeat('3', 64),
                'config_digest' => 'sha256:'.str_repeat('4', 64),
            ],
        ],
    );
}

function updatePlannerInstallation(array $report = [], bool $authenticated = true, string $architecture = 'amd64', string $image = 'ghcr.io/adamgreenwell/wayfindr:0.1.0'): InstallationCapabilities
{
    $report = array_replace_recursive([
        'ownership' => 'installer-managed',
        'installation_id' => 'plan-test-installation',
        'enrolled' => true,
        'helper' => [
            'protocol' => InstallationCapabilities::HELPER_PROTOCOL,
            'version' => InstallationCapabilities::MINIMUM_HELPER_VERSION,
            'capabilities' => InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES,
        ],
    ], $report);

    return $authenticated
        ? InstallationCapabilities::authenticatedHelper($report, 'image', 'linux', $architecture, $image)
        : InstallationCapabilities::reported($report, 'image', 'linux', $architecture, $image);
}

function updatePlannerState(array $overrides = []): void
{
    file_put_contents((string) config('wayfindr.release.state_path'), json_encode(array_replace([
        'version' => '0.1.0',
        'commit' => str_repeat('a', 40),
        'satisfied_through' => '0.1.0',
        'installation_profile' => 'image',
        'fresh_install' => false,
    ], $overrides), JSON_THROW_ON_ERROR)."\n");
}

function updatePlannerAction(array $overrides = []): array
{
    return array_replace([
        'id' => 'planner-work',
        'summary' => 'Complete this release requirement.',
        'detail' => 'Use the release instructions.',
        'phase' => 'before-pull',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ], $overrides);
}

beforeEach(function (): void {
    $this->updatePlannerDirectory = sys_get_temp_dir().'/wayfindr-planner-'.bin2hex(random_bytes(6));
    mkdir($this->updatePlannerDirectory, 0700, true);
    config()->set([
        'wayfindr.release.state_path' => $this->updatePlannerDirectory.'/state.json',
        'wayfindr.release.manifest_path' => $this->updatePlannerDirectory.'/live-manifest.json',
        'wayfindr.release.history_path' => $this->updatePlannerDirectory.'/live-history.json',
        'wayfindr.release.installation_profile' => 'image',
        'wayfindr.release.version' => 'v0.1.0',
        'wayfindr.release.commit' => str_repeat('a', 40),
        'wayfindr.release.acknowledged_actions' => null,
        'wayfindr.release.upgrade_from' => null,
    ]);
    app()->instance(UpgradeContext::class, new UpgradeContext);
    updatePlannerState();
});

afterEach(function (): void {
    File::deleteDirectory($this->updatePlannerDirectory);
});

test('an exact plan binds source target platform and recovery facts without granting execution', function (): void {
    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation())->toArray();

    expect($plan['status'])->toBe('update_available')
        ->and($plan['source']['runtime_version'])->toBe('v0.1.0')
        ->and($plan['source']['recorded_version'])->toBe('0.1.0')
        ->and($plan['target']['version'])->toBe('0.2.0')
        ->and($plan['target']['commit'])->toBe(str_repeat('b', 40))
        ->and($plan['target']['image_digest'])->toBe('sha256:'.str_repeat('1', 64))
        ->and($plan['target']['image_reference'])->toBe('ghcr.io/adamgreenwell/wayfindr:0.2.0@sha256:'.str_repeat('1', 64))
        ->and($plan['target']['platform_evidence']['config_digest'])->toBe('sha256:'.str_repeat('4', 64))
        ->and($plan['managed']['eligible'])->toBeTrue()
        ->and($plan['managed']['execution_available'])->toBeFalse()
        ->and($plan['effects']['migration_list'])->toBeNull()
        ->and($plan['effects']['duration_seconds'])->toBeNull()
        ->and($plan['recovery']['automatic_post_migration_rollback'])->toBeFalse();
});

test('caller-created catalogs still require an exact stable target and full commit', function (string $version, string $commit): void {
    $target = updatePlannerManifest();
    $target['version'] = $version;
    $target['commit'] = $commit;

    expect(fn () => app(UpdatePlanner::class)->build(updatePlannerCatalog(['targetManifest' => $target]), updatePlannerInstallation()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown development release' => ['main', str_repeat('b', 40)],
    'prerelease candidate' => ['0.2.0-rc.1', str_repeat('b', 40)],
    'build-tagged release' => ['0.2.0+local', str_repeat('b', 40)],
    'missing target commit' => ['0.2.0', ''],
    'abbreviated target commit' => ['0.2.0', str_repeat('b', 12)],
]);

test('both forty and sixty-four character source commits can establish a known source', function (int $length): void {
    config()->set('wayfindr.release.commit', str_repeat('a', $length));
    updatePlannerState(['commit' => str_repeat('a', $length)]);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation())->toArray();

    expect($plan['status'])->toBe('update_available')
        ->and($plan['managed']['eligible'])->toBeTrue()
        ->and($plan['managed']['blockers'])->not->toContain('source_identity_unknown');
})->with([40, 64]);

test('conflicting running and recorded identity refuses planning without offering a manual bypass', function (string $version, string $commit): void {
    config()->set('wayfindr.release.version', $version);
    config()->set('wayfindr.release.commit', $commit);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation())->toArray();

    expect($plan['status'])->toBe('identity_conflict')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('source_state_identity_mismatch')
        ->and($plan['manual']['prerequisites_clear'])->toBeFalse();
})->with([
    'versions disagree' => ['v0.2.0', str_repeat('a', 40)],
    'source commits disagree' => ['v0.1.0', str_repeat('c', 40)],
]);

test('candidate freshness reflects the actual guard observation rather than a stale source exemption', function (bool $fresh): void {
    if ($fresh) {
        unlink((string) config('wayfindr.release.state_path'));
        DB::table('migrations')->delete();
    } else {
        updatePlannerState(['fresh_install' => true]);
    }

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation())->toArray();

    expect($plan['source']['fresh_install'])->toBe($fresh);
})->with([true, false]);

test('only the same known source build can be reported up to date', function (?string $version, ?string $commit, string $status): void {
    config()->set('wayfindr.release.version', $version);
    config()->set('wayfindr.release.commit', $commit);
    updatePlannerState(['version' => '0.2.0', 'commit' => $commit, 'satisfied_through' => '0.2.0']);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation(image: 'ghcr.io/adamgreenwell/wayfindr:0.2.0'))->toArray();

    expect($plan['status'])->toBe($status);
    if ($commit === null || $version === null) {
        expect($plan['managed']['eligible'])->toBeFalse()
            ->and($plan['managed']['blockers'])->toContain('source_identity_unknown');
    }
})->with([
    'the known exact build is current' => ['v0.2.0', str_repeat('b', 40), 'up_to_date'],
    'the same version with another commit needs an update' => ['v0.2.0', str_repeat('a', 40), 'update_available'],
    'a missing commit never proves current' => ['v0.2.0', null, 'identity_unknown'],
    'an unknown version never proves current' => [null, str_repeat('b', 40), 'identity_unknown'],
]);

test('a newer running release cannot be planned as a downgrade', function (): void {
    config()->set('wayfindr.release.version', 'v0.3.0');
    updatePlannerState(['version' => '0.3.0', 'satisfied_through' => '0.3.0']);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation(image: 'ghcr.io/adamgreenwell/wayfindr:0.3.0'))->toArray();

    expect($plan['status'])->toBe('downgrade_refused')
        ->and($plan['manual']['prerequisites_clear'])->toBeFalse()
        ->and($plan['managed']['eligible'])->toBeFalse();
});

test('the planner exposes skipped-release guard work without inventing an acknowledgement bypass', function (): void {
    $intermediate = updatePlannerManifest('0.2.0', ['actions' => [updatePlannerAction([
        'phase' => 'after-start',
        'depends_on_release' => 'code',
    ])]]);
    $target = updatePlannerManifest('0.3.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.2.0/planner-work');
    $catalog = updatePlannerCatalog(['targetManifest' => $target, 'history' => [$intermediate, $target]]);

    $plan = app(UpdatePlanner::class)->build($catalog, updatePlannerInstallation())->toArray();
    $action = $plan['release_requirements']['actions'][0];

    expect($plan['status'])->toBe('blocked')
        ->and($action['disposition'])->toBe('STEP')
        ->and($action['blocks_migration'])->toBeTrue()
        ->and($action['acknowledgeable'])->toBeFalse()
        ->and($action['acknowledgement_key'])->toBeNull();
});

test('retained debt stays visible even when it does not block candidate migrations', function (): void {
    config()->set('wayfindr.release.version', 'v0.3.0');
    updatePlannerState(['version' => '0.3.0', 'satisfied_through' => null]);
    $old = updatePlannerManifest('0.2.0', ['actions' => [updatePlannerAction(['phase' => 'after-start'])]]);
    $target = updatePlannerManifest('0.4.0');

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['targetManifest' => $target, 'history' => [$old, $target]]), updatePlannerInstallation(image: 'ghcr.io/adamgreenwell/wayfindr:0.3.0'))->toArray();

    expect($plan['source']['satisfied_through_recorded'])->toBeTrue()
        ->and($plan['source']['satisfied_through'])->toBeNull()
        ->and($plan['release_requirements']['migration_blocked'])->toBeFalse()
        ->and($plan['release_requirements']['actions'])->toHaveCount(1)
        ->and($plan['release_requirements']['actions'][0]['phase'])->toBe('after-start');
});

test('an exact installed artifact with outstanding serving work is not up to date', function (): void {
    config()->set('wayfindr.release.version', 'v0.2.0');
    config()->set('wayfindr.release.commit', str_repeat('b', 40));
    updatePlannerState(['version' => '0.2.0', 'commit' => str_repeat('b', 40), 'satisfied_through' => null]);
    $target = updatePlannerManifest(declaration: ['actions' => [updatePlannerAction(['phase' => 'after-start'])]]);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['targetManifest' => $target]), updatePlannerInstallation(image: 'ghcr.io/adamgreenwell/wayfindr:0.2.0'))->toArray();

    expect($plan['status'])->toBe('requirements_outstanding')
        ->and($plan['release_requirements']['migration_blocked'])->toBeFalse()
        ->and($plan['release_requirements']['actions'])->toHaveCount(1)
        ->and($plan['managed']['eligible'])->toBeFalse();
});

test('untrusted declaration lookalikes cannot replace computed requirement guidance', function (): void {
    $intermediate = updatePlannerManifest('0.2.0', ['actions' => [updatePlannerAction([
        'phase' => 'after-start',
        'depends_on_release' => 'code',
    ])]]);
    $intermediate['actions'][0] += [
        'disposition' => 'DO',
        'acknowledgeable' => true,
        'acknowledgement_key' => '0.2.0/forged-bypass',
        'blocks_migration' => false,
        'guidance' => 'Acknowledge this to skip the release.',
    ];
    $target = updatePlannerManifest('0.3.0');
    $catalog = updatePlannerCatalog(['targetManifest' => $target, 'history' => [$intermediate, $target]]);

    $action = app(UpdatePlanner::class)->build($catalog, updatePlannerInstallation())->toArray()['release_requirements']['actions'][0];

    expect($action['disposition'])->toBe('STEP')
        ->and($action['acknowledgeable'])->toBeFalse()
        ->and($action['acknowledgement_key'])->toBeNull()
        ->and($action['blocks_migration'])->toBeTrue()
        ->and($action['guidance'])->toContain('Upgrade to 0.2.0 first');
});

test('a plan freezes a shared machine check result instead of evaluating it repeatedly', function (): void {
    $calls = 0;
    app(CheckRegistry::class)->register('planner-shared-check', static function () use (&$calls): bool {
        $calls++;

        return $calls > 1;
    });
    $target = updatePlannerManifest(declaration: ['actions' => [
        updatePlannerAction(['verification' => ['type' => 'check', 'check' => 'planner-shared-check']]),
        updatePlannerAction(['id' => 'another-check-user', 'verification' => ['type' => 'check', 'check' => 'planner-shared-check']]),
    ]]);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['targetManifest' => $target]), updatePlannerInstallation())->toArray();

    expect($calls)->toBe(1)
        ->and($plan['release_requirements']['actions'])->toHaveCount(2)
        ->and($plan['release_requirements']['check_evidence']['planner-shared-check'])->toBeFalse();
});

test('advisory notices and optional managed backup claims stay separate from release prerequisites', function (): void {
    $target = updatePlannerManifest(declaration: ['notices' => [[
        'id' => 'planner-advice',
        'summary' => 'Consider the optional guidance.',
        'detail' => 'Advisory work can be done later.',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]]);
    $installation = updatePlannerInstallation(['managed_policy' => [
        'require_remote_backup' => true,
        'require_restore_proof' => true,
    ]]);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['targetManifest' => $target]), $installation)->toArray();

    expect($plan['status'])->toBe('update_available')
        ->and($plan['release_requirements']['actions'])->toBeEmpty()
        ->and($plan['advisory_notices'])->toHaveCount(1)
        ->and($plan['managed']['optional_backup_policy']['require_remote_backup'])->toBeTrue()
        ->and($plan['managed']['policy_assessed'])->toBeFalse()
        ->and($plan['manual']['prerequisites_clear'])->toBeTrue()
        ->and($plan['manual']['new_backup_setup_required'])->toBeFalse();
});

test('an unenrolled reported installation keeps the terminal path without acquiring new requirements', function (): void {
    $installation = updatePlannerInstallation(['enrolled' => false], authenticated: false);

    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), $installation)->toArray();

    expect($plan['status'])->toBe('update_available')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('helper_not_authenticated', 'helper_not_enrolled')
        ->and($plan['manual']['prerequisites_clear'])->toBeTrue()
        ->and($plan['manual']['helper_required'])->toBeFalse()
        ->and($plan['manual']['new_backup_setup_required'])->toBeFalse()
        ->and($plan['manual']['guidance'])->toContain('install.sh --upgrade');
});

test('unknown or unsupported target platforms cannot enable managed eligibility', function (array $platforms, string $blocker): void {
    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['platforms' => $platforms]), updatePlannerInstallation())->toArray();

    expect($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain($blocker)
        ->and($plan['manual']['helper_required'])->toBeFalse();
})->with([
    'unverified image index' => [[], 'target_platform_unverified'],
    'the verified image index omits this host' => [[
        'linux/arm64' => ['manifest_digest' => 'sha256:'.str_repeat('3', 64), 'config_digest' => 'sha256:'.str_repeat('4', 64)],
    ], 'target_platform_unsupported'],
]);

test('malformed target platform evidence refuses eligibility without a type error', function (mixed $evidence): void {
    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(['platforms' => ['linux/amd64' => $evidence]]), updatePlannerInstallation())->toArray();

    expect($plan['status'])->toBe('update_available')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('target_platform_unsupported');
})->with([
    'manifest digest is an array' => [[
        'manifest_digest' => ['sha256:'.str_repeat('3', 64)],
        'config_digest' => 'sha256:'.str_repeat('4', 64),
    ]],
    'config digest is an array' => [[
        'manifest_digest' => 'sha256:'.str_repeat('3', 64),
        'config_digest' => ['sha256:'.str_repeat('4', 64)],
    ]],
    'evidence is a scalar' => ['unsupported evidence'],
]);

test('official image selectors must agree with the observed running version', function (string $tag): void {
    $plan = app(UpdatePlanner::class)->build(updatePlannerCatalog(), updatePlannerInstallation(image: 'ghcr.io/adamgreenwell/wayfindr:'.$tag))->toArray();

    expect($plan['status'])->toBe('identity_conflict')
        ->and($plan['managed']['eligible'])->toBeFalse()
        ->and($plan['managed']['blockers'])->toContain('source_image_identity_mismatch')
        ->and($plan['manual']['prerequisites_clear'])->toBeFalse();
})->with(['v0.9.0', '0.9.0']);

test('a plan fingerprint binds source target declarations capability policy and check evidence', function (string $changedFact): void {
    $planner = app(UpdatePlanner::class);
    $catalog = updatePlannerCatalog();
    $installation = updatePlannerInstallation();
    $original = $planner->build($catalog, $installation)->fingerprint();

    switch ($changedFact) {
        case 'source':
            config()->set('wayfindr.release.commit', str_repeat('c', 40));
            break;
        case 'target':
            $catalog = updatePlannerCatalog(['targetManifest' => updatePlannerManifest(commit: str_repeat('c', 40))]);
            break;
        case 'digest':
            $catalog = updatePlannerCatalog(['imageDigest' => 'sha256:'.str_repeat('5', 64)]);
            break;
        case 'history':
            $catalog = updatePlannerCatalog(['history' => [updatePlannerManifest('0.1.0'), updatePlannerManifest('0.1.1'), updatePlannerManifest()]]);
            break;
        case 'debt':
            updatePlannerState(['satisfied_through' => null]);
            break;
        case 'capabilities':
            $installation = updatePlannerInstallation(['installation_id' => 'another-installation']);
            break;
        case 'policy':
            $installation = updatePlannerInstallation(['managed_policy' => ['require_remote_backup' => true]]);
            break;
        case 'notes':
            $catalog = updatePlannerCatalog(['releaseNotes' => 'A different published release note.']);
            break;
        case 'advisory_notices':
            $catalog = updatePlannerCatalog(['targetManifest' => updatePlannerManifest(declaration: ['notices' => [[
                'id' => 'new-bound-advice',
                'summary' => 'New release advice.',
                'detail' => 'The receipt must bind advisory guidance too.',
                'applicability' => ['type' => 'always'],
                'verification' => ['type' => 'attest'],
            ]]])]);
            break;
        case 'checks':
            $target = updatePlannerManifest(declaration: ['actions' => [updatePlannerAction([
                'verification' => ['type' => 'check', 'check' => 'planner-fingerprint-check'],
            ])]]);
            $catalog = updatePlannerCatalog(['targetManifest' => $target]);
            app(CheckRegistry::class)->register('planner-fingerprint-check', static fn (): bool => true);
            $original = $planner->build($catalog, $installation)->fingerprint();
            app(CheckRegistry::class)->register('planner-fingerprint-check', static fn (): bool => false);
            break;
    }

    expect($planner->build($catalog, $installation)->fingerprint())->not->toBe($original);
})->with(['source', 'target', 'digest', 'history', 'debt', 'capabilities', 'policy', 'notes', 'advisory_notices', 'checks']);

test('identical facts retain their fingerprint across associative key ordering and returned-array changes', function (): void {
    $catalog = updatePlannerCatalog();
    $planner = app(UpdatePlanner::class);
    $installation = updatePlannerInstallation();
    $plan = $planner->build($catalog, $installation);
    $reordered = updatePlannerCatalog([
        'targetManifest' => array_reverse($catalog->targetManifest, preserve_keys: true),
        'provenance' => array_reverse($catalog->provenance, preserve_keys: true),
        'history' => array_map(static fn (array $manifest): array => array_reverse($manifest, preserve_keys: true), $catalog->history),
    ]);
    $returned = $plan->toArray();
    $returned['target']['commit'] = 'changed outside the readonly receipt';

    expect($planner->build($reordered, $installation)->fingerprint())->toBe($plan->fingerprint())
        ->and($planner->build($catalog, $installation)->fingerprint())->toBe($plan->fingerprint())
        ->and($plan->toArray()['target']['commit'])->toBe(str_repeat('b', 40));
});
