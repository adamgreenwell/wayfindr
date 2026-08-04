<?php

declare(strict_types=1);

use App\Listeners\BlockMigrationsWithUnmetRequirements;
use App\Listeners\ForgetReleaseAfterRollback;
use App\Support\Backup\RestoreService;
use App\Support\Release\CheckRegistry;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

/*
 * A WARNING about dispatching CommandStarting here.
 *
 * `BlockMigrationsWithUnmetRequirements` refuses by calling exit(), which it has
 * to: the console kernel swallows exceptions and the entrypoint needs a
 * distinguished code. In a test run that terminates the RUNNER — every test after
 * it is silently never executed, and the summary still says "passed", because the
 * tests that did run all passed.
 *
 * It cost 67 tests once, invisibly. So: never put a CommandStarting for a guarded
 * command (`migrate`, `migrate:fresh`, `migrate:refresh`) on the bus in a test
 * whose baked declaration blocks. Record the context directly, or call the
 * listener under test yourself.
 *
 * If the totals ever look wrong, compare them: a run where
 * `pest --list-tests | grep -c '^ - '` exceeds the reported test count means the
 * process died partway.
 */

function bakeRelease(array $declaration, string $version, array $history = []): void
{
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $manifest = ReleaseManifest::build($declaration, $version, 'abc123');
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => $history === [] ? [$manifest] : $history,
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');
}

function blockingDeclaration(string $phase = 'after-pull'): array
{
    return ['actions' => [[
        'id' => 'do-the-thing',
        'summary' => 'Do the thing.',
        'detail' => 'php artisan do:the-thing',
        'phase' => $phase,
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]];
}

test('is inert when no manifest is baked', function (): void {
    // A development checkout must migrate as normal.
    config()->set('wayfindr.release.manifest_path', '/nonexistent/release.json');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('blocks migration on an unmet pre-migration requirement', function (): void {
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['actions'])->toHaveCount(1)
        ->and($assessment['target'])->toBe('0.2.0');
});

test('does not block migration on an after-start requirement', function (): void {
    // It needs the migrated schema to be performed at all, so blocking migration
    // on it would withhold the state the action requires.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('an acknowledgement clears the block', function (): void {
    bakeRelease(blockingDeclaration(), '0.2.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.2.0/do-the-thing');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('an acknowledgement for another release does not clear it', function (): void {
    bakeRelease(blockingDeclaration(), '0.2.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.9.9/do-the-thing');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
});

test('an install with prior migrations but no state file is treated as legacy', function (): void {
    // RefreshDatabase has run the suite's migrations, so the migrations table is
    // populated — which is exactly the evidence that distinguishes an existing
    // install from a fresh one when no state file exists.
    bakeRelease(blockingDeclaration(), '0.2.0');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['legacy'])->toBeTrue()
        ->and($assessment['from'])->toBeNull();
});

test('the command reports cleanly and exits non-zero when blocked', function (): void {
    bakeRelease(blockingDeclaration(), '0.2.0');

    $this->artisan('wayfindr:upgrade-guard')
        ->expectsOutputToContain('Acknowledge with: 0.2.0/do-the-thing')
        ->assertFailed();
});

test('the command succeeds when nothing is outstanding', function (): void {
    bakeRelease(['actions' => []], '0.2.0');

    $this->artisan('wayfindr:upgrade-guard')->assertSuccessful();
});

test('the command emits machine-readable output on request', function (): void {
    bakeRelease(blockingDeclaration(), '0.2.0');

    $this->artisan('wayfindr:upgrade-guard --json')
        ->expectsOutputToContain('"blocked": true')
        ->assertFailed();
});

test('an unmet after-start requirement refuses traffic but keeps health up', function (): void {
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');

    // Migration is deliberately not blocked by this phase...
    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();

    // ...but the release must not serve until it is done.
    $this->get('/')->assertStatus(503)->assertSee('do-the-thing');

    // The health endpoint stays up: a failing health check would have the
    // orchestrator restart the container on a loop, replacing a legible message
    // with a crash loop.
    $this->get('/up')->assertSuccessful();
});

test('serving resumes once the requirement is acknowledged', function (): void {
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.2.0/do-the-thing');

    $this->get('/up')->assertSuccessful();
    // `/` redirects normally in this app; what matters is that it is no longer
    // being refused.
    expect($this->get('/')->status())->not->toBe(503);
});

test('a pre-migration requirement does not gate serving', function (): void {
    // It gates migration instead; gating both would strand an operator whose
    // recovery needs the app.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    expect($this->get('/')->status())->not->toBe(503);
});

test('the serving gate survives the release being recorded', function (): void {
    // The regression: recording the release makes the span (target, target],
    // which is empty, so every unmet after-start action would vanish and traffic
    // would be served despite the requirement.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');
    app(ReleaseState::class)->record('0.2.0', 'abc123');

    $this->get('/')->assertStatus(503)->assertSee('do-the-thing');
});

test('the command reports an after-start requirement the migration gate ignores', function (): void {
    // assess() filters it out as non-migration-blocking; the command must not,
    // or it prints "nothing outstanding" while the app refuses traffic.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');

    $this->artisan('wayfindr:upgrade-guard')
        ->expectsOutputToContain('Blocks: serving')
        ->assertFailed();
});

test('a fresh install is not handed historical upgrade work', function (): void {
    // No state file and no prior schema. RefreshDatabase would normally leave a
    // populated migrations table, so drop it to model a genuinely new install.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');
    Schema::drop('migrations');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('an install below the floor cannot upgrade directly', function (): void {
    // Not a to-do list: the migrations that would carry it forward have been
    // retired, so no acknowledgement can make the jump safe.
    bakeRelease(['minimum_upgrade_from' => '0.5.0', 'actions' => []], '0.6.0');
    app(ReleaseState::class)->record('0.2.0', 'abc');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['floor'])->toBe('0.5.0');
});

test('an install at the floor may upgrade', function (): void {
    bakeRelease(['minimum_upgrade_from' => '0.5.0', 'actions' => []], '0.6.0');
    app(ReleaseState::class)->record('0.5.0', 'abc');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('a development version is not refused by the floor', function (): void {
    // Precedence against one is undefined, so "below" cannot be established.
    // Refusing on no answer would strand source installs that are current.
    bakeRelease(['minimum_upgrade_from' => '0.5.0', 'actions' => []], '0.6.0');
    app(ReleaseState::class)->record('0.6.0-dev', 'abc');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('the floor refusal says acknowledgement cannot help', function (): void {
    bakeRelease(['minimum_upgrade_from' => '0.5.0', 'actions' => []], '0.6.0');
    app(ReleaseState::class)->record('0.2.0', 'abc');

    $this->artisan('wayfindr:upgrade-guard')
        ->expectsOutputToContain('oldest supported starting point is 0.5.0')
        ->assertFailed();
});

test('falls back to the source tree when nothing is baked at the image path', function (): void {
    // The whole of the host installed base. Only the image build writes
    // /etc/wayfindr, so a guard that looked there and nowhere else enforced
    // nothing at all on Forge and on every git checkout - silently, which is the
    // worst shape for it to fail in.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $manifest = ReleaseManifest::build(blockingDeclaration(), '0.2.0', 'abc123');
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [$manifest],
    ]));

    config()->set('wayfindr.release.manifest_path', '/nonexistent/etc/release.json');
    config()->set('wayfindr.release.history_path', '/nonexistent/etc/history.json');
    config()->set('wayfindr.release.manifest_fallback_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_fallback_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
});

test('prefers the baked manifest over the source tree when both exist', function (): void {
    // A container carries both: the image bakes /etc/wayfindr AND ships the
    // repository. The baked one is the release's own declaration and must win.
    bakeRelease(blockingDeclaration(), '0.2.0');

    $stale = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($stale, 0700, true);
    file_put_contents($stale.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.9.9', 'zzz'),
    ));

    config()->set('wayfindr.release.manifest_fallback_path', $stale.'/release.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['target'])->toBe('0.2.0');
});

test('reads acknowledgements from the environment, not a stale config cache', function (): void {
    // The moment this value matters is the retry: the operator has just been
    // refused, has added the acknowledgement the refusal asked for, and is
    // running migrate again. On a host deployment config() is still serving what
    // was cached before they edited anything, so reading only config() would
    // refuse a second time and leave them stuck behind a cache nobody mentioned.
    bakeRelease(blockingDeclaration(), '0.2.0');
    config()->set('wayfindr.release.acknowledged_actions', null);

    putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/do-the-thing');

    try {
        expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
    } finally {
        putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS');
    }
});

test('the live environment wins over a config value that disagrees', function (): void {
    // Stale-beats-live is the exact failure: config() holding the pre-edit value
    // must not mask the acknowledgement the operator just made.
    bakeRelease(blockingDeclaration(), '0.2.0');
    config()->set('wayfindr.release.acknowledged_actions', '0.1.0/something-else');

    putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/do-the-thing');

    try {
        expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
    } finally {
        putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS');
    }
});

test('an intermediate release after-start action survives the target being recorded', function (): void {
    // The same collapse one level deeper. A v0.1.0 -> v0.3.0 upgrade passes
    // through v0.2.0, whose after-start action CANNOT block the migration - it
    // needs the migrated schema to be performed at all. So migration completes,
    // v0.3.0 is recorded, and reading the span from what is RUNNING makes it
    // (0.3.0, 0.3.0]: target inclusion restores v0.3.0's own actions and nothing
    // of v0.2.0's. The requirement is outstanding one moment and served the next.
    $intermediate = ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb');

    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        $intermediate,
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ]);

    $state = app(ReleaseState::class);
    $state->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    // Outstanding before the upgrade is recorded.
    expect(app(UpgradeGuard::class)->assessAll())->toHaveCount(1);

    // The upgrade lands. Nothing was done about v0.2.0's action, so the marker
    // must not advance past it - it stays at 0.1.0, which is what the recorder
    // computes: below everything still owed.
    $state->record('0.3.0', 'ccc', satisfiedThrough: '0.1.0');

    expect($state->recordedVersion())->toBe('0.3.0')
        ->and($state->satisfiedThrough())->toBe('0.1.0');

    $this->get('/')->assertStatus(503)->assertSee('do-the-thing');
});

test('the recording listener advances the marker only on a clean assessment', function (): void {
    // The listener is what decides, and it decides BEFORE recording - once the
    // target is recorded the span collapses and the answer is always "clean".
    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ]);
    config()->set('wayfindr.release.version', '0.3.0');
    config()->set('wayfindr.release.commit', 'ccc');

    $state = app(ReleaseState::class);
    $state->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect($state->recordedVersion())->toBe('0.3.0')
        ->and($state->satisfiedThrough())->toBe('0.1.0');
});

test('the recording listener advances the marker when nothing is owed', function (): void {
    // The other half: an install that owed nothing must not stay pinned to an
    // old starting point, or every later upgrade evaluates a span that only grows.
    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ]);
    config()->set('wayfindr.release.version', '0.3.0');
    config()->set('wayfindr.release.commit', 'ccc');

    $state = app(ReleaseState::class);
    $state->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect($state->satisfiedThrough())->toBe('0.3.0');
});

