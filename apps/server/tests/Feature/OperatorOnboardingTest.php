<?php

// Guided onboarding checklist (ADR 0011 slice 1c): a focused, mail-first walk to
// a runnable install under the platform-operator boundary, turning readiness
// diagnostics into inline actions.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\OperatorReadinessConfirmation;
use App\Models\Site;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function onboardingOperator(?Account $account = null, ?string $locale = null): User
{
    return User::factory()->for($account ?? Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
    ]);
}

/** Make every essential green EXCEPT the background-workers attestation. */
function greenExceptWorkers(): void
{
    config()->set('app.url', 'https://support.example.test');
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.example.com');
    config()->set('mail.mailers.smtp.port', 587);
    config()->set('mail.mailers.smtp.scheme', null);
    config()->set('mail.from.address', 'support@acme.test');

    // Language and region is an essential too (#795): unset means "we guessed
    // and nobody confirmed", which is an unanswered step like any other.
    $settings = app(OperatorSettings::class);
    $settings->set('localization.language', 'en');
    $settings->set('localization.timezone', 'UTC');
}

function confirmReadiness(string $key, User $operator): void
{
    OperatorReadinessConfirmation::query()->create([
        'key' => $key,
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now(),
    ]);
}

test('a non-operator cannot reach the onboarding checklist', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.onboarding'))->assertForbidden();
});

test('the onboarding checklist is mail-first with an inline configure action', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Essential steps')
        ->assertSee('Configure the essentials')
        ->assertSee('of 5 ready')
        // The mail step offers a GUI Configure action, not just a CLI command.
        ->assertSee(route('operator.settings.mail.edit'), false)
        // Mail leads the guided order; background workers are a single confirmable
        // step (not a driver-only "Queue worker" that overclaims readiness).
        ->assertSeeInOrder(['Mail transport', 'Public URL', 'Confirm background workers', 'Backups and restore', 'Language and region'])
        ->assertDontSee('Queue worker');
});

