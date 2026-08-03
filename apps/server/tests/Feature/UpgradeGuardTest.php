<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
