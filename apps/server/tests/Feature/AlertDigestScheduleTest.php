<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('alert digest delivery is registered with the Laravel scheduler', function (): void {
    $digestEvent = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'wayfindr:send-alert-digests'));

    expect($digestEvent)->not->toBeNull()
        ->and($digestEvent?->getExpression())->toBe('0 * * * *')
        ->and($digestEvent?->withoutOverlapping)->toBeFalse();
});