test('the onboarding checklist follows the operator language and isolates operational values', function (string $locale, array $copy): void {
    $response = $this->actingAs(onboardingOperator(locale: $locale))
        ->get(route('operator.onboarding'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee($copy['title'])
        ->assertSee($copy['essential'])
        ->assertSee($copy['progress'])
        ->assertSee($copy['mail'])
        ->assertSee($copy['public_url'])
        ->assertSee($copy['workers'])
        ->assertSee($copy['backups'])
        ->assertSee($copy['language'])
        ->assertSee($copy['commands'])
        ->assertSee($copy['copy'])
        ->assertSee('aria-label="'.$copy['copy_named'].'"', false)
        ->assertSee($copy['note'])
        ->assertDontSee('Set up your installation')
        ->assertDontSee('Essential steps')
        ->assertDontSee('Mail transport')
        ->assertDontSee('Confirm background workers')
        ->assertDontSee('Recommended commands')
        ->assertDontSee('Mark confirmed');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach (['MAIL_MAILER', (string) config('mail.default'), 'APP_URL', 'QUEUE_CONNECTION', 'sync', 'English', 'UTC'] as $value) {
        $nodes = $xpath->query('//span[@lang="" and normalize-space(.)="'.$value.'"]');

        expect($nodes->length)->toBeGreaterThan(0, "missing language boundary for {$value}");
    }

    foreach (['php artisan queue:work', 'php artisan schedule:list'] as $command) {
        $node = $xpath->query('//code[@lang="" and normalize-space(.)="'.$command.'"]')->item(0);

        expect($node)->toBeInstanceOf(DOMElement::class, "missing language boundary for {$command}");
    }

    $note = $xpath->query('//input[@name="note"]')->item(0);
    expect($note)->toBeInstanceOf(DOMElement::class)
        ->and($note->hasAttribute('lang'))->toBeTrue()
        ->and($note->getAttribute('lang'))->toBe('')
        ->and($note->getAttribute('placeholder'))->toBe($copy['note']);
})->with([
    'German' => ['de', [
        'title' => 'Ihre Installation einrichten',
        'essential' => 'Grundlegende Schritte',
        'progress' => '0 von 5 bereit',
        'mail' => 'E-Mail-Versand',
        'public_url' => 'Öffentliche URL',
        'workers' => 'Hintergrundprozesse bestätigen',
        'backups' => 'Sicherung und Wiederherstellung',
        'language' => 'Sprache und Region',
        'commands' => 'Empfohlene Befehle',
        'copy' => 'Befehl kopieren',
        'copy_named' => 'Befehl php artisan queue:work kopieren',
        'note' => 'Optionale Notiz',
    ]],
    'Italian' => ['it', [
        'title' => 'Configurazione dell’installazione',
        'essential' => 'Passaggi essenziali',
        'progress' => '0 di 5 pronti',
        'mail' => 'Trasporto della posta',
        'public_url' => 'URL pubblico',
        'workers' => 'Conferma dei processi in background',
        'backups' => 'Copie di sicurezza e ripristino',
        'language' => 'Lingua e area geografica',
        'commands' => 'Comandi consigliati',
        'copy' => 'Copia comando',
        'copy_named' => 'Copia il comando php artisan queue:work',
        'note' => 'Nota facoltativa',
    ]],
]);

test('the ready onboarding state localizes confirmations without translating people or sites', function (string $locale, array $copy): void {
    greenExceptWorkers();
    $account = Account::factory()->create();
    $operator = onboardingOperator($account, $locale);
    $operator->update(['name' => 'Datenpunkt <operator>']);
    $site = Site::factory()->for($account)->create(['name' => 'Datenpunkt <site>']);

    confirmReadiness('backups_restore', $operator);
    confirmReadiness('background_workers', $operator);
    OperatorReadinessConfirmation::query()
        ->where('key', 'backups_restore')
        ->update(['confirmed_at' => now()->subHours(3)]);

    $response = $this->actingAs($operator)->get(route('operator.onboarding'));

    $response->assertOk()
        ->assertSee($copy['progress'])
        ->assertSee($copy['all_ready'])
        ->assertSee($copy['connect'])
        ->assertSee($copy['manage_mail'])
        ->assertSee($copy['confirmed'])
        ->assertSee($copy['confirmed_by'])
        ->assertSee($copy['age'])
        ->assertSee('Datenpunkt &lt;operator&gt;', false)
        ->assertSee('Datenpunkt &lt;site&gt;', false)
        ->assertDontSee('Datenpunkt <operator>', false)
        ->assertDontSee('Datenpunkt <site>', false)
        ->assertDontSee('All essentials ready')
        ->assertDontSee('Confirmation complete')
        ->assertDontSee('Confirmed by');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach (['Datenpunkt <operator>', 'Datenpunkt <site>'] as $value) {
        $node = $xpath->query('//*[@lang="" and normalize-space(.)="'.$value.'"]')->item(0);

        expect($node)->toBeInstanceOf(DOMElement::class, "missing language boundary for {$value}");
    }

    expect($xpath->query('//strong[@lang="" and normalize-space(.)="Datenpunkt <site>"]')->length)->toBe(1);
})->with([
    'German' => ['de', [
        'progress' => '5 von 5 bereit',
        'all_ready' => 'Alle grundlegenden Schritte bereit',
        'connect' => 'Erste Website verbinden',
        'manage_mail' => 'E-Mail-Einstellungen verwalten',
        'confirmed' => 'Bestätigung abgeschlossen',
        'confirmed_by' => 'Bestätigt von',
        'age' => 'vor 3 Stunden',
    ]],
    'Italian' => ['it', [
        'progress' => '5 di 5 pronti',
        'all_ready' => 'Tutti i passaggi essenziali sono pronti',
        'connect' => 'Collegamento del primo sito',
        'manage_mail' => 'Gestisci le impostazioni della posta',
        'confirmed' => 'Passaggio confermato',
        'confirmed_by' => 'Confermato da',
        'age' => '3 ore fa',
    ]],
]);

test('readiness confirmation validation and feedback follow the onboarding language', function (string $locale, array $copy): void {
    $operator = onboardingOperator(locale: $locale);

    $this->actingAs($operator)
        ->from(route('operator.onboarding'))
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'backups_restore',
            'redirect_to' => 'onboarding',
            'note' => str_repeat('x', 501),
        ])
        ->assertRedirect(route('operator.onboarding'))
        ->assertSessionHasErrors(['note']);

    expect((string) session('errors')->first('note'))->toContain($copy['note_attribute']);

    $this->actingAs($operator)
        ->from(route('operator.onboarding'))
        ->followingRedirects()
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'backups_restore',
            'redirect_to' => 'onboarding',
            'note' => 'Datenpunkt evidence',
        ])
        ->assertOk()
        ->assertSee($copy['saved'])
        ->assertDontSee('Readiness confirmation saved.');
})->with([
    'German' => ['de', [
        'note_attribute' => 'Bestätigungsnotiz',
        'saved' => 'Bereitschaftsbestätigung gespeichert.',
    ]],
    'Italian' => ['it', [
        'note_attribute' => 'Nota di conferma',
        'saved' => 'Salvataggio della verifica di preparazione completato.',
    ]],
]);