test('the recorder keeps a marker that sits below everything owed', function (): void {
    // Preserving the marker is the RECORDER's decision, not something record()
    // does by coalescing a null - it needs the outstanding list to make it.
    bakeRelease(['actions' => []], '0.4.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.4.0', 'abc123'),
    ]);
    config()->set('wayfindr.release.version', '0.4.0');
    config()->set('wayfindr.release.commit', 'abc123');

    $state = app(ReleaseState::class);
    $state->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    // 0.1.0 is below the 0.2.0 debt, so it is still the last clean release.
    expect($state->satisfiedThrough())->toBe('0.1.0');
});

test('the recorder clears a marker that sits at the debt it owes', function (): void {
    // A build under the same VERSION adds an action, or a check regresses. The
    // marker already sits AT that release, so preserving it drops the debt from
    // the next upgrade's span - serving still blocks while this release is
    // current, because the target is included explicitly, and then the next
    // upgrade computes (0.2.0, target] and never sees it again.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'abc123'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'abc123');

    $state = app(ReleaseState::class);
    $state->record('0.2.0', 'abc123', satisfiedThrough: '0.2.0');

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect($state->satisfiedThroughRecorded())->toBeTrue()
        ->and($state->satisfiedThrough())->toBeNull();
});

test('a floor refusal stays blocked in machine-readable output', function (): void {
    // A floor refusal carries no actions, so deriving `blocked` from the action
    // count reported success for an upgrade the guard had already refused - and
    // --json returns before the floor-specific path that would have caught it.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.1.0', 'aaa');

    $exit = Artisan::call('wayfindr:upgrade-guard', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($decoded['blocked'])->toBeTrue()
        ->and($decoded['floor'])->toBe('0.2.0');
});

test('a newly traversed retirement is measured from the release actually running', function (): void {
    // The fail-open the retained-debt span introduces if the two origins are
    // shared. This install ran 0.3.0 and still owes 0.2.0 work, so the span
    // reaches back to 0.1.0. A 0.4.0 action retiring what 0.3.0 set up carries
    // upgrade-from.min = 0.3.0 - and measured from 0.1.0 it looks inapplicable,
    // so the retirement is dropped on precisely the install that needs it.
    $retirement = ['actions' => [[
        'id' => 'retire-the-worker',
        'summary' => 'Stop the worker 0.3.0 introduced.',
        'detail' => 'systemctl disable wayfindr-worker',
        'phase' => 'after-start',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'upgrade-from', 'min' => '0.3.0'],
        'verification' => ['type' => 'attest'],
    ]]];

    bakeRelease($retirement, '0.4.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
        ReleaseManifest::build($retirement, '0.4.0', 'ddd'),
    ]);

    // Running 0.3.0, but still carrying unpaid 0.2.0 debt.
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.1.0');

    $ids = array_column(app(UpgradeGuard::class)->assessAll(), 'id');

    // Both: the retained 0.2.0 debt AND the newly traversed 0.4.0 retirement.
    expect($ids)->toContain('retire-the-worker')
        ->and($ids)->toContain('do-the-thing');
});

