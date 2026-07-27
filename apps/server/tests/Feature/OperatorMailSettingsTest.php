<?php

// Operator mail settings GUI (ADR 0011 slice 1b): an SMTP form + send-test under
// the platform-operator boundary, writing DB-backed overrides. The stored
// password is write-only (never echoed).

use App\Enums\AccountRole;
use App\Mail\WayfindrMailTestMessage;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function operatorUser(): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
}

function mailSettings(): OperatorSettings
{
    return app(OperatorSettings::class);
}

test('a non-operator cannot reach the mail settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.mail.edit'))->assertForbidden();
});

test('the operator sees the mail settings form', function (): void {
    $this->actingAs(operatorUser())
        ->get(route('operator.settings.mail.edit'))
        ->assertOk()
        ->assertSee('Outbound mail')
        ->assertSee('Send a test email');
});

test('saving SMTP settings stores them as live overrides', function (): void {
    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.update'), [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'smtp',
            'username' => 'apikey',
            'password' => 's3cr3t',
            'from_address' => 'support@acme.test',
            'from_name' => 'Acme Support',
        ])
        ->assertRedirect(route('operator.settings.mail.edit'))
        ->assertSessionHas('status');

    expect(mailSettings()->get('mail.mailer'))->toBe('smtp')
        ->and(mailSettings()->get('mail.host'))->toBe('smtp.example.com')
        ->and(mailSettings()->get('mail.port'))->toBe('587')
        ->and(mailSettings()->get('mail.username'))->toBe('apikey')
        ->and(mailSettings()->get('mail.from_address'))->toBe('support@acme.test')
        ->and(mailSettings()->isSet('mail.password'))->toBeTrue();
});

test('the stored password is never echoed back to the browser', function (): void {
    $operator = operatorUser();
    mailSettings()->set('mail.password', 'top-secret-pw');

    $this->actingAs($operator)
        ->get(route('operator.settings.mail.edit'))
        ->assertOk()
        ->assertDontSee('top-secret-pw');
});

test('an empty password field keeps the saved password; the clear box removes it', function (): void {
    $operator = operatorUser();
    mailSettings()->set('mail.password', 'keep-me');

    // Save without a new password and without clearing — the password stays.
    $this->actingAs($operator)->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'from_address' => 'support@acme.test',
    ]);
    expect(mailSettings()->get('mail.password'))->toBe('keep-me');

    // Explicit clear removes it.
    $this->actingAs($operator)->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'from_address' => 'support@acme.test',
        'clear_password' => '1',
    ]);
    expect(mailSettings()->isSet('mail.password'))->toBeFalse();
});

test('saving mail settings records an audit event without the password value', function (): void {
    $this->actingAs(operatorUser())->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'password' => 'do-not-log-me',
        'from_address' => 'support@acme.test',
    ]);

    $event = AuditEvent::query()->where('action', 'operator_settings.mail.updated')->firstOrFail();

    expect($event->metadata['mailer'])->toBe('smtp')
        ->and($event->metadata['password_changed'])->toBe('updated')
        ->and(json_encode($event->metadata))->not->toContain('do-not-log-me');
});

test('SMTP requires a host and port', function (): void {
    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.update'), [
            'mailer' => 'smtp',
            'from_address' => 'support@acme.test',
        ])
        ->assertSessionHasErrors(['host', 'port']);
});

test('the send-test action sends a test email via the configured mailer', function (): void {
    Mail::fake();

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertRedirect(route('operator.settings.mail.edit'))
        ->assertSessionHas('status');

    Mail::assertSent(WayfindrMailTestMessage::class);
});

test('the send-test action validates the recipient', function (): void {
    Mail::fake();

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'not-an-email'])
        ->assertSessionHasErrors('to');

    Mail::assertNothingSent();
});
