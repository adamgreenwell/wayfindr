<?php

// Drift-signal thresholds for the agent replay preview.
//
// Only unresolved mutations (paths that resolve to no node) count as drift.
// Unsupported types and malformed records are excluded from the ratio so noise
// does not masquerade as a stale preview.
//
// See issue #486.

use App\Support\CobrowseReplayDrift;

test('reports an aligned preview when nothing has drifted', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 12,
        'unresolved' => 0,
        'unsupported' => 3,
        'invalid' => 1,
    ]);

    expect($drift['state'])->toBe('steady')
        ->and($drift['recommend_resync'])->toBeFalse()
        ->and($drift['drift_count'])->toBe(0);
});

test('does not alarm on a tiny sample even when a mutation drifts', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 1,
        'unresolved' => 2,
    ]);

    // addressable = 3, below the minimum sample, so no recommendation yet.
    expect($drift['state'])->toBe('steady')
        ->and($drift['recommend_resync'])->toBeFalse();
});

test('flags minor drift without recommending a resync', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 6,
        'unresolved' => 2,
    ]);

    // addressable = 8, ratio = 0.25.
    expect($drift['state'])->toBe('watch')
        ->and($drift['recommend_resync'])->toBeFalse()
        ->and($drift['ratio'])->toBe(0.25);
});

test('recommends a fresh snapshot once drift dominates', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 4,
        'unresolved' => 4,
    ]);

    // addressable = 8, ratio = 0.5.
    expect($drift['state'])->toBe('drifting')
        ->and($drift['recommend_resync'])->toBeTrue()
        ->and($drift['tone'])->toBe('attention');
});

test('excludes unsupported and invalid records from the drift ratio', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 8,
        'unresolved' => 0,
        'unsupported' => 100,
        'invalid' => 100,
    ]);

    // Pure noise must not register as drift.
    expect($drift['state'])->toBe('steady')
        ->and($drift['recommend_resync'])->toBeFalse()
        ->and($drift['ratio'])->toBe(0.0);
});

test('counts only unresolved mutations against the addressable denominator', function (): void {
    $drift = (new CobrowseReplayDrift)->evaluate([
        'applied' => 3,
        'unresolved' => 3,
        'unsupported' => 50,
    ]);

    // addressable = 6 (unsupported excluded), ratio = 0.5 -> drifting.
    expect($drift['addressable'])->toBe(6)
        ->and($drift['state'])->toBe('drifting')
        ->and($drift['recommend_resync'])->toBeTrue();
});
