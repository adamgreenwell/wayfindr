<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

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
    // must not advance past it.
    $state->record('0.3.0', 'ccc', satisfiedThrough: null);

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

test('recording preserves the marker when the caller does not advance it', function (): void {
    // Dropping it on a later write would silently widen the span back to the
    // recorded version - the exact collapse the field exists to prevent.
    bakeRelease(['actions' => []], '0.3.0');

    $state = app(ReleaseState::class);
    $state->record('0.2.0', 'bbb', satisfiedThrough: '0.1.0');
    $state->record('0.3.0', 'ccc');

    expect($state->satisfiedThrough())->toBe('0.1.0');
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
    config()->set('wayfindr.release.commit', 'bbb');

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
    config()->set('wayfindr.release.commit', 'abcdef');

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

test('an absent release history is still legitimate', function (): void {
    // Absent is not the same as unreadable: a build cut before the history
    // existed published none, and must migrate as normal.
    $dir = storage_path('framework/testing/release-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);

    file_put_contents($dir.'/release.json', json_encode(
        ReleaseManifest::build(['actions' => []], '0.3.0', 'ccc'),
    ));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/absent.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    expect(app(UpgradeGuard::class)->assess()['blocked'])->toBeFalse();
});
