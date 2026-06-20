<?php

use App\Support\CobrowseTransportPressure;
use Illuminate\Support\Carbon;

test('cobrowse transport pressure summarizes recent telemetry and mutation loss', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 20:00:00', 'UTC'));

    try {
        $pressure = new CobrowseTransportPressure;
        $metadata = [
            'telemetry' => [
                'dropped_batches' => 2,
                'reported_at' => now()->subSeconds(12)->toJSON(),
            ],
            'mutations' => [
                'last_reported_at' => now()->subSeconds(8)->toJSON(),
                'recent_batches' => [
                    [
                        'dropped_count' => 1,
                        'skipped_count' => 3,
                        'reported_at' => now()->subSeconds(8)->toJSON(),
                        'mutations' => [['type' => 'text', 'text' => 'Sensitive mutation body']],
                    ],
                    [
                        'dropped_count' => 9,
                        'skipped_count' => 9,
                        'reported_at' => now()->subMinutes(2)->toJSON(),
                        'mutations' => [['type' => 'text', 'text' => 'Old sensitive mutation body']],
                    ],
                ],
            ],
        ];

        expect($pressure->summarize($metadata))->toBe([
            'dropped_batches' => 3,
            'skipped_mutations' => 3,
            'reported_at' => now()->subSeconds(8)->toJSON(),
        ])
            ->and(json_encode($pressure->summarize($metadata)))->not->toContain('Sensitive mutation body')
            ->and($pressure->format($metadata))->toBe('3 dropped batches, 3 skipped mutations');
    } finally {
        Carbon::setTestNow();
    }
});

test('cobrowse transport pressure returns calm empty labels', function (): void {
    $pressure = new CobrowseTransportPressure;

    expect($pressure->summarize([
        'telemetry' => [
            'dropped_batches' => 0,
            'reported_at' => now()->toJSON(),
        ],
    ]))->toBe([
        'dropped_batches' => 0,
        'skipped_mutations' => 0,
        'reported_at' => null,
    ])
        ->and($pressure->format([
            'telemetry' => [
                'dropped_batches' => 0,
                'reported_at' => now()->toJSON(),
            ],
        ]))->toBe('No recent drops reported')
        ->and($pressure->format([]))->toBe('No drops reported');
});

test('cobrowse transport pressure ignores negative client counts', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 20:15:00', 'UTC'));

    try {
        $pressure = new CobrowseTransportPressure;
        $metadata = [
            'telemetry' => [
                'dropped_batches' => -4,
                'reported_at' => now()->subSeconds(10)->toJSON(),
            ],
            'mutations' => [
                'last_reported_at' => now()->subSeconds(8)->toJSON(),
                'recent_batches' => [
                    [
                        'dropped_count' => -2,
                        'skipped_count' => 4,
                        'reported_at' => now()->subSeconds(8)->toJSON(),
                    ],
                ],
            ],
        ];

        expect($pressure->summarize($metadata))->toBe([
            'dropped_batches' => 0,
            'skipped_mutations' => 4,
            'reported_at' => now()->subSeconds(8)->toJSON(),
        ])
            ->and($pressure->format($metadata))->toBe('4 skipped mutations');
    } finally {
        Carbon::setTestNow();
    }
});
