<?php

// Operator mail settings GUI (ADR 0011 slice 1b): an SMTP form + send-test under
// the platform-operator boundary, writing DB-backed overrides. The stored
// password is write-only (never echoed).

use App\Enums\AccountRole;
use App\Mail\WayfindrMailTestMessage;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OperatorSetting;
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

test('an environment-supplied password shows as configured, not "no password saved"', function (): void {
    // An existing install: MAIL_PASSWORD supplies the effective credential and
    // there is no operator override. The form must not imply it is empty, or an
    // operator could blank a working env password.
    config()->set('mail.mailers.smtp.password', 'env-secret');

    $this->actingAs(operatorUser())
        ->get(route('operator.settings.mail.edit'))
        ->assertOk()
        ->assertDontSee('No password saved')
        ->assertSee('a password is configured')
        ->assertDontSee('env-secret'); // never echoed
});

test('an undecryptable stored password renders the form with a warning instead of a 500', function (): void {
    // Ciphertext that no longer decrypts (e.g. the APP_KEY was rotated). Written
    // directly so it bypasses set()'s encryption and lands as a bad value.
    OperatorSetting::query()->create(['key' => 'mail.password', 'value' => 'not-valid-ciphertext']);

    $this->actingAs(operatorUser())
        ->get(route('operator.settings.mail.edit'))
        ->assertOk()
        ->assertSee('could not be decrypted');
});

test('an empty password keeps the saved one; the no-password box stores an explicit empty', function (): void {
    $operator = operatorUser();
    mailSettings()->set('mail.password', 'keep-me');

    // Blank password, no "no password" box — the saved password stays.
    $this->actingAs($operator)->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'from_address' => 'support@acme.test',
    ]);
    expect(mailSettings()->get('mail.password'))->toBe('keep-me');

    // "No password" stores an explicit empty override (no auth), not a revert to env.
    $this->actingAs($operator)->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'from_address' => 'support@acme.test',
        'no_password' => '1',
    ]);
    expect(mailSettings()->isSet('mail.password'))->toBeTrue()
        ->and(mailSettings()->get('mail.password'))->toBe('');
});

test('blanking a connection field stores an explicit empty, not a revert to env', function (): void {
    // Env supplies a username; the operator blanks it for an unauthenticated relay.
    config()->set('mail.mailers.smtp.username', 'env-user');

    $this->actingAs(operatorUser())->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'relay.example.com',
        'port' => 25,
        'username' => '', // deliberately blank
        'from_address' => 'support@acme.test',
    ]);

    expect(mailSettings()->isSet('mail.username'))->toBeTrue()
        ->and(mailSettings()->get('mail.username'))->toBe(''); // explicit empty, not env-user
});

test('saving other settings preserves a transport configured outside the form', function (): void {
    mailSettings()->set('mail.mailer', 'ses'); // an env-configured external transport

    $this->actingAs(operatorUser())->post(route('operator.settings.mail.update'), [
        'mailer' => 'ses', // the form offers and keeps it, rather than defaulting to log
        'from_address' => 'support@acme.test',
        'from_name' => 'Acme',
    ])->assertSessionHasNoErrors();

    expect(mailSettings()->get('mail.mailer'))->toBe('ses');
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

test('the send-test action sends a test email via a real mailer', function (): void {
    Mail::fake();
    config()->set('mail.default', 'smtp'); // a real transport, not log

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertRedirect(route('operator.settings.mail.edit'))
        ->assertSessionHas('status');

    Mail::assertSent(WayfindrMailTestMessage::class);
});

test('the send-test refuses a non-delivering transport instead of reporting a false delivery', function (string $mailer): void {
    Mail::fake();
    config()->set('mail.default', $mailer); // log writes to a file, array only holds in memory

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('error');

    Mail::assertNothingSent();
})->with(['log', 'array']);

test('the send-test refuses a failover chain of only non-delivering transports', function (): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['log', 'array']]);

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('error');

    Mail::assertNothingSent();
});

test('the send-test warns that a failover chain with a local sink may not have delivered', function (): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['smtp', 'log']]);

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'may have fallen back'));

    Mail::assertSent(WayfindrMailTestMessage::class);
});

test('the send-test action validates the recipient', function (): void {
    Mail::fake();
    config()->set('mail.default', 'smtp');

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'not-an-email'])
        ->assertSessionHasErrors('to');

    Mail::assertNothingSent();
});
