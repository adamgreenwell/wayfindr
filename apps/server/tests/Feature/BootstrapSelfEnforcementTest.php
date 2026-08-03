<?php

declare(strict_types=1);

use App\Support\Release\ReleaseManifest;
use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the shipped release.json does not gate serving on its own release', function (): void {
    // The bootstrap constraint from ADR 0013: the release that first carries the
    // guard must require nothing, or it refuses traffic on every install the
    // moment it lands. This test is the guard against re-declaring an action
    // without thinking about that.
    $declaration = json_decode((string) file_get_contents(base_path('../../release.json')), true);

    $manifest = ReleaseManifest::build($declaration, '0.2.0', 'abc');

    $dir = storage_path('framework/testing/bootstrap-'.bin2hex(random_bytes(4)));
    mkdir($dir, 0700, true);
    file_put_contents($dir.'/release.json', json_encode($manifest));
    file_put_contents($dir.'/history.json', json_encode(['schema' => 1, 'releases' => [$manifest]]));

    config()->set('wayfindr.release.manifest_path', $dir.'/release.json');
    config()->set('wayfindr.release.history_path', $dir.'/history.json');
    config()->set('wayfindr.release.state_path', $dir.'/state.json');

    $outstanding = app(UpgradeGuard::class)->assessAll();

    $gatesServing = array_filter(
        $outstanding,
        static fn (array $a): bool => in_array($a['phase'] ?? '', UpgradeRequirements::BLOCKS_SERVING, true),
    );

    expect($gatesServing)->toBeEmpty(
        'release.json declares an action that would refuse traffic on the release introducing the guard',
    );
});
