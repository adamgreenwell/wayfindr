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

function scanningOperator(?string $locale = null): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
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

test('the scanning page follows the operator language', function (string $locale, array $copy): void {
    $settings = scanningSettings();
    $settings->set('scanning.driver', 'sophos-datenpunkt');
    $settings->set('scanning.socket', 'unix:///datenpunkt/clamd.sock');

    $response = $this->actingAs(scanningOperator($locale))
        ->get(route('operator.settings.scanning.edit'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee($copy['title'])
        ->assertSee($copy['heading'])
        ->assertSee($copy['none'])
        ->assertSee($copy['external'])
        ->assertSee($copy['save'])
        ->assertSee($copy['test'])
        ->assertDontSee('Scan uploaded files for malware before they are stored.')
        ->assertDontSee('None (accept with defense-in-depth)')
        ->assertDontSee('Save scanning settings');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach ([
        '//select[@id="driver"]/option[@value="sophos-datenpunkt"]' => 'sophos-datenpunkt',
        '//select[@id="driver"]/option[@value="clamav"]' => 'ClamAV',
        '//input[@id="socket"]' => null,
        '//code[normalize-space(.)="sophos-datenpunkt"]' => 'sophos-datenpunkt',
        '//code[normalize-space(.)="tcp://host:port"]' => 'tcp://host:port',
        '//code[normalize-space(.)="unix:///var/run/clamav/clamd.ctl"]' => 'unix:///var/run/clamav/clamd.ctl',
    ] as $query => $text) {
        $node = $xpath->query($query)->item(0);

        expect($node)->toBeInstanceOf(DOMElement::class, "missing {$query}")
            ->and($node->hasAttribute('lang'))->toBeTrue("missing language boundary on {$query}")
            ->and($node->getAttribute('lang'))->toBe('');

        if ($text !== null) {
            expect(trim($node->textContent))->toBe($text);
        }
    }
})->with([
    'German' => ['de', [
        'title' => 'Prüfung von Anhängen',
        'heading' => 'Schadsoftware-Scanner',
        'none' => 'Ohne Scanner (mit mehrschichtigem Schutz akzeptieren)',
        'external' => 'Der aktuelle Scanner ist über die Umgebung konfiguriert:',
        'save' => 'Einstellungen für die Dateiprüfung speichern',
        'test' => 'Erreichbarkeit testen',
    ]],
    'Italian' => ['it', [
        'title' => 'Scansione degli allegati',
        'heading' => 'Scanner antimalware',
        'none' => 'Nessuno (accetta con protezione a più livelli)',
        'external' => 'Lo scanner attuale è configurato nell’ambiente:',
        'save' => 'Salva le impostazioni di scansione',
        'test' => 'Verifica della raggiungibilità',
    ]],
]);

test('scanning validation and save feedback answer in the operator language', function (string $locale, string $error, string $saved): void {
    $operator = scanningOperator($locale);

    $this->actingAs($operator)
        ->from(route('operator.settings.scanning.edit'))
        ->post(route('operator.settings.scanning.update'), ['driver' => 'clamav'])
        ->assertRedirect(route('operator.settings.scanning.edit'))
        ->assertSessionHasErrors('socket');

    expect((string) session('errors')->first('socket'))->toBe($error);

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.scanning.update'), ['driver' => ''])
        ->assertOk()
        ->assertSee($saved)
        ->assertDontSee('Scanning settings saved. Run a reachability test to confirm the scanner responds.');
})->with([
    'German' => [
        'de',
        'ClamAV-Socket muss ausgefüllt werden, wenn Scanner den Wert clamav hat.',
        'Einstellungen für die Dateiprüfung gespeichert.',
    ],
    'Italian' => [
        'it',
        'Il campo Socket ClamAV è obbligatorio quando Scanner vale clamav.',
        'Impostazioni di scansione salvate.',
    ],
]);

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

test('scanner test outcomes are localized and keep runtime data language-neutral', function (): void {
    $operator = scanningOperator('de');

    config()->set('wayfindr.attachments.scanner.driver', '');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.scanning.test'))
        ->assertOk()
        ->assertSee('Es ist kein Scanner konfiguriert')
        ->assertDontSee('No scanner is configured');

    config()->set('wayfindr.attachments.scanner.driver', 'clamav');
    config()->set('wayfindr.attachments.scanner.clamav.socket', 'tcp://datenpunkt:3310');
    $this->mock(AttachmentScanner::class, fn ($mock) => $mock
        ->shouldReceive('isAvailable')
        ->twice()
        ->andReturn(true, false));

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.scanning.test'))
        ->assertOk()
        ->assertSee('Scanner erreichbar')
        ->assertSee('<span lang="">clamav</span>', false)
        ->assertDontSee('Scanner reachable');

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.scanning.test'))
        ->assertOk()
        ->assertSee('nicht erreichbar')
        ->assertSee('<span lang="">tcp://datenpunkt:3310</span>', false)
        ->assertDontSee('scanner is configured but unreachable');

    app()->bind(AttachmentScanner::class, fn () => throw new RuntimeException('Datenpunkt <failure>'));

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.scanning.test'))
        ->assertOk()
        ->assertSee('Der Scanner ist falsch konfiguriert')
        ->assertSee('<span lang="">Datenpunkt &lt;failure&gt;</span>', false)
        ->assertDontSee('Scanner is misconfigured');
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
