<?php

declare(strict_types=1);

use App\Support\Release\CheckRegistry;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the shipped release.json does not gate serving on its own release', function (): void {
    // The bootstrap constraint from ADR 0013: the release that first carries the
    // guard must require nothing, or it refuses traffic on every install the
    // moment it lands. 0.1.0 discharged that, so this is now a tripwire rather
    // than a law — it exists so that declaring something which refuses traffic is
    // a deliberate act, not a side effect.
    //
    // It asks the real question — does this actually gate serving — rather than
    // proxying through the phase. Those answered the same thing until advisory
    // NOTICES existed; now a notice is reported and never gates, so a phase-based
    // filter would trip on advice that cannot refuse anything. Advisory work is
    // declared under `notices`, which the serving gate never reads, so it is
    // correctly invisible here.
    $declaration = json_decode((string) file_get_contents(base_path('../../release.json')), true);

    $manifest = ReleaseManifest::build($declaration, '0.2.0', 'abc');

    $dir = storage_path('framework/testing/bootstrap-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', json_encode(['schema' => 1, 'releases' => [$manifest]]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $guard = app(UpgradeGuard::class);

    $gatesServing = array_filter(
        $guard->assessAll(),
        static fn (array $a): bool => in_array($a['phase'] ?? '', UpgradeRequirements::BLOCKS_SERVING, true),
    );

    expect($gatesServing)->toBeEmpty(
        'release.json declares an action that would refuse traffic. If that is intended, say so '
        .'deliberately — and consider whether it belongs under "notices" instead, which reports '
        .'without refusing.',
    );

    // The other half of the tripwire, and the reason it is not simply "actions is
    // empty": notices are allowed to be non-empty, and MUST stay out of the
    // gates. If a notice ever reaches the serving list, the separation that makes
    // advisories safe has broken.
    $noticeIds = array_column($guard->notices(), 'id');

    foreach (array_column($guard->assessAll(), 'id') as $gated) {
        expect($noticeIds)->not->toContain(
            $gated,
            "\"{$gated}\" is declared as a notice but reached the gating list — advisory work must never gate.",
        );
    }
});

test('the shipped release.json advises about the backups queue worker', function (): void {
    // The declaration is live, not merely well-formed. This is the first use of
    // ADR 0013's advisory response, and the property that made it declarable is
    // asserted here: it is REPORTED and it gates NOTHING.
    $declaration = json_decode((string) file_get_contents(base_path('../../release.json')), true);

    $manifest = ReleaseManifest::build($declaration, '0.3.0', 'abc');

    $dir = storage_path('framework/testing/notice-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', json_encode(['schema' => 1, 'releases' => [$manifest]]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    // A recorded release, as any running install has. Without one the floor
    // refuses the upgrade outright, and a refused upgrade returns before notices
    // are computed — deliberately, since advice about a release that cannot
    // start is noise on top of a refusal.
    app(ReleaseState::class)->record('0.3.0', 'abc', satisfiedThrough: '0.3.0');

    // No worker seen: the operator is told.
    app(CheckRegistry::class)->register('backups-queue-consumer', fn (): ?bool => false);

    $guard = app(UpgradeGuard::class);

    expect(array_column($guard->notices(), 'id'))->toContain('backups-queue-consumer')
        ->and($guard->assess()['blocked'])->toBeFalse()
        ->and($guard->assessAll())->toBeEmpty();

    // A worker is seen: it retires itself, with nothing for the operator to do.
    // This is why it is verified by `check` rather than attested — an operator
    // already running one is never told anything.
    app(CheckRegistry::class)->register('backups-queue-consumer', fn (): ?bool => true);

    expect(app(UpgradeGuard::class)->notices())->toBeEmpty();
});
