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

test('expired cobrowse resync requests are no longer pending', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        $request = [
            'requested_at' => now()->subMinutes(6)->toJSON(),
            'fulfilled_at' => null,
        ];

        expect($policy->isExpired($request))->toBeTrue()
            ->and($policy->isFreshPending($request))->toBeFalse()
            ->and($policy->isDelayedPending($request))->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

test('only unexpired pending cobrowse resync requests can be fulfilled', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-19 16:00:00', 'UTC'));

    try {
        $policy = new CobrowseResyncRequestPolicy;

        expect($policy->canBeFulfilled([
            'requested_at' => now()->subSeconds(299)->toJSON(),
            'fulfilled_at' => null,
        ]))->toBeTrue()
            ->and($policy->canBeFulfilled([
                'requested_at' => now()->subMinutes(6)->toJSON(),
                'fulfilled_at' => null,
            ]))->toBeFalse()
            ->and($policy->canBeFulfilled([
                'requested_at' => now()->subSeconds(10)->toJSON(),
                'fulfilled_at' => now()->toJSON(),
            ]))->toBeFalse()
            ->and($policy->canBeFulfilled([
                'requested_at' => 'not-a-date',
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