test('each mail-readiness edge state selects its localized onboarding copy', function (array $overrides, string $expected, string $value): void {
    $settings = app(OperatorSettings::class);

    foreach ($overrides as $key => $override) {
        $settings->set($key, $override);
    }

    // Production applies stored operator settings before serving a request. The
    // test process boots as an Artisan command, so exercise that same boundary
    // explicitly rather than mutating config that the runtime layer owns.
    $settings->applyOverrides();

    $response = $this->actingAs(onboardingOperator(locale: 'de'))
        ->get(route('operator.onboarding'));

    $response->assertOk()
        // Technical values are wrapped in lang="" spans, so assert on the
        // accessible text rather than requiring one contiguous HTML string.
        ->assertSeeText($expected)
        ->assertDontSeeText('Mail transport');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    expect($xpath->query('//span[@lang="" and normalize-space(.)="'.$value.'"]')->length)
        ->toBeGreaterThan(0, "missing language boundary for {$value}");
})->with([
    'unset transport' => [[
        'mail.mailer' => '',
    ], 'MAIL_MAILER ist nicht festgelegt.', 'MAIL_MAILER'],
    'local SMTP host' => [[
        'mail.mailer' => 'smtp',
        'mail.host' => '127.0.0.1',
        'mail.port' => '2525',
        'mail.scheme' => '',
        'mail.from_address' => 'support@example.test',
    ], 'SMTP verweist weiterhin auf einen lokalen E-Mail-Host.', '127.0.0.1'],
    'unsupported SMTP scheme' => [[
        'mail.mailer' => 'smtp',
        'mail.host' => 'smtp.example.test',
        'mail.port' => '587',
        'mail.scheme' => 'tls-datenpunkt',
        'mail.from_address' => 'support@example.test',
    ], 'SMTP verwendet einen nicht unterstützten Wert für', 'tls-datenpunkt'],
    'missing sender' => [[
        'mail.mailer' => 'smtp',
        'mail.host' => 'smtp.example.test',
        'mail.port' => '587',
        'mail.scheme' => '',
        'mail.from_address' => '',
    ], 'MAIL_FROM_ADDRESS fehlt.', 'MAIL_FROM_ADDRESS'],
    'placeholder sender' => [[
        'mail.mailer' => 'smtp',
        'mail.host' => 'smtp.example.test',
        'mail.port' => '587',
        'mail.scheme' => '',
        'mail.from_address' => 'hello@example.test',
    ], 'MAIL_FROM_ADDRESS sieht weiterhin wie ein Platzhalter aus.', 'MAIL_FROM_ADDRESS'],
]);

test('a scheduler-only confirmation does not complete the background-workers essential', function (): void {
    greenExceptWorkers();
    $operator = onboardingOperator();

    // Fresh scheduler + backups proofs exist, but no dedicated background-workers
    // proof — scheduler-only evidence must not satisfy worker readiness.
    confirmReadiness('scheduler', $operator);
    confirmReadiness('backups_restore', $operator);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('4 of 5 ready')
        ->assertDontSee('All essentials ready');
});

test('confirming background workers completes the checklist', function (): void {
    greenExceptWorkers();
    $operator = onboardingOperator();

    confirmReadiness('backups_restore', $operator);
    confirmReadiness('background_workers', $operator);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('5 of 5 ready')
        ->assertSee('All essentials ready');
});

test('the checklist is scoped to its own items, not the full diagnostic', function (): void {
    // A misconfigured scanner would surface on the full operator diagnostic, but
    // the focused checklist must neither run nor depend on unrelated probes
    // (attachment storage / ClamAV), so it renders regardless.
    config()->set('wayfindr.attachments.scanner.driver', 'bogus-unreachable');

    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertDontSee('Attachment scanning')
        ->assertDontSee('Attachment storage');
});

test('the onboarding checklist shows the connect-your-first-site card', function (): void {
    $account = Account::factory()->create();
    $operator = onboardingOperator($account);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Connect your first site')
        ->assertSee('Acme Docs')
        ->assertSee(route('dashboard.sites.show', $site).'#install-snippet', false);
});

test('the onboarding checklist links back to the full operator diagnostic', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee(route('operator.dashboard'), false)
        ->assertSee('full instance diagnostic');
});

test('the connect-site card is hidden for a site this operator cannot view', function (): void {
    $account = Account::factory()->create();
    $operator = onboardingOperator($account);
    // The site has explicit support agents that exclude this operator, so it is
    // outside their visibility (SitePolicy::view would 404 the link).
    $otherAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['name' => 'Restricted Docs']);
    $site->supportAgents()->attach($otherAgent->id);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertDontSee('Connect your first site')
        ->assertDontSee('Restricted Docs');
});

test('the onboarding confirmation form carries a return path back to onboarding', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('name="redirect_to" value="onboarding"', false);
});

test('confirming a step from onboarding returns to the checklist, not the dashboard', function (): void {
    $this->actingAs(onboardingOperator())
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'scheduler',
            'redirect_to' => 'onboarding',
        ])
        ->assertRedirect(route('operator.onboarding'));
});

test('confirming a step without a return path still lands on the operator dashboard', function (): void {
    $this->actingAs(onboardingOperator())
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'scheduler',
        ])
        ->assertRedirect(route('operator.dashboard'));
});
