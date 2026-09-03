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

function operatorUser(?string $locale = null): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
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
        ->assertSee('Send a test email')
        ->assertSee('Back to operator console');
});

test('the mail settings page follows the operator language', function (string $locale, array $copy): void {
    $settings = mailSettings();
    $settings->set('mail.mailer', 'ses-datenpunkt');
    $settings->set('mail.host', 'smtp.datenpunkt.test');
    $settings->set('mail.port', '2525');
    $settings->set('mail.username', 'datenpunkt-user');
    $settings->set('mail.from_address', 'support@datenpunkt.test');
    $settings->set('mail.from_name', 'Datenpunkt Support');
    $settings->set('mail.password', 'never-render-datenpunkt-secret');

    $response = $this->actingAs(operatorUser($locale))
        ->get(route('operator.settings.mail.edit'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee($copy['title'])
        ->assertSee($copy['heading'])
        ->assertSee($copy['log_only'])
        ->assertSee($copy['password_placeholder'])
        ->assertSee($copy['save'])
        ->assertSee($copy['test'])
        ->assertSee($copy['external'])
        ->assertDontSee('Configure outbound email here')
        ->assertDontSee('Log only (no delivery)')
        ->assertDontSee('Save mail settings')
        ->assertDontSee('never-render-datenpunkt-secret');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach ([
        '//select[@id="mailer"]/option[@value="ses-datenpunkt"]' => 'ses-datenpunkt',
        '//select[@id="mailer"]/option[@value="smtp"]' => 'SMTP',
        '//select[@id="encryption"]/option[@value="smtp"]' => 'STARTTLS',
        '//select[@id="encryption"]/option[@value="smtps"]' => 'SSL/TLS',
        '//input[@id="host"]' => null,
        '//input[@id="port"]' => null,
        '//input[@id="username"]' => null,
        '//input[@id="from_address"]' => null,
        '//input[@id="from_name"]' => null,
        '//input[@id="to"]' => null,
        '//code[normalize-space(.)="ses-datenpunkt"]' => 'ses-datenpunkt',
    ] as $query => $text) {
        $node = $xpath->query($query)->item(0);

        expect($node)->toBeInstanceOf(DOMElement::class, "missing {$query}")
            ->and($node->hasAttribute('lang'))->toBeTrue("missing language boundary on {$query}")
            ->and($node->getAttribute('lang'))->toBe('');

        if ($text !== null) {
            expect(trim($node->textContent))->toBe($text);
        }
    }

    $password = $xpath->query('//input[@id="password"]')->item(0);

    expect($password)->toBeInstanceOf(DOMElement::class)
        ->and($password->hasAttribute('lang'))->toBeFalse('the translated password placeholder must inherit the page language');
})->with([
    'German' => ['de', [
        'title' => 'E-Mail-Einstellungen',
        'heading' => 'Ausgehende E-Mails',
        'log_only' => 'Nur protokollieren (keine Zustellung)',
        'password_placeholder' => 'ein Passwort ist konfiguriert',
        'save' => 'E-Mail-Einstellungen speichern',
        'test' => 'Test-E-Mail senden',
        'external' => 'Der aktuelle Transport ist über die Umgebung konfiguriert:',
    ]],
    'Italian' => ['it', [
        'title' => 'Impostazioni email',
        'heading' => 'Posta in uscita',
        'log_only' => 'Solo registro (nessun recapito)',
        'password_placeholder' => 'è configurata una password',
        'save' => 'Salva le impostazioni email',
        'test' => 'Invio di un’email di prova',
        'external' => 'Il trasporto attuale è configurato nell’ambiente:',
    ]],
]);

test('mail validation and save feedback answer in the operator language', function (string $locale, array $errors, string $saved): void {
    $operator = operatorUser($locale);

    $this->actingAs($operator)
        ->from(route('operator.settings.mail.edit'))
        ->post(route('operator.settings.mail.update'), [
            'mailer' => 'smtp',
            'from_address' => '',
        ])
        ->assertRedirect(route('operator.settings.mail.edit'))
        ->assertSessionHasErrors(['host', 'port', 'from_address']);

    expect((string) session('errors')->first('host'))->toBe($errors['host'])
        ->and((string) session('errors')->first('port'))->toBe($errors['port'])
        ->and((string) session('errors')->first('from_address'))->toBe($errors['from_address']);

    config()->set('mail.default', 'smtp');

    $this->actingAs($operator)
        ->post(route('operator.settings.mail.test'), ['to' => 'not-an-email'])
        ->assertSessionHasErrors('to');

    expect((string) session('errors')->first('to'))->toBe($errors['to']);

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.update'), [
            'mailer' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'support@example.test',
        ])
        ->assertOk()
        ->assertSee($saved)
        ->assertDontSee('Mail settings saved. Send a test email to confirm delivery.');
})->with([
    'German' => ['de', [
        'host' => 'SMTP-Host muss ausgefüllt werden, wenn Transport den Wert smtp hat.',
        'port' => 'SMTP-Port muss ausgefüllt werden, wenn Transport den Wert smtp hat.',
        'from_address' => 'Absenderadresse muss ausgefüllt werden.',
        'to' => 'Empfängeradresse muss eine gültige E-Mail-Adresse sein.',
    ], 'E-Mail-Einstellungen gespeichert.'],
    'Italian' => ['it', [
        'host' => 'Il campo Host SMTP è obbligatorio quando Trasporto vale smtp.',
        'port' => 'Il campo Porta SMTP è obbligatorio quando Trasporto vale smtp.',
        'from_address' => 'Il campo Indirizzo mittente è obbligatorio.',
        'to' => 'Il campo Destinatario deve contenere un indirizzo email valido.',
    ], 'Impostazioni email salvate.'],
]);

