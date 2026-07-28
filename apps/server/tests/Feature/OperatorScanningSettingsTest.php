<?php

// Operator malware-scanning GUI (ADR 0011 slice 2b): toggle ClamAV vs
// accept-with-defense-in-depth, set the clamd socket + fail policy, as
// DB-backed overrides, with a reachability-test button.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Attachments\Scanning\AttachmentScanner;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function scanningOperator(): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
}

function scanningSettings(): OperatorSettings
{
    return app(OperatorSettings::class);
}

test('a non-operator cannot reach the scanning settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.scanning.edit'))->assertForbidden();
});

test('the operator sees the scanning settings form', function (): void {
    $this->actingAs(scanningOperator())
        ->get(route('operator.settings.scanning.edit'))
        ->assertOk()
        ->assertSee('Attachment scanning')
        ->assertSee('Malware scanner')
        ->assertSee('Test reachability')
        ->assertSee('Back to operator console');
});

test('saving ClamAV settings stores them, with fail_closed as a real boolean', function (): void {
    $settings = scanningSettings();

    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.update'), [
            'driver' => 'clamav',
            'socket' => 'unix:///var/run/clamav/clamd.ctl',
            'fail_closed' => '1',
        ])
        ->assertRedirect(route('operator.settings.scanning.edit'))
        ->assertSessionHas('status');

    expect($settings->get('scanning.driver'))->toBe('clamav')
        ->and($settings->get('scanning.socket'))->toBe('unix:///var/run/clamav/clamd.ctl');

    // Applying overrides lands a real boolean fail-closed flag, not "1".
    $settings->applyOverrides();
    expect(config('wayfindr.attachments.scanner.driver'))->toBe('clamav')
        ->and(config('wayfindr.attachments.scanner.clamav.socket'))->toBe('unix:///var/run/clamav/clamd.ctl')
        ->and(config('wayfindr.attachments.scanner.fail_closed'))->toBeTrue();
});

test('ClamAV requires a socket', function (): void {
    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.update'), ['driver' => 'clamav'])
        ->assertSessionHasErrors('socket');
});

test('switching scanning off does not blank the env clamd socket', function (): void {
    $settings = scanningSettings();

    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.update'), ['driver' => '']) // none
        ->assertRedirect();

    expect($settings->get('scanning.driver'))->toBe('')
        ->and($settings->isSet('scanning.socket'))->toBeFalse();
});

test('the fail-closed checkbox always submits a value', function (): void {
    $this->actingAs(scanningOperator())
        ->get(route('operator.settings.scanning.edit'))
        ->assertOk()
        ->assertSee('name="fail_closed" value="0"', false);
});

test('an unknown driver is preserved so saving does not silently disable scanning', function (): void {
    $settings = scanningSettings();
    $settings->set('scanning.driver', 'sophos'); // an unknown/external, fail-loud driver

    // The form is prefilled with the external driver as a preserved option.
    $this->actingAs(scanningOperator())
        ->get(route('operator.settings.scanning.edit'))
        ->assertOk()
        ->assertSee('sophos');

    // Saving (e.g. to change the fail policy) keeps the driver, not None.
    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.update'), [
            'driver' => 'sophos',
            'fail_closed' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect($settings->get('scanning.driver'))->toBe('sophos');
});

test('saving scanning settings records an instance-scoped audit', function (): void {
    $this->actingAs(scanningOperator())->post(route('operator.settings.scanning.update'), [
        'driver' => 'clamav',
        'socket' => 'tcp://127.0.0.1:3310',
        'fail_closed' => '1',
    ]);

    $event = AuditEvent::query()->where('action', 'operator_settings.scanning.updated')->firstOrFail();

    expect($event->account_id)->toBeNull() // instance-wide, not a tenant event
        ->and($event->metadata['driver'])->toBe('clamav')
        ->and($event->metadata['fail_closed'])->toBeTrue();
});

test('a scanning change shows in the operator activity feed', function (): void {
    $operator = scanningOperator();

    $this->actingAs($operator)->post(route('operator.settings.scanning.update'), ['driver' => '']);

    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Scanning settings updated');
});

test('the scanner test reports when no scanner is configured', function (): void {
    config()->set('wayfindr.attachments.scanner.driver', ''); // none

    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.test'))
        ->assertSessionHas('error');
});

test('the scanner test reports a reachable scanner', function (): void {
    config()->set('wayfindr.attachments.scanner.driver', 'clamav');
    $this->mock(AttachmentScanner::class, fn ($mock) => $mock->shouldReceive('isAvailable')->andReturnTrue());

    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.test'))
        ->assertRedirect(route('operator.settings.scanning.edit'))
        ->assertSessionHas('status');
});

test('the scanner test reports an unreachable scanner', function (): void {
    config()->set('wayfindr.attachments.scanner.driver', 'clamav');
    $this->mock(AttachmentScanner::class, fn ($mock) => $mock->shouldReceive('isAvailable')->andReturnFalse());

    $this->actingAs(scanningOperator())
        ->post(route('operator.settings.scanning.test'))
        ->assertSessionHas('error');
});

test('arriving from onboarding keeps the back link and save on the checklist', function (): void {
    $operator = scanningOperator();

    $this->actingAs($operator)
        ->get(route('operator.settings.scanning.edit', ['from' => 'onboarding']))
        ->assertOk()
        ->assertSee('Back to setup checklist')
        ->assertSee(route('operator.onboarding'), false);

    $this->actingAs($operator)
        ->post(route('operator.settings.scanning.update'), ['driver' => '', 'from' => 'onboarding'])
        ->assertRedirect(route('operator.settings.scanning.edit', ['from' => 'onboarding']));
});