test('retained debt keeps the origin its own upgrade started from', function (): void {
    // The other side of the split. A 0.2.0 action that only applies to installs
    // starting at 0.2.0 or later must NOT become applicable just because the
    // install has since moved to 0.3.0 - this install started at 0.1.0, which is
    // what decided its applicability, and moving on does not rewrite that.
    //
    // The min is deliberately BETWEEN the two origins: measured from 0.1.0 it
    // does not apply, measured from 0.3.0 it does, so the test can tell the two
    // apart. (An earlier draft used 0.15.0, where SemVer ranks minor 15 above 3
    // and both origins rejected it - passing for the wrong reason.)
    $conditional = ['actions' => [[
        'id' => 'only-for-recent-starts',
        'summary' => 'Applies only from 0.2.0 up.',
        'detail' => 'Not this install.',
        'phase' => 'after-start',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'upgrade-from', 'min' => '0.2.0'],
        'verification' => ['type' => 'attest'],
    ]]];

    bakeRelease(['actions' => []], '0.4.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build($conditional, '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
        ReleaseManifest::build(['actions' => []], '0.4.0', 'ddd'),
    ]);

    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.1.0');

    expect(array_column(app(UpgradeGuard::class)->assessAll(), 'id'))
        ->not->toContain('only-for-recent-starts');
});

test('a fresh install stays fresh across the migration that populates the table', function (): void {
    // Freshness is read off the migrations table, and the first thing `migrate`
    // does is populate it - so the SAME install reads fresh before and legacy
    // after. The post-migration reassessment would hand a brand-new install the
    // target's after-start action and the serving gate would 503 it on day one.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0');
    // Matching the commit bakeRelease() stamps. A real deploy derives the
    // recorded commit and the manifest's from one build, so a fixture where they
    // disagree exercises a CHANGED build by accident.
    config()->set('wayfindr.release.commit', 'abc123');

    // Observed while the database is still empty, exactly as the blocking
    // listener does on CommandStarting.
    app(UpgradeContext::class)->observeFreshInstall(true);

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    $state = app(ReleaseState::class);

    expect($state->recordedVersion())->toBe('0.2.0')
        ->and($state->wasFreshInstall())->toBeTrue()
        ->and(app(UpgradeGuard::class)->assessAll())->toBe([])
        // The marker must advance too. Left null because the post-migration
        // reassessment saw work outstanding, the NEXT upgrade would start from an
        // unknown point and take the legacy path - handing a install that has
        // never upgraded the whole published history.
        ->and($state->satisfiedThrough())->toBe('0.2.0');

    expect($this->get('/')->status())->not->toBe(503);
});

test('the fresh exemption does not survive a later upgrade', function (): void {
    // Fresh at 0.2.0 is not fresh at 0.3.0. Persisting the flag without scoping
    // it to the release it was recorded at would exempt every future upgrade.
    bakeRelease(blockingDeclaration('after-start'), '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.2.0', 'bbb'),
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.3.0', 'ccc'),
    ]);

    app(ReleaseState::class)->record('0.2.0', 'bbb', satisfiedThrough: '0.2.0', freshInstall: true);

    // Target is 0.3.0; the install was fresh at 0.2.0, so it must be evaluated.
    expect(app(UpgradeGuard::class)->assessAll())->toHaveCount(1);
});

test('a legacy install is still handed its history', function (): void {
    // The exemption must not leak to installs that genuinely upgraded: an empty
    // context observation is not evidence of freshness.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'bbb');

    app(UpgradeContext::class)->observeFreshInstall(false);

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->wasFreshInstall())->toBeFalse();
    $this->get('/')->assertStatus(503);
});

test('the canonical release is recorded, not the development identity', function (): void {
    // A source deployment stamps the manifest with VERSION (0.2.0) while the
    // running identity is 0.2.0-dev+<sha>. Recording the identity means the
    // recorded release never equals the guard's own target, so the cross-process
    // freshness check fails and a brand-new Forge install is reclassified as an
    // upgrade - handed upgrade-only work it never had.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0-dev+abcdef');
    config()->set('wayfindr.release.commit', 'abc123');

    app(UpgradeContext::class)->observeFreshInstall(true);
    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    $state = app(ReleaseState::class);

    expect($state->recordedVersion())->toBe('0.2.0')
        // The build is not lost - it is kept as the commit.
        ->and($state->wasFreshInstall())->toBeTrue();

    // And the freshness scoping now matches, so the gate stays open.
    expect($this->get('/')->status())->not->toBe(503);
});