test('arriving from onboarding keeps the back link and save action on the checklist', function (): void {
    $operator = operatorUser();

    $this->actingAs($operator)
        ->get(route('operator.settings.mail.edit', ['from' => 'onboarding']))
        ->assertOk()
        ->assertSee('Back to setup checklist')
        ->assertSee(route('operator.onboarding'), false);

    // Saving preserves the origin so the operator can return to the checklist.
    $this->actingAs($operator)
        ->post(route('operator.settings.mail.update'), [
            'mailer' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'from_address' => 'support@acme.test',
            'from' => 'onboarding',
        ])
        ->assertRedirect(route('operator.settings.mail.edit', ['from' => 'onboarding']));
});

test('the send-test preserves the onboarding origin on redirect', function (): void {
    Mail::fake();
    config()->set('mail.default', 'smtp');

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test', 'from' => 'onboarding'])
        ->assertRedirect(route('operator.settings.mail.edit', ['from' => 'onboarding']));
});

test('an unknown return context falls back to the operator console', function (): void {
    $this->actingAs(operatorUser())
        ->get(route('operator.settings.mail.edit', ['from' => 'somewhere-else']))
        ->assertOk()
        ->assertSee('Back to operator console');
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
        ->and($event->account_id)->toBeNull() // instance-wide, not a tenant event
        ->and(json_encode($event->metadata))->not->toContain('do-not-log-me');
});

test('the mail settings audit is instance-scoped, not attributed to the operator tenant', function (): void {
    $operator = operatorUser();

    $this->actingAs($operator)->post(route('operator.settings.mail.update'), [
        'mailer' => 'smtp',
        'host' => 'smtp.example.com',
        'port' => 587,
        'from_address' => 'support@acme.test',
    ]);

    // Never stamped with a tenant account — so it stays out of that account's
    // /dashboard/account/audit trail and off its cascade-on-delete.
    expect(AuditEvent::query()
        ->where('action', 'operator_settings.mail.updated')
        ->whereNotNull('account_id')
        ->exists())->toBeFalse();

    // But still visible where operators look — the operator console activity feed.
    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Mail settings updated');
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

test('the send-test refuses a nested failover chain that bottoms out in only sinks', function (): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['inner']]);
    config()->set('mail.mailers.inner', ['transport' => 'failover', 'mailers' => ['log', 'array']]);

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('error');

    Mail::assertNothingSent();
});

test('the send-test refuses a failover chain whose first transport is a local sink', function (string $first): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => [$first, 'smtp']]);

    // Failover stops at the first success and array/log always succeed, so smtp
    // is never reached — refuse rather than flash a (backwards) fallback warning.
    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('error');

    Mail::assertNothingSent();
})->with(['array', 'log']);

test('the send-test tolerates a self-referential failover chain without looping forever', function (): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['failover', 'log']]);

    // The cycle guard must let this resolve and return a response, not hang.
    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertRedirect(route('operator.settings.mail.edit'));
});

test('the send-test warns that a failover chain with a local sink may not have delivered', function (): void {
    Mail::fake();
    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['smtp', 'log']]);

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'me@acme.test'])
        ->assertSessionHas('status', fn (array $status): bool => $status['key'] === 'operator.mail.flash.may_fall_back');

    Mail::assertSent(WayfindrMailTestMessage::class);
});

test('mail test outcomes are localized and keep runtime data language-neutral', function (): void {
    $operator = operatorUser('de');

    config()->set('mail.default', '');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.test'), ['to' => 'ada@datenpunkt.test'])
        ->assertOk()
        ->assertSee('Der E-Mail-Transport ist noch nicht festgelegt')
        ->assertDontSee('Mail transport is still not set');

    config()->set('mail.default', 'log');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.test'), ['to' => 'ada@datenpunkt.test'])
        ->assertOk()
        ->assertSee('eine Testnachricht würde nicht zugestellt')
        ->assertSee('<span lang="">log</span>', false)
        ->assertDontSee('a test message would not be delivered');

    Mail::fake();
    config()->set('mail.default', 'smtp');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.test'), ['to' => 'ada@datenpunkt.test'])
        ->assertOk()
        ->assertSee('Test-E-Mail an')
        ->assertSee('<span lang="">ada@datenpunkt.test</span>', false)
        ->assertSee('<span lang="">smtp</span>', false)
        ->assertDontSee('Test email sent to');

    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['smtp', 'log']]);

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.test'), ['to' => 'ada@datenpunkt.test'])
        ->assertOk()
        ->assertSee('möglicherweise auf ein lokales Protokoll ausgewichen')
        ->assertSee('<span lang="">failover</span>', false)
        ->assertDontSee('may have fallen back');

    Mail::shouldReceive('to')
        ->once()
        ->with('ada@datenpunkt.test')
        ->andThrow(new RuntimeException('Datenpunkt <smtp-failure>'));
    config()->set('mail.default', 'smtp');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.mail.test'), ['to' => 'ada@datenpunkt.test'])
        ->assertOk()
        ->assertSee('Test-E-Mail über')
        ->assertSee('<span lang="">Datenpunkt &lt;smtp-failure&gt;</span>', false)
        ->assertDontSee('Test email failed via');
});

test('the send-test action validates the recipient', function (): void {
    Mail::fake();
    config()->set('mail.default', 'smtp');

    $this->actingAs(operatorUser())
        ->post(route('operator.settings.mail.test'), ['to' => 'not-an-email'])
        ->assertSessionHasErrors('to');

    Mail::assertNothingSent();
});
