<?php

use App\Models\Account;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent quiet hours follow the agent timezone across midnight', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ]);

    expect($agent->alertQuietHours())->toBe([
        'enabled' => true,
        'start' => '22:00',
        'end' => '07:00',
        'timezone' => 'America/New_York',
    ])
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-06 01:59:00', 'UTC')))->toBeFalse()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-06 02:00:00', 'UTC')))->toBeTrue()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-06 10:59:00', 'UTC')))->toBeTrue()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC')))->toBeFalse();
});

test('same-day quiet hours include the start and exclude the end', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'timezone' => 'Europe/Berlin',
        'alert_preferences' => [
            'quiet_hours' => [
                'enabled' => true,
                'start' => '12:00',
                'end' => '13:30',
            ],
        ],
    ]);

    expect($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-05 09:59:00', 'UTC')))->toBeFalse()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC')))->toBeTrue()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-05 11:29:00', 'UTC')))->toBeTrue()
        ->and($agent->alertQuietHoursActive(CarbonImmutable::parse('2026-09-05 11:30:00', 'UTC')))->toBeFalse();
});

test('quiet hours leave at least two hours for scheduled delivery sweeps', function (): void {
    expect(User::alertQuietHoursScheduleIsValid('07:00', '05:00'))->toBeTrue()
        ->and(User::alertQuietHoursScheduleIsValid('07:00', '05:01'))->toBeFalse();
});

test('quiet hours pause interruptive email delivery without suppressing alert scope', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 02:30:00', 'UTC'));
    $agent = User::factory()->for(Account::factory())->create([
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ]);

    try {
        expect($agent->alertInterruptionsPaused())->toBeTrue()
            ->and($agent->wantsImmediateAlertEmail())->toBeFalse()
            ->and($agent->alertMode())->toBe(User::ALERT_MODE_ALL);

        $agent->forceFill([
            'alert_preferences' => [
                ...$agent->alert_preferences,
                'cadence' => User::ALERT_CADENCE_UNATTENDED,
            ],
        ])->save();

        expect($agent->fresh()->wantsUnattendedAlertEmail())->toBeFalse();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'));

        expect($agent->fresh()->alertInterruptionsPaused())->toBeFalse()
            ->and($agent->fresh()->wantsUnattendedAlertEmail())->toBeTrue();
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('invalid persisted quiet hours fail open and render repairable defaults', function (): void {
    config()->set('wayfindr.dashboard_timezone', 'Europe/London');
    $agent = User::factory()->for(Account::factory())->create([
        'timezone' => null,
        'alert_preferences' => [
            'quiet_hours' => [
                'enabled' => true,
                'start' => '25:00',
                'end' => '25:00',
            ],
        ],
    ]);

    expect($agent->alertQuietHours())->toBe([
        'enabled' => false,
        'start' => User::ALERT_QUIET_HOURS_DEFAULT_START,
        'end' => User::ALERT_QUIET_HOURS_DEFAULT_END,
        'timezone' => 'Europe/London',
    ])->and($agent->alertQuietHoursActive())->toBeFalse();

    $agent->forceFill([
        'alert_preferences' => [
            'quiet_hours' => [
                'enabled' => true,
                'start' => '07:00',
                'end' => '06:59',
            ],
        ],
    ])->save();

    expect($agent->fresh()->alertQuietHours())->toBe([
        'enabled' => false,
        'start' => '07:00',
        'end' => '06:59',
        'timezone' => 'Europe/London',
    ]);
});
