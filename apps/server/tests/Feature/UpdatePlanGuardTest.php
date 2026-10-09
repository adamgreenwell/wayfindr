<?php

declare(strict_types=1);

use App\Support\Release\ActionAdvice;
use App\Support\Release\CheckRegistry;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function updatePlanGuardAction(array $overrides = []): array
{
    return array_replace([
        'id' => 'candidate-work',
        'summary' => 'Complete the candidate requirement.',
        'detail' => 'Follow this release prerequisite.',
        'phase' => 'before-pull',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ], $overrides);
}

function updatePlanGuardManifest(string $version, array $declaration = []): array
{
    return ReleaseManifest::build(array_replace([
        'minimum_upgrade_from' => null,
        'actions' => [],
    ], $declaration), $version, str_repeat('a', 40));
}

function updatePlanGuardState(array $state): void
{
    file_put_contents((string) config('wayfindr.release.state_path'), json_encode($state, JSON_THROW_ON_ERROR)."\n");
}

beforeEach(function (): void {
    $this->updatePlanGuardDirectory = sys_get_temp_dir().'/wayfindr-update-plan-'.bin2hex(random_bytes(6));
    mkdir($this->updatePlanGuardDirectory, 0700, true);
    $live = updatePlanGuardManifest('0.1.0');
    file_put_contents($this->updatePlanGuardDirectory.'/release.json', json_encode($live, JSON_THROW_ON_ERROR));
    file_put_contents($this->updatePlanGuardDirectory.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [$live],
    ], JSON_THROW_ON_ERROR));
    config()->set([
        'wayfindr.release.manifest_path' => $this->updatePlanGuardDirectory.'/release.json',
        'wayfindr.release.history_path' => $this->updatePlanGuardDirectory.'/history.json',
        'wayfindr.release.state_path' => $this->updatePlanGuardDirectory.'/state.json',
        'wayfindr.release.installation_profile' => 'image',
        'wayfindr.release.acknowledged_actions' => null,
        'wayfindr.release.upgrade_from' => null,
    ]);
    app()->instance(UpgradeContext::class, new UpgradeContext);
    updatePlanGuardState([
        'version' => '0.1.0',
        'commit' => str_repeat('a', 40),
        'satisfied_through' => '0.1.0',
        'installation_profile' => 'image',
        'fresh_install' => false,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->updatePlanGuardDirectory);
});

test('a candidate cannot skip release-bound work by acknowledging a release it never ran', function (): void {
    $intermediate = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction([
        'phase' => 'after-start',
        'depends_on_release' => 'code',
    ])]]);
    $target = updatePlanGuardManifest('0.3.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.2.0/candidate-work');

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$intermediate, $target]);
    $action = $assessment['outstanding'][0];
    $advice = ActionAdvice::for($action, $assessment['target'], $assessment['from']);

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['actions'])->toHaveCount(1)
        ->and(UpgradeRequirements::disposition($action, $assessment['target'], $assessment['from'])->value)->toBe('STEP')
        ->and($advice->acknowledgeKey)->toBeNull();
});

test('a candidate includes before-pull requirements declared by skipped intermediate releases', function (): void {
    $intermediate = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction()]]);
    $target = updatePlanGuardManifest('0.3.0');

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$intermediate, $target]);

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['actions'])->toHaveCount(1)
        ->and($assessment['actions'][0]['release'])->toBe('0.2.0')
        ->and($assessment['actions'][0]['phase'])->toBe('before-pull');
});

test('a candidate retains work that can be done only while the current release is running', function (): void {
    updatePlanGuardState([
        'version' => '0.2.0',
        'satisfied_through' => '0.1.0',
        'installation_profile' => 'image',
    ]);
    $current = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction([
        'phase' => 'after-start',
        'depends_on_release' => 'code',
    ])]]);
    $target = updatePlanGuardManifest('0.3.0');

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$current, $target]);

    expect($assessment['blocked'])->toBeTrue()
        ->and(UpgradeRequirements::disposition($assessment['outstanding'][0], '0.3.0', '0.2.0')->value)->toBe('NOW');
});

test('candidate history preserves the distinction between retained and absent debt markers', function (array $marker, int $owed): void {
    updatePlanGuardState(array_merge([
        'version' => '0.3.0',
        'installation_profile' => 'image',
    ], $marker));
    $intermediate = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction([
        'phase' => 'after-start',
    ])]]);
    $target = updatePlanGuardManifest('0.4.0');

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$intermediate, $target]);

    expect($assessment['from'])->toBe('0.3.0')
        ->and($assessment['outstanding'])->toHaveCount($owed)
        ->and($assessment['blocked'])->toBeFalse();
})->with([
    'written unknown origin keeps historical debt' => [['satisfied_through' => null], 1],
    'absent marker falls back to recorded version' => [[], 0],
    'retained older origin keeps historical debt' => [['satisfied_through' => '0.1.0'], 1],
    'clean current marker settles older history' => [['satisfied_through' => '0.3.0'], 0],
]);

test('candidate assessment reopens history when a clean marker belongs to another profile', function (?string $recordedProfile, int $owed): void {
    config()->set('wayfindr.release.installation_profile', 'host');
    updatePlanGuardState([
        'version' => '0.3.0',
        'satisfied_through' => '0.3.0',
        'installation_profile' => $recordedProfile,
    ]);
    $intermediate = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction([
        'installation_profiles' => ['host'],
    ])]]);
    $target = updatePlanGuardManifest('0.4.0');

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$intermediate, $target]);

    expect($assessment['installation_profile'])->toBe('host')
        ->and($assessment['outstanding'])->toHaveCount($owed)
        ->and($assessment['blocked'])->toBe($owed !== 0);
})->with([
    'image proof cannot settle host work' => ['image', 1],
    'unknown proof cannot settle host work' => [null, 1],
    'host proof settles host work' => ['host', 0],
]);

