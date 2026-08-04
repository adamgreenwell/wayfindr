<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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