test('an unreadable release history refuses rather than reporting clear', function (): void {
    // Truncated history: the target manifest still reads, so assess() would
    // append it and evaluate a span of one - silently omitting every
    // intermediate release's before-pull and after-pull work.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $manifest = ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc');
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', '{"schema":1,"releases":[{"version":"0.2.0"');

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('a manifest with no history beside it refuses', function (): void {
    // Both producers write the pair together - the image build emits --out and
    // --history from one invocation, and the Forge deploy generates the manifest
    // beside the committed history. So a manifest with no history is an
    // incomplete artifact, and reading it as "no prior release declared
    // anything" reduces a v1 -> v3 upgrade to the target alone.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/absent.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('missing or could not be read');
});

test('a stranded intermediate action blocks migration whatever its phase', function (): void {
    // A v0.2.0 action needing v0.2.0's own code cannot be performed on a direct
    // 0.1.0 -> 0.3.0 jump at ANY phase. Letting an after-start one through
    // migration and gating serving afterwards leaves the install migrated,
    // refusing traffic, and holding a requirement with no way to satisfy it.
    $stranded = ['actions' => [[
        'id' => 'needs-its-own-code',
        'summary' => 'Run a command that only 0.2.0 ships.',
        'detail' => 'php artisan something:only-in-0.2.0',
        'phase' => 'after-start',
        'depends_on_release' => 'code',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]];

    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build($stranded, '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ]);

    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and(array_column($assessment['actions'], 'id'))->toContain('needs-its-own-code');
});

test('the targets own after-start action still does not block migration', function (): void {
    // The stranded rule must not swallow the ordinary case: an after-start action
    // of the release being installed needs the migrated schema, so blocking
    // migration on it could never be satisfied.
    $own = ['actions' => [[
        'id' => 'needs-new-schema',
        'summary' => 'Backfill using the new column.',
        'detail' => 'php artisan backfill',
        'phase' => 'after-start',
        'depends_on_release' => 'schema',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]];

    bakeRelease($own, '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build($own, '0.3.0', 'ccc'),
    ]);

    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse()
        ->and(app(UpgradeGuard::class)->assessAll())->toHaveCount(1);
});

test('an unreadable target manifest refuses rather than disabling the guard', function (): void {
    // Reading it as absent turned a corrupt file into "this build declares
    // nothing" - which disables the floor as well as the actions.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', '{"schema":1,"version":"0.3.0","act');

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('an actionless refusal is preserved in command output', function (): void {
    // Any refusal that carries no actions - floor, unreadable manifest,
    // unreadable history - was recomputed to success by the command, which then
    // reported clear for a release the migration gate refuses.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', '{"schema":1,"releases":[{"ver');

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $exit = Artisan::call('wayfindr:upgrade-guard', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($decoded['blocked'])->toBeTrue();
});

test('a pretend migration installs nothing and records nothing', function (): void {
    // `--pretend` prints the SQL and runs none of it, exiting 0. Recorded as
    // installed, the next REAL migration computes its span from the release it
    // is about to install and skips that release's own pre-migration work.
    bakeRelease(blockingDeclaration(), '0.2.0');
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'bbb');

    event(new CommandFinished('migrate', new ArrayInput(['--pretend' => true]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('a real migration still records', function (): void {
    // The counterpart, so the guard above cannot be satisfied by never recording.
    bakeRelease(['actions' => []], '0.2.0');
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'bbb');

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.2.0');
});

test('a history entry that is not an object makes the history unreadable', function (): void {
    // Valid JSON, invalid shape. Dropping the entry shortens the history rather
    // than rejecting it, and the release that vanishes takes its before-pull and
    // after-pull requirements with it.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [
            ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
            'not-an-object',
        ],
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('the human-readable command fails on an unreadable manifest', function (): void {
    // An unreadable manifest names no release, so the target is null - and the
    // no-target branch returned success before looking at `blocked`. Only --json
    // saw the corrected value; the documented command still exited 0.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', '{"schema":1,"version":"0.3.0","act');

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    expect(Artisan::call('wayfindr:upgrade-guard'))->toBe(1);
});

test('a development checkout still reports cleanly', function (): void {
    // The no-target branch must stay a success for the case it was written for.
    config()->set('wayfindr.release.manifest_path', '/nonexistent/release.json');
    config()->set('wayfindr.release.manifest_fallback_path', '');

    expect(Artisan::call('wayfindr:upgrade-guard'))->toBe(0);
});

test('a legacy install cannot skip a floor it cannot be checked against', function (): void {
    // No recorded release means the origin is unknown, so the install MAY be
    // below the floor - and "may be" is not permission to run migrations whose
    // path was explicitly retired. Skipping the check because there is nothing
    // to compare against is the fail-open reading of missing evidence.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['from'])->toBeNull()
        ->and($assessment['floor'])->toBe('0.2.0')
        ->and($assessment['reason'])->toContain('cannot be verified');
});

test('stating the origin clears an unverifiable floor', function (): void {
    // The refusal has to be clearable by establishing the origin, or it strands
    // every install predating the state file the first time a floor is declared.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    putenv('WAYFINDR_UPGRADE_FROM=0.2.4');

    try {
        expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
    } finally {
        putenv('WAYFINDR_UPGRADE_FROM');
    }
});

test('stating an origin below the floor is still refused', function (): void {
    // It establishes the origin; it does not override the floor.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    putenv('WAYFINDR_UPGRADE_FROM=0.1.0');

    try {
        $assessment = app(UpgradeGuard::class)->assess();

        expect($assessment['blocked'])->toBeTrue()
            ->and($assessment['from'])->toBe('0.1.0');
    } finally {
        putenv('WAYFINDR_UPGRADE_FROM');
    }
});

test('a fresh install is not refused by an unverifiable floor', function (): void {
    // A brand-new install has not upgraded from anywhere, so there is no floor
    // to verify. Refusing it would make a declared floor unable to be installed.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    app(UpgradeContext::class)->observeFreshInstall(true);

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('the command tells an unverifiable install how to clear it', function (): void {
    // The two refusals sharing `floor` have different remedies. Printing "you are
    // older than X allows" for the unverifiable case sends an operator who may be
    // perfectly current off to reinstall an older release.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    expect(Artisan::call('wayfindr:upgrade-guard'))->toBe(1);
    expect(Artisan::output())->toContain('WAYFINDR_UPGRADE_FROM');
});

test('a history entry that is not a published manifest is rejected', function (): void {
    // Structurally an array, but not a manifest. It contributes no actions - and
    // if its version matches the target it also makes historyContains() true,
    // suppressing the real manifest and taking its requirements with it.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(blockingDeclaration(), '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [['version' => '0.3.0']],
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('an unparseable declared origin does not clear the floor refusal', function (): void {
    // The escape hatch has to be an ORDERABLE version. The floor deliberately
    // refuses only a definite "below", and a typo compares as null - so an
    // unvalidated value would clear the unknown-origin refusal without ever
    // being ranked against the floor at all.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    putenv('WAYFINDR_UPGRADE_FROM=0.2.O');

    try {
        $assessment = app(UpgradeGuard::class)->assess();

        expect($assessment['blocked'])->toBeTrue()
            ->and($assessment['from'])->toBeNull();
    } finally {
        putenv('WAYFINDR_UPGRADE_FROM');
    }
});

test('a development identity is not accepted as a declared origin', function (): void {
    // It parses but does not order, so it could never be ranked against the
    // floor either - the same bypass wearing a valid-looking value.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    putenv('WAYFINDR_UPGRADE_FROM=0.2.5-dev');

    try {
        expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
    } finally {
        putenv('WAYFINDR_UPGRADE_FROM');
    }
});

test('a declared origin is canonicalised', function (): void {
    // `v0.2.4` and `0.2.4` are the same release, and an operator typing the tag
    // they saw on a release page must not get a different answer.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    putenv('WAYFINDR_UPGRADE_FROM=v0.2.4');

    try {
        $assessment = app(UpgradeGuard::class)->assess();

        expect($assessment['blocked'])->toBeFalse()
            ->and($assessment['from'])->toBe('0.2.4');
    } finally {
        putenv('WAYFINDR_UPGRADE_FROM');
    }
});

test('an unreachable database is not a fresh install', function (): void {
    // Reading a connection failure as "fresh" exempts the entire history AND the
    // floor. A connection that blips during this check and recovers before the
    // migrator's own access would migrate with every requirement unevaluated.
    bakeRelease(blockingDeclaration(), '0.2.0');

    DB::shouldReceive('connection')->andThrow(new RuntimeException('could not connect'));

    expect(fn () => app(UpgradeGuard::class)->assess())->toThrow(RuntimeException::class);
});

test('the serving gate tolerates an unreachable database', function (): void {
    // Enforcement belongs to the migration gate. Turning a blip into a 500 from
    // middleware would take out pages that do not need the database at all.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0');
    app(ReleaseState::class)->record('0.2.0', 'abc123');

    $this->get('/')->assertStatus(503);

    DB::shouldReceive('connection')->andThrow(new RuntimeException('could not connect'));

    expect($this->get('/')->status())->not->toBe(500);
});

test('a changed build of the same version is reassessed', function (): void {
    // A source deployment stamps every build of a cycle with the same VERSION, so
    // `recorded === target` does not mean the release has been dealt with - a
    // later commit can add an action under the same version. The span would be
    // (target, target], empty, and the new pre-migration action skipped; serving
    // cannot catch it either, since that gates only after-start.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    // The previous deploy of the SAME version, a different commit.
    app(ReleaseState::class)->record('0.2.0', 'older-commit', satisfiedThrough: '0.2.0');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
});

test('an unchanged build of the same version is not reassessed', function (): void {
    // The counterpart: re-running migrate on the same build must not resurrect
    // work that was already settled, or a released image would block on every
    // restart.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    // bakeRelease() stamps 'abc123' as the commit.
    app(ReleaseState::class)->record('0.2.0', 'abc123', satisfiedThrough: '0.2.0');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('a failed state write is reported on the migration output', function (): void {
    // Silent failure here is delayed and confusing: the next process sees a
    // populated database with no state and reads the install as legacy.
    bakeRelease(['actions' => []], '0.2.0');
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'bbb');
    config()->set('wayfindr.release.state_path', '/nonexistent-root/no/state.json');

    $output = new BufferedOutput;
    event(new CommandFinished('migrate', new ArrayInput([]), $output, 0));

    expect($output->fetch())->toContain('Could not record this release');
});

test('a successful state write says nothing', function (): void {
    // So the warning above cannot be satisfied by always printing it.
    bakeRelease(['actions' => []], '0.2.0');
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'bbb');

    $output = new BufferedOutput;
    event(new CommandFinished('migrate', new ArrayInput([]), $output, 0));

    expect($output->fetch())->not->toContain('Could not record');
});

test('the fresh exemption does not survive a changed build of the same version', function (): void {
    // A Forge database first installed by one commit at 0.2.0 records
    // fresh_install. A later commit under the SAME VERSION adds an action - and
    // a fresh install short-circuits to "nothing outstanding" before the span is
    // consulted at all, so re-including the target would not save it.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    app(ReleaseState::class)->record('0.2.0', 'older-commit', satisfiedThrough: '0.2.0', freshInstall: true);

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
});

test('legacy debt is not discharged by recording the target', function (): void {
    // A state-less legacy install crossing an intermediate after-start action
    // records satisfied_through as null on purpose: the origin is unknown, so the
    // span must keep evaluating the whole history. Reading that null as "never
    // set" and falling back to the recorded release collapses the span to the
    // target and drops the very debt the null was recording.
    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'abc123'),
    ]);

    // What the recorder writes for a legacy upgrade that still owes something.
    app(ReleaseState::class)->record('0.3.0', 'abc123', satisfiedThrough: null);

    expect(app(ReleaseState::class)->satisfiedThroughRecorded())->toBeTrue()
        ->and(app(ReleaseState::class)->satisfiedThrough())->toBeNull()
        ->and(app(UpgradeGuard::class)->assessAll())->toHaveCount(1);
});

test('a state file predating the marker still falls back to the recorded release', function (): void {
    // Old state files have no `satisfied_through` key at all, and those must keep
    // using the recorded release as their origin rather than being read as
    // legacy debt.
    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'abc123'),
    ]);

    file_put_contents(
        (string) config('wayfindr.release.state_path'),
        json_encode(['version' => '0.3.0', 'commit' => 'abc123']),
    );

    expect(app(ReleaseState::class)->satisfiedThroughRecorded())->toBeFalse()
        ->and(app(UpgradeGuard::class)->assessAll())->toBe([]);
});

test('the installed manifest wins over a stale history entry for its own version', function (): void {
    // A source commit under the same VERSION, made after that release was
    // appended to the committed history. The history entry declares nothing; the
    // build being installed declares an after-pull action. Declining to append
    // left the stale copy in the span, so the action was invisible - and
    // re-including the target cannot help when the wrong COPY is selected.
    bakeRelease(blockingDeclaration('after-pull'), '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'stale'),
    ]);

    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and(array_column($assessment['actions'], 'id'))->toContain('do-the-thing');
});

test('an unorderable floor makes the manifest unreadable', function (): void {
    // A bound that cannot be ordered silently stops bounding: compare() returns
    // null for it and the guard refuses only on a definite "below", so an install
    // demonstrably older than the intended floor migrates anyway.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $manifest = ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc');
    $manifest['minimum_upgrade_from'] = 'not-a-version';
    file_put_contents($dir.'/release.json', json_encode($manifest));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('a null floor is how a release says it retires nothing', function (): void {
    // Most releases declare no floor, and build() emits the key as null for them.
    // That must stay readable, or every ordinary release becomes unreadable.
    expect(ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'))
        ->toHaveKey('minimum_upgrade_from', null);

    expect(fn () => ReleaseManifest::assertPublished(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ))->not->toThrow(InvalidArgumentException::class);
});

test('a manifest missing the floor key entirely is rejected', function (): void {
    // build() always emits it, so its absence means truncated or edited - and the
    // absence silently erases a declared floor, letting an install below the
    // supported starting point migrate.
    $manifest = ReleaseManifest::build(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0', 'ccc');
    unset($manifest['minimum_upgrade_from']);

    expect(fn () => ReleaseManifest::assertPublished($manifest))
        ->toThrow(InvalidArgumentException::class);
});

test('a recorded install does not query the database to assess', function (): void {
    // The serving middleware runs this on every non-health request. With a
    // release on record neither $legacy nor $fresh can be affected, so the
    // connection check, table lookup and existence query were pure overhead.
    bakeRelease(['actions' => []], '0.2.0');
    app(ReleaseState::class)->record('0.2.0', 'abc123', satisfiedThrough: '0.2.0');

    DB::shouldReceive('connection')->never();
    DB::shouldReceive('table')->never();

    expect(app(UpgradeGuard::class)->assessAll())->toBe([]);
});

test('a malformed recorded version reads as no origin at all', function (): void {
    // The floor refuses only a definite "below", so a mistyped version compares
    // as null and lets a potentially unsupported migration through. Reporting no
    // origin instead routes to the unverifiable-floor refusal, which is safe and
    // clearable.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    file_put_contents(
        (string) config('wayfindr.release.state_path'),
        json_encode(['version' => '0.1.O', 'commit' => 'aaa']),
    );

    $assessment = app(UpgradeGuard::class)->assess();

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull()
        ->and($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('cannot be verified');
});

test('a recorded development identity is still a valid origin', function (): void {
    // Unlike a declared origin, this is a record of what ran rather than an
    // assertion made to clear a refusal - and the floor already treats a
    // development version as no evidence of an unsupported jump.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');

    file_put_contents(
        (string) config('wayfindr.release.state_path'),
        json_encode(['version' => '0.2.5-dev+abc', 'commit' => 'aaa']),
    );

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.2.5-dev+abc')
        ->and(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('a recorded version is canonicalised on read', function (): void {
    // So a state file written by an older build, or edited by hand from a release
    // page, compares like the manifests it is ranked against.
    bakeRelease(['actions' => []], '0.3.0');

    file_put_contents(
        (string) config('wayfindr.release.state_path'),
        json_encode(['version' => 'v0.2.4', 'commit' => 'aaa']),
    );

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.2.4');
});

test('the manifest commit is recorded, not the runtime override', function (): void {
    // buildChanged() compares what is on record against the MANIFEST, so
    // recording an overridden or stale WAYFINDR_COMMIT reads as a different build
    // on the very next request - dropping a fresh install's exemption and gating
    // serving on upgrade-only work it never owed.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'abc123'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0');
    // A runtime identity that disagrees with the manifest bakeRelease() built.
    config()->set('wayfindr.release.commit', 'stale-override');

    app(UpgradeContext::class)->observeFreshInstall(true);
    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedCommit())->toBe('abc123');

    // And the fresh exemption therefore survives into the serving gate.
    expect($this->get('/')->status())->not->toBe(503);
});

test('an action attributed to another release makes the manifest unreadable', function (): void {
    // Misattribution changes what the action IS. stranded() decides by comparing
    // an action's release against the target, so an intermediate manifest
    // claiming its work belongs to the target turns an unperformable action into
    // a permitted one: migration proceeds, then serving is gated on something
    // that cannot be done at all because the release it needs was skipped.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $intermediate = ReleaseManifest::build([
        'actions' => [[
            'id' => 'needs-its-own-code',
            'summary' => 'Needs 0.2.0 code.',
            'detail' => 'php artisan something',
            'phase' => 'after-start',
            'depends_on_release' => 'code',
            'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ]],
    ], '0.2.0', 'bbb');

    // Rewritten to claim the target, which is what makes it look performable.
    $intermediate['actions'][0]['release'] = '0.3.0';

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [$intermediate, ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc')],
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('a correctly attributed action is still accepted', function (): void {
    // So the check above cannot be satisfied by rejecting every action.
    expect(fn () => ReleaseManifest::assertPublished(
        ReleaseManifest::build(blockingDeclaration(), '0.2.0', 'bbb'),
    ))->not->toThrow(InvalidArgumentException::class);
});

test('a no-op migration rerun does not clear the fresh marker', function (): void {
    // Every container start runs migrate again, and an install with a release on
    // record never re-observes freshness - the observation is only made when
    // there is nothing recorded to read. Without carrying it, the flag survives
    // exactly one restart and the serving gate then treats a brand-new install
    // as an upgrade, refusing traffic for work it never owed.
    bakeRelease(blockingDeclaration('after-start'), '0.2.0', history: [
        ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'abc123'),
    ]);
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'abc123');

    // First start: fresh.
    app(UpgradeContext::class)->observeFreshInstall(true);
    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->wasFreshInstall())->toBeTrue();

    // Second start: a fresh container, so a fresh context that observes nothing
    // because a release is now on record.
    app()->forgetInstance(UpgradeContext::class);
    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->wasFreshInstall())->toBeTrue();
    expect($this->get('/')->status())->not->toBe(503);
});

test('freshness does not carry to a different build', function (): void {
    // Carried only for the SAME release and commit. A real upgrade is not fresh,
    // and preserving it across one would exempt an install from work it owes.
    bakeRelease(['actions' => []], '0.2.0');
    config()->set('wayfindr.release.version', '0.2.0');
    config()->set('wayfindr.release.commit', 'abc123');

    app(ReleaseState::class)->record('0.2.0', 'older-commit', satisfiedThrough: '0.2.0', freshInstall: true);
    app()->forgetInstance(UpgradeContext::class);

    event(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->wasFreshInstall())->toBeFalse();
});

test('a rollback forgets which release is installed', function (): void {
    // The state would otherwise claim a release whose migrations have just been
    // undone, and a later upgrade measures its floor from that claim - so a
    // rewound install could clear a floor it is now below.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');

    event(new CommandFinished('migrate:rollback', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('a rollback that failed partway still forgets', function (): void {
    // Reverting one migration and then failing on a later down() exits non-zero
    // having already changed the schema, so a non-zero exit is not evidence that
    // nothing happened. Forgetting a release that is still installed only costs a
    // refusal the operator can clear; keeping one whose migrations are partly
    // undone lets a later upgrade pass a floor it is now below.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandFinished('migrate:rollback', new ArrayInput([]), new NullOutput, 1));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('migrate:refresh still records rather than forgetting', function (): void {
    // It ends by migrating, so the recorder writes an accurate record - forgetting
    // would be undone a moment later anyway.
    bakeRelease(['actions' => []], '0.3.0');
    config()->set('wayfindr.release.version', '0.3.0');
    config()->set('wayfindr.release.commit', 'abc123');

    event(new CommandFinished('migrate:refresh', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('the rollback listener itself ignores migrate:refresh', function (): void {
    // Dispatched straight at the listener rather than through the event, because
    // both listeners handle CommandFinished and Laravel does not promise an
    // order. Through the event this passes whenever the recorder happens to run
    // second and rewrite what was removed - which is luck, not behaviour, and it
    // hid the difference when this was checked by mutation.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    app(ForgetReleaseAfterRollback::class)->handle(
        new CommandFinished('migrate:refresh', new ArrayInput([]), new NullOutput, 0),
    );

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('a restore points the release state at the archive', function (): void {
    // The state lives on the volume, not inside the dump, so it survives the
    // database being replaced and goes on claiming the release that was running.
    // The documented next step is `migrate --force`, which would then measure its
    // floor and its span from a version this schema is not at.
    bakeRelease(['actions' => []], '0.5.0');
    $state = app(ReleaseState::class);
    $state->record('0.5.0', 'current', satisfiedThrough: '0.5.0');

    app(RestoreService::class)->recordRestoredRelease('0.2.0', 'archived');

    expect($state->recordedVersion())->toBe('0.2.0')
        ->and($state->recordedCommit())->toBe('archived')
        // Unknown on purpose: what this restored database still owed is recorded
        // nowhere, so the whole published history goes back into scope.
        ->and($state->satisfiedThroughRecorded())->toBeTrue()
        ->and($state->satisfiedThrough())->toBeNull();
});

test('a restore from an archive that cannot say what it is forgets', function (): void {
    // Guessing a version here would be inventing evidence. Forgetting routes to
    // the unverifiable-floor refusal, which is safe and clearable.
    bakeRelease(['actions' => []], '0.5.0');
    $state = app(ReleaseState::class);
    $state->record('0.5.0', 'current', satisfiedThrough: '0.5.0');

    app(RestoreService::class)->recordRestoredRelease('unknown', null);

    expect($state->recordedVersion())->toBeNull();
});

test('a manifest with a null commit is rejected', function (): void {
    // buildChanged() compares the recorded commit against this, and a null reads
    // as "cannot tell" - which it resolves as changed, permanently. On a fresh
    // install that drops the exemption on the next request and gates serving on
    // upgrade-only work the install never owed.
    $manifest = ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc');
    $manifest['commit'] = null;

    expect(fn () => ReleaseManifest::assertPublished($manifest))
        ->toThrow(InvalidArgumentException::class);
});

test('a manifest with an empty commit is still accepted', function (): void {
    // The documented release flow produces it: the history-recording step runs
    // before the release commit exists and passes no --commit, so its entries
    // carry "". Rejecting those would break `build-manifest.php --history`.
    expect(fn () => ReleaseManifest::assertPublished(
        ReleaseManifest::build(['actions' => []], '0.3.0', ''),
    ))->not->toThrow(InvalidArgumentException::class);
});

test('the release state is reset before anything that can throw', function (): void {
    // A source-position check, deliberately. Both restoreAttachments() and the
    // integrity pass throw on failure, and the schema is already replaced by
    // then - so recording at the end of the method left a partial restore with
    // state describing a database that no longer exists.
    //
    // Exercising that for real means a live database, an assembled archive, and
    // an injected failure in the attachment copy; what the fix actually is, is an
    // ordering, and this binds to the ordering in the real file.
    $source = file_get_contents(app_path('Support/Backup/RestoreService.php'));

    $replace = strpos($source, '$this->restorer->restore($dump);');
    $record = strpos($source, '$this->recordRestoredRelease(');
    $attachments = strpos($source, '$attachments = $this->restoreAttachments(');

    expect($replace)->not->toBeFalse()
        ->and($record)->not->toBeFalse()
        ->and($attachments)->not->toBeFalse();

    expect($record)->toBeGreaterThan($replace)
        ->and($record)->toBeLessThan($attachments);
});

test('a restore that cannot update the release record fails loudly', function (): void {
    // Both writes can fail on a read-only volume. Ignoring that reported a
    // successful restore whose release record still described the database that
    // was just replaced - and the documented next step measures its floor and
    // span from that stale version.
    bakeRelease(['actions' => []], '0.5.0');
    config()->set('wayfindr.release.state_path', '/nonexistent-root/no/state.json');

    expect(fn () => app(RestoreService::class)->recordRestoredRelease('0.2.0', 'archived'))
        ->toThrow(RuntimeException::class);
});

test('a restore that can update the record does not throw', function (): void {
    // So the failure above cannot be satisfied by always throwing.
    bakeRelease(['actions' => []], '0.5.0');

    expect(fn () => app(RestoreService::class)->recordRestoredRelease('0.2.0', 'archived'))
        ->not->toThrow(RuntimeException::class);

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.2.0');
});

test('a rollback that cannot clear the state exits non-zero', function (): void {
    // A source check, like the restore ordering one. exit() cannot be exercised
    // in-process without killing the test run, and what the fix IS is that the
    // failure path terminates non-zero rather than printing and returning - so
    // the assertion is that the terminate follows the message.
    $source = file_get_contents(app_path('Listeners/ForgetReleaseAfterRollback.php'));

    $message = strpos($source, 'Could not clear the recorded release');
    $terminate = strpos($source, 'exit(1);');

    expect($message)->not->toBeFalse()
        ->and($terminate)->not->toBeFalse()
        ->and($terminate)->toBeGreaterThan($message);
});

test('migrate:refresh does not forget the release via its nested reset', function (): void {
    // RefreshCommand runs migrate:reset and then a nested migrate, and the
    // reset fires its own CommandFinished. Forgetting on it left the nested
    // migration reading an empty ledger, calling a long-standing install fresh,
    // and recording that exemption - which erases outstanding after-start work.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    // What the operator ran.
    event(new CommandStarting('migrate:refresh', new ArrayInput([]), new NullOutput));

    // What it runs internally.
    event(new CommandFinished('migrate:reset', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('a standalone reset still forgets', function (): void {
    // The counterpart: without it, "skip during refresh" could be satisfied by
    // never forgetting at all.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandStarting('migrate:reset', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('migrate:reset', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('a failed migrate:fresh forgets the release', function (): void {
    // The wipe succeeded and the re-migration did not, so the ledger is empty or
    // partial while the record still claims the release that was installed. The
    // recorder returns early on a non-zero exit, and the refresh path
    // deliberately preserves state through its nested reset, so nothing else
    // clears it.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('migrate:fresh', new ArrayInput([]), new NullOutput, 1));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('a failed migrate:refresh forgets the release', function (): void {
    // The nested reset is skipped on purpose, so the outer failure is the only
    // chance to notice the ledger no longer matches the record.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandStarting('migrate:refresh', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('migrate:reset', new ArrayInput([]), new NullOutput, 0));
    event(new CommandFinished('migrate:refresh', new ArrayInput([]), new NullOutput, 1));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('a successful rebuild keeps the record the recorder wrote', function (): void {
    // A rebuild that worked ends by migrating, so the recorder writes an accurate
    // record - forgetting it would undo the very thing that makes the state right.
    bakeRelease(['actions' => []], '0.3.0');
    config()->set('wayfindr.release.version', '0.3.0');
    config()->set('wayfindr.release.commit', 'abc123');

    event(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('migrate:fresh', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('the rollback listener does not forget a successful rebuild', function (): void {
    // Dispatched straight at the listener. Through the event this passes whenever
    // the recorder happens to run second and rewrite what was removed - the same
    // ordering trap as the migrate:refresh case, and it hid the difference when
    // this was first checked by mutation.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    app(ForgetReleaseAfterRollback::class)->handle(
        new CommandFinished('migrate:fresh', new ArrayInput([]), new NullOutput, 0),
    );

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('a standalone db:wipe forgets the release', function (): void {
    // The schema is gone, but nothing was clearing the record - so the next
    // migrate saw the recorded release, never noticed the database was empty,
    // and could evaluate a floor against a schema that no longer exists.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandStarting('db:wipe', new ArrayInput([]), new NullOutput));
    event(new CommandFinished('db:wipe', new ArrayInput([]), new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBeNull();
});

test('the db:wipe inside migrate:fresh is left alone', function (): void {
    // migrate:fresh runs db:wipe and then migrates, so the recorder writes an
    // accurate record. Forgetting on the nested wipe would erase it - or worse,
    // leave the nested migration calling a long-standing install fresh.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    event(new CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput));

    app(ForgetReleaseAfterRollback::class)->handle(
        new CommandFinished('db:wipe', new ArrayInput([]), new NullOutput, 0),
    );

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('a pretend rollback rewinds nothing and forgets nothing', function (): void {
    // --pretend prints the SQL and rewinds nothing, so there is no disagreement
    // to resolve. Forgetting would make an inspection change the install.
    bakeRelease(['actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.3.0', 'ccc', satisfiedThrough: '0.3.0');

    $input = new ArrayInput(['--pretend' => true]);
    event(new CommandStarting('migrate:rollback', $input, new NullOutput));
    event(new CommandFinished('migrate:rollback', $input, new NullOutput, 0));

    expect(app(ReleaseState::class)->recordedVersion())->toBe('0.3.0');
});

test('a manifest whose version cannot be parsed is rejected', function (): void {
    // build() keeps an unparseable value verbatim, the recorder writes it to the
    // state file, and recordedVersion() then discards it - so every later process
    // reads a populated install as legacy.
    expect(fn () => ReleaseManifest::assertPublished(
        ReleaseManifest::build(['actions' => []], '0.3.O', 'ccc'),
    ))->toThrow(InvalidArgumentException::class);
});

test('a development version is still a valid manifest version', function (): void {
    // Unlike a bound, this is what the release IS rather than a limit to compare
    // against, and the guard already treats an unorderable running version
    // conservatively. Rejecting it would make source builds unreadable.
    expect(fn () => ReleaseManifest::assertPublished(
        ReleaseManifest::build(['actions' => []], '0.3.0-dev+abc', 'ccc'),
    ))->not->toThrow(InvalidArgumentException::class);
});

test('acknowledging a stranded action does not settle it', function (): void {
    // The refusal prints an acknowledgement key beside every action, so an
    // operator following the guidance could bypass the intermediate step the
    // same message tells them to take. Attesting that something was done cannot
    // make it possible when the release whose code it needs is skipped.
    $stranded = ['actions' => [[
        'id' => 'needs-its-own-code',
        'summary' => 'Needs 0.2.0 code.',
        'detail' => 'php artisan something',
        'phase' => 'after-start',
        'depends_on_release' => 'code',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ]]];

    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
        ReleaseManifest::build($stranded, '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'abc123'),
    ]);

    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');
    putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/needs-its-own-code');

    try {
        $assessment = app(UpgradeGuard::class)->assess();

        expect($assessment['blocked'])->toBeTrue()
            ->and(array_column($assessment['actions'], 'id'))->toContain('needs-its-own-code');
    } finally {
        putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS');
    }
});

test('a passing check does settle a stranded action', function (): void {
    // A machine check is evidence rather than an assertion: if it answers true
    // the thing exists, whatever route it took. Only the attestation is refused.
    $stranded = ['actions' => [[
        'id' => 'needs-its-own-code',
        'summary' => 'Needs 0.2.0 code.',
        'detail' => 'php artisan something',
        'phase' => 'after-start',
        'depends_on_release' => 'code',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'check', 'check' => 'the-thing-exists'],
    ]]];

    bakeRelease(['actions' => []], '0.3.0', history: [
        ReleaseManifest::build($stranded, '0.2.0', 'bbb'),
        ReleaseManifest::build(['actions' => []], '0.3.0', 'abc123'),
    ]);

    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');
    app(CheckRegistry::class)->register('the-thing-exists', fn (): bool => true);

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('an acknowledgement still settles an ordinary action', function (): void {
    // So the stranded rule cannot be satisfied by refusing every acknowledgement.
    bakeRelease(blockingDeclaration(), '0.2.0');

    putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS=0.2.0/do-the-thing');

    try {
        expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
    } finally {
        putenv('WAYFINDR_ACKNOWLEDGED_ACTIONS');
    }
});

test('the migration refusal does not offer a key it will not honour', function (): void {
    // This is the refusal an operator meets on Forge, on a manual migration, or
    // on any upgrade whose installer preflight was skipped. Printing an
    // acknowledgement key beside a stranded action told them to do something that
    // produces this identical refusal, and never mentioned the release they
    // actually have to install.
    $source = file_get_contents(app_path('Listeners/BlockMigrationsWithUnmetRequirements.php'));

    $strandedBranch = strpos($source, 'UpgradeRequirements::stranded(');
    $acknowledgeLine = strpos($source, 'Acknowledge with:');

    expect($strandedBranch)->not->toBeFalse()
        ->and($acknowledgeLine)->not->toBeFalse()
        // The stranded branch continues before the key is printed.
        ->and($strandedBranch)->toBeLessThan($acknowledgeLine);

    expect($source)->toContain('Install that release first');
});

test('a non-canonical manifest version is rejected', function (): void {
    // `v0.3.0` parses, so it survives the parseability check - but
    // recordedVersion() canonicalises what it reads while the guard keeps the
    // manifest's spelling as the target, so the two stop comparing equal and a
    // fresh install loses its exemption on the next request.
    $manifest = ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc');
    $manifest['version'] = 'v0.3.0';

    expect(fn () => ReleaseManifest::assertPublished($manifest))
        ->toThrow(InvalidArgumentException::class);
});

test('the builder canonicalises so its own output always passes', function (): void {
    // build() takes the git tag verbatim from the release workflow, so this is
    // the ordinary path rather than a corner: `v0.3.0` in, canonical out.
    $manifest = ReleaseManifest::build(['actions' => []], 'v0.3.0', 'ccc');

    expect($manifest['version'])->toBe('0.3.0')
        ->and(fn () => ReleaseManifest::assertPublished($manifest))
        ->not->toThrow(InvalidArgumentException::class);
});

test('a published manifest repeating an action id is rejected', function (): void {
    // An acknowledgement is keyed `<release>/<action-id>`, so two actions sharing
    // an id share a key - and acknowledging the one the operator read settles the
    // other silently, with its work never done. The authored declaration has
    // always required unique ids; the published side, where a hand-edited or
    // truncated manifest arrives, did not.
    $action = [
        'id' => 'same-id',
        'summary' => 'Do a thing.',
        'detail' => 'php artisan thing',
        'phase' => 'after-start',
        'depends_on_release' => 'none',
        'applicability' => ['type' => 'always'],
        'verification' => ['type' => 'attest'],
    ];

    $manifest = ReleaseManifest::build(['actions' => [$action]], '0.3.0', 'ccc');
    $manifest['actions'][] = $manifest['actions'][0];

    expect(fn () => ReleaseManifest::assertPublished($manifest))
        ->toThrow(InvalidArgumentException::class);
});

test('distinct action ids in one manifest are still accepted', function (): void {
    // So the check cannot be satisfied by rejecting every manifest with more than
    // one action - which is the ordinary case for a release that asks for two
    // unrelated things.
    $manifest = ReleaseManifest::build(['actions' => [
        [
            'id' => 'first-thing',
            'summary' => 'One.',
            'detail' => 'php artisan one',
            'phase' => 'after-start',
            'depends_on_release' => 'none',
            'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ],
        [
            'id' => 'second-thing',
            'summary' => 'Two.',
            'detail' => 'php artisan two',
            'phase' => 'after-start',
            'depends_on_release' => 'none',
            'applicability' => ['type' => 'always'],
            'verification' => ['type' => 'attest'],
        ],
    ]], '0.3.0', 'ccc');

    expect(fn () => ReleaseManifest::assertPublished($manifest))
        ->not->toThrow(InvalidArgumentException::class);
});

test('a history repeating a release is unreadable', function (): void {
    // Each manifest validates on its own, so two entries for the same canonical
    // release both pass - and an action id reused between them gives two
    // different pieces of work the same acknowledgement key, so acknowledging
    // either settles both.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    $entry = ReleaseManifest::build(blockingDeclaration('after-start'), '0.2.0', 'bbb');

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [$entry, $entry, ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc')],
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $assessment = app(UpgradeGuard::class)->assess();

    expect($assessment['blocked'])->toBeTrue()
        ->and($assessment['reason'])->toContain('could not be read');
});

test('a history of distinct releases is still readable', function (): void {
    // So the check cannot be satisfied by rejecting every history with more than
    // one entry, which is what a history is for.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', json_encode([
        'schema' => 1,
        'releases' => [
            ReleaseManifest::build(['actions' => []], '0.1.0', 'aaa'),
            ReleaseManifest::build(['actions' => []], '0.2.0', 'bbb'),
            ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
        ],
    ]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});

test('a nested migration inside a rebuild is not guarded again', function (): void {
    // migrate:fresh guards its own start, wipes, then runs `migrate` - which
    // arrives at the guard again with the schema already gone. A state check that
    // passed against the old schema can now answer false, so the guard would
    // refuse AFTER the database was destroyed, and the hard exit would skip the
    // cleanup that clears the stale release record.
    bakeRelease(blockingDeclaration('after-pull'), '0.2.0');

    // The outer command is recorded directly rather than dispatched. Putting a
    // CommandStarting for `migrate:fresh` on the bus reaches the guard for the
    // OUTER command too, which refuses and calls exit() - terminating the test
    // runner mid-suite and silently taking every later test with it.
    app(UpgradeContext::class)->observeCommand('migrate:fresh');

    // The nested run. Reaching the guard here would exit(78), so returning at all
    // is the assertion.
    app(BlockMigrationsWithUnmetRequirements::class)->handle(
        new CommandStarting('migrate', new ArrayInput([]), new NullOutput),
    );

    expect(app(UpgradeContext::class)->outerCommand())->toBe('migrate:fresh');
});

test('an unreadable release history stops the serving gate', function (): void {
    // An empty action list means "nothing outstanding" only when the release
    // could be assessed. An unreadable history produces the same empty list from
    // a very different situation - nothing is known about what is owed.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));
    file_put_contents($dir.'/history.json', '{"schema":1,"releases":[{"ver');

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $this->get('/')->assertStatus(503);
});

test('a floor refusal does not stop the serving gate', function (): void {
    // The other actionless refusal, and the opposite answer: the release is
    // running perfectly and simply cannot be upgraded to from here, so serving
    // carries on. Conflating the two would take a working site down.
    bakeRelease(['minimum_upgrade_from' => '0.2.0', 'actions' => []], '0.3.0');
    app(ReleaseState::class)->record('0.1.0', 'aaa', satisfiedThrough: '0.1.0');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeTrue();
    expect($this->get('/')->status())->not->toBe(503);
});