test('an unknown legacy candidate origin cannot clear an upgrade floor', function (): void {
    unlink((string) config('wayfindr.release.state_path'));
    $target = updatePlanGuardManifest('0.3.0', ['minimum_upgrade_from' => '0.2.0']);

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$target]);

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['legacy'])->toBeTrue()
        ->and($assessment['from'])->toBeNull()
        ->and($assessment['floor'])->toBe('0.2.0')
        ->and($assessment['declared_origin'])->toBeNull()
        ->and($assessment['assessable'])->toBeTrue();
});

test('a genuinely fresh candidate does not owe historical upgrade work or an unknown-origin floor', function (): void {
    unlink((string) config('wayfindr.release.state_path'));
    DB::table('migrations')->delete();
    $intermediate = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction()]]);
    $target = updatePlanGuardManifest('0.3.0', ['minimum_upgrade_from' => '0.2.0']);
    $context = app(UpgradeContext::class);

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$intermediate, $target]);

    expect($assessment['blocked'])->toBeFalse()
        ->and($assessment['legacy'])->toBeFalse()
        ->and($assessment['outstanding'])->toBeEmpty()
        ->and($context->wasFreshInstall())->toBeNull();
});

test('candidate check results preserve existing acknowledgement semantics', function (?bool $result, bool $acknowledged, bool $blocked): void {
    app(CheckRegistry::class)->register('update-plan-check', static fn (): ?bool => $result);
    if ($acknowledged) {
        config()->set('wayfindr.release.acknowledged_actions', '0.2.0/candidate-work');
    }
    $target = updatePlanGuardManifest('0.2.0', ['actions' => [updatePlanGuardAction([
        'verification' => ['type' => 'check', 'check' => 'update-plan-check'],
    ])]]);

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$target]);

    expect($assessment['blocked'])->toBe($blocked)
        ->and($assessment['outstanding'])->toHaveCount($blocked ? 1 : 0);
})->with([
    'a failing check cannot be acknowledged away' => [false, true, true],
    'an unavailable check remains outstanding without acknowledgement' => [null, false, true],
    'a valid acknowledgement can settle an unavailable ordinary check' => [null, true, false],
    'a passing check needs no acknowledgement' => [true, false, false],
]);

test('candidate advisory notices do not become migration requirements', function (): void {
    $target = updatePlanGuardManifest('0.2.0', ['notices' => [[
        'id' => 'candidate-advice',
        'summary' => 'Review the optional recommendation.',
        'detail' => 'This is advisory guidance.',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]]);

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$target]);

    expect($assessment['blocked'])->toBeFalse()
        ->and($assessment['outstanding'])->toBeEmpty()
        ->and($assessment['notices'])->toHaveCount(1);
});

test('malformed caller-owned candidate metadata is rejected before assessment', function (string $malformation): void {
    $target = updatePlanGuardManifest('0.2.0');
    $history = [$target];
    if ($malformation === 'unsupported-schema') {
        $target['schema'] = ReleaseManifest::SCHEMA + 1;
    } elseif ($malformation === 'missing-floor') {
        unset($target['minimum_upgrade_from']);
    } else {
        $history[] = $target;
    }

    expect(fn (): array => app(UpgradeGuard::class)->assessTarget($target, $history))
        ->toThrow(InvalidArgumentException::class);
})->with(['unsupported-schema', 'missing-floor', 'duplicate-history-version']);

test('candidate requirements use the source-state snapshot even when a machine check changes the file', function (): void {
    $original = json_decode((string) file_get_contents((string) config('wayfindr.release.state_path')), true, flags: JSON_THROW_ON_ERROR);
    app(CheckRegistry::class)->register('update-plan-mutating-check', static function (): bool {
        updatePlanGuardState([
            'version' => '0.3.0',
            'satisfied_through' => '0.3.0',
            'installation_profile' => 'image',
        ]);

        return false;
    });
    $target = updatePlanGuardManifest('0.3.0', ['actions' => [updatePlanGuardAction([
        'verification' => ['type' => 'check', 'check' => 'update-plan-mutating-check'],
    ])]]);

    $assessment = app(UpgradeGuard::class)->assessTarget($target, [$target]);

    expect($assessment['from'])->toBe('0.1.0')
        ->and($assessment['source_state'])->toBe($original)
        ->and($assessment['blocked'])->toBeTrue();
});

test('candidate assessment leaves the live guard paths context and release state unchanged', function (): void {
    $guard = app(UpgradeGuard::class);
    $before = $guard->assess();
    $context = app(UpgradeContext::class);
    $context->observeFreshInstall(false);
    $statePath = (string) config('wayfindr.release.state_path');
    $stateBytes = file_get_contents($statePath);
    $manifestPath = config('wayfindr.release.manifest_path');
    $historyPath = config('wayfindr.release.history_path');
    $target = updatePlanGuardManifest('0.3.0', ['actions' => [updatePlanGuardAction()]]);

    $candidate = $guard->assessTarget($target, [$target]);

    expect($candidate['blocked'])->toBeTrue()
        ->and($candidate['target'])->toBe('0.3.0')
        ->and($candidate['target_commit'])->toBe(str_repeat('a', 40))
        ->and($guard->lastTarget())->toBe('0.1.0')
        ->and($context->wasFreshInstall())->toBeFalse()
        ->and(config('wayfindr.release.manifest_path'))->toBe($manifestPath)
        ->and(config('wayfindr.release.history_path'))->toBe($historyPath)
        ->and(file_get_contents($statePath))->toBe($stateBytes)
        ->and($guard->assess())->toBe($before);
});
