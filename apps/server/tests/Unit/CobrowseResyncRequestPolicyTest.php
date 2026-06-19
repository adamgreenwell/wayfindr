<?php

use App\Support\CobrowseResyncRequestPolicy;
use Illuminate\Support\Carbon;

test('fresh pending cobrowse resync requests are still in flight', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        expect($policy->isFreshPending([
            'requested_at' => now()->subSeconds(30)->toJSON(),
            'fulfilled_at' => null,
        ]))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('delayed cobrowse resync requests can be retried', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        expect($policy->isDelayedPending([
            'requested_at' => now()->subSeconds(61)->toJSON(),
            'fulfilled_at' => null,
        ]))->toBeTrue()
            ->and($policy->isFreshPending([
                'requested_at' => now()->subSeconds(61)->toJSON(),
                'fulfilled_at' => null,
            ]))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('fulfilled cobrowse resync requests are not pending', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        expect($policy->isFreshPending([
            'requested_at' => now()->subSeconds(10)->toJSON(),
            'fulfilled_at' => now()->toJSON(),
        ]))->toBeFalse()
            ->and($policy->isDelayedPending([
                'requested_at' => now()->subMinutes(2)->toJSON(),
                'fulfilled_at' => now()->toJSON(),
            ]))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('cobrowse resync requests without parseable request times are not retryable', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        expect($policy->isFreshPending([
            'requested_at' => 'not-a-date',
            'fulfilled_at' => null,
        ]))->toBeFalse()
            ->and($policy->isDelayedPending([
                'requested_at' => null,
                'fulfilled_at' => null,
            ]))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});
