<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\DashboardLanguage;
use App\Support\DashboardTimezone;
use App\Support\ReaderClock;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function localizationOperator(): User
{
    return User::factory()->for(Account::factory())->create(['platform_role' => 'operator']);
}

test('an operator sets the install language and timezone in the browser', function (): void {
    $this->actingAs(localizationOperator())
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect(route('operator.settings.localization.edit'))
        ->assertSessionHas('status');

    $settings = app(OperatorSettings::class);

    expect($settings->effective('localization.language'))->toBe('de')
        ->and($settings->effective('localization.timezone'))->toBe('Europe/Berlin');
});

/**
 * The claim worth testing is not that a row was written. It is that an agent
 * who has chosen nothing now reads what the operator chose -- which is the
 * whole point of moving these out of env, and the only part a stored value
 * cannot demonstrate on its own.
 */
test('what the operator chose is what an agent with no preference reads', function (): void {
    $this->actingAs(localizationOperator())
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    // A fresh resolution, the way the next request would see it.
    app(OperatorSettings::class)->applyOverrides();

    $agent = User::factory()->for(Account::factory())->create(['locale' => null, 'timezone' => null]);

    expect(DashboardLanguage::for($agent))->toBe('de')
        ->and(ReaderClock::zone($agent))->toBe('Europe/Berlin');
});

test('an agent who has chosen for themselves keeps their own answer', function (): void {
    $this->actingAs(localizationOperator())
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    app(OperatorSettings::class)->applyOverrides();

    $agent = User::factory()->for(Account::factory())->create([
        'locale' => 'it',
        'timezone' => 'Asia/Tokyo',
    ]);

    expect(DashboardLanguage::for($agent))->toBe('it')
        ->and(ReaderClock::zone($agent))->toBe('Asia/Tokyo');
});

test('the stored setting overrides the environment default', function (): void {
    Config::set('wayfindr.dashboard_locale', 'en');
    Config::set('wayfindr.dashboard_timezone', 'UTC');

    $this->actingAs(localizationOperator())
        ->post(route('operator.settings.localization.update'), [
            'language' => 'it',
            'timezone' => 'Asia/Tokyo',
        ])
        ->assertRedirect();

    app(OperatorSettings::class)->applyOverrides();

    expect(DashboardLanguage::for(null))->toBe('it')
        ->and(DashboardTimezone::installDefault())->toBe('Asia/Tokyo');
});

test('a language or zone the platform cannot render is refused, not stored', function (): void {
    $operator = localizationOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'kl',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertSessionHasErrors('language');

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->assertSessionHasErrors('timezone');

    $settings = app(OperatorSettings::class);

    expect($settings->isSet('localization.language'))->toBeFalse()
        ->and($settings->isSet('localization.timezone'))->toBeFalse();
});

/**
 * Changing the display clock must never move the storage clock. `app.timezone`
 * is what Laravel writes `created_at` through, into columns that carry no
 * offset -- pointing it anywhere but UTC corrupts rows rather than formatting
 * them.
 */
test('setting the install timezone leaves the storage clock alone', function (): void {
    $this->actingAs(localizationOperator())
        ->post(route('operator.settings.localization.update'), [
            'language' => 'en',
            'timezone' => 'Asia/Tokyo',
        ])
        ->assertRedirect();

    app(OperatorSettings::class)->applyOverrides();

    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});

test('the change is recorded as an instance event, with no tenant attached', function (): void {
    $operator = localizationOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    $event = AuditEvent::query()->where('action', 'operator_settings.localization.updated')->sole();

    expect($event->account_id)->toBeNull('instance-wide config is not a tenant event')
        ->and($event->actor_id)->toBe($operator->id)
        ->and($event->metadata['language'])->toBe('de')
        ->and($event->metadata['timezone'])->toBe('Europe/Berlin');
});

test('the page offers every language and zone, and is reachable from the console', function (): void {
    $operator = localizationOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), ['language' => 'de', 'timezone' => 'Europe/Berlin'])
        ->assertRedirect();

    $this->actingAs($operator)
        ->get(route('operator.settings.localization.edit'))
        ->assertOk()
        // The application container is shared across test requests, so the
        // boot-time config override still carries this test's English env
        // default. The locale-specific render below proves the translated
        // page; this assertion is about the selected install value.
        ->assertSee('Language and region')
        ->assertSee('value="de" selected', escape: false)
        ->assertSee('value="Europe/Berlin" selected', escape: false)
        ->assertSee('<optgroup lang="" label="Europe">', escape: false);

    // Discoverable, not just addressable: an operator who does not know the URL
    // has to be able to find it.
    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee(route('operator.settings.localization.edit'), escape: false);
});

test('the language and region page follows the operator language', function (string $locale, array $copy): void {
    $account = Account::factory()->create(['name' => 'Datenpunkt Account']);
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'locale' => $locale,
        'name' => 'Olive Datenpunkt',
    ]);

    $response = $this->actingAs($operator)->get(route('operator.settings.localization.edit'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee($copy['title'])
        ->assertSee($copy['subtitle'])
        ->assertSee($copy['defaults'])
        ->assertSee($copy['language_help'])
        ->assertSee($copy['timezone_help'])
        ->assertSee($copy['save'])
        ->assertSee($copy['sections_label'])
        ->assertSee($copy['console'])
        ->assertSee($copy['back_to_console'])
        ->assertDontSee('What the dashboard reads in for anyone who has not chosen for themselves.')
        ->assertDontSee('Install defaults')
        ->assertDontSee('Save language and region');

    $this->actingAs($operator)
        ->get(route('operator.settings.localization.edit', ['from' => 'onboarding']))
        ->assertOk()
        ->assertSee($copy['back_to_setup']);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach (DashboardLanguage::SUPPORTED as $code => $label) {
        $option = $xpath->query('//select[@id="language"]/option[@value="'.$code.'"]')->item(0);

        expect($option)->toBeInstanceOf(DOMElement::class)
            ->and($option->getAttribute('lang'))->toBe($code)
            ->and(trim($option->textContent))->toBe($label);
    }

    $zone = $xpath->query('//select[@id="timezone"]/optgroup[@label="Europe"]/option[@value="Europe/Berlin"]')->item(0);
    $region = $xpath->query('//select[@id="timezone"]/optgroup[@label="Europe"]')->item(0);
    $heading = $xpath->query('//*[@id="localization-config-heading"]')->item(0);

    expect($zone)->toBeInstanceOf(DOMElement::class)
        ->and($zone->hasAttribute('lang'))->toBeTrue()
        ->and($zone->getAttribute('lang'))->toBe('')
        ->and($region)->toBeInstanceOf(DOMElement::class)
        ->and($region->hasAttribute('lang'))->toBeTrue()
        ->and($region->getAttribute('lang'))->toBe('')
        ->and($heading)->toBeInstanceOf(DOMElement::class)
        ->and($heading->hasAttribute('lang'))->toBeFalse();

    foreach (['Olive Datenpunkt', 'Datenpunkt Account'] as $value) {
        expect($xpath->query('//*[@lang="" and normalize-space(.)="'.$value.'"]')->length)
            ->toBeGreaterThan(0, "{$value} is not marked as language-neutral shell data");
    }
})->with([
    'German' => ['de', [
        'title' => 'Sprache und Region',
        'subtitle' => 'Die Sprache des Dashboards für alle, die selbst keine ausgewählt haben.',
        'defaults' => 'Installationsstandards',
        'language_help' => 'Gilt für das Agenten-Dashboard.',
        'timezone_help' => 'Uhrzeiten und Berichtstage werden nach der eingestellten Zeitzone angezeigt.',
        'save' => 'Sprache und Region speichern',
        'sections_label' => 'Betreiberbereiche',
        'console' => 'Konsole',
        'back_to_console' => 'Zurück zur Betreiberkonsole',
        'back_to_setup' => 'Zurück zur Einrichtungscheckliste',
    ]],
    'Italian' => ['it', [
        'title' => 'Lingua e area geografica',
        'subtitle' => 'La lingua usata dalla dashboard per chi non ne ha scelta una.',
        'defaults' => 'Impostazioni predefinite dell’installazione',
        'language_help' => 'Si applica alla dashboard degli agenti.',
        'timezone_help' => 'Gli orari e i giorni dei report seguono questo fuso.',
        'save' => 'Salva lingua e area geografica',
        'sections_label' => 'Sezioni del gestore',
        'console' => 'Pannello di controllo',
        'back_to_console' => 'Torna alla console del gestore',
        'back_to_setup' => 'Torna alla checklist di configurazione',
    ]],
]);

test('localization validation and completion answer in the operator language', function (string $locale, string $languageError, string $timezoneError, string $flash): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'locale' => $locale,
    ]);

    $this->actingAs($operator)
        ->from(route('operator.settings.localization.edit'))
        ->post(route('operator.settings.localization.update'), [
            'language' => 'kl',
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->assertRedirect(route('operator.settings.localization.edit'))
        ->assertSessionHasErrors(['language', 'timezone']);

    expect((string) session('errors')->first('language'))->toBe($languageError)
        ->and((string) session('errors')->first('timezone'))->toBe($timezoneError);

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('operator.settings.localization.update'), [
            'language' => $locale,
            'timezone' => 'Europe/Berlin',
        ])
        ->assertOk()
        ->assertSee($flash)
        ->assertDontSee('Language and region saved. Agents who have not chosen their own now read this.');
})->with([
    'German' => [
        'de',
        'Der gewählte Wert für Sprache ist ungültig.',
        'Der gewählte Wert für Zeitzone ist ungültig.',
        'Sprache und Region gespeichert. Agenten ohne eigene Auswahl lesen das Dashboard jetzt so.',
    ],
    'Italian' => [
        'it',
        'Il valore selezionato per Lingua non è valido.',
        'Il valore selezionato per Fuso orario non è valido.',
        'Lingua e area geografica salvate. Gli agenti che non hanno effettuato una scelta ora leggono la dashboard in questo modo.',
    ],
]);

test('operator pages outside the extracted slice remain wholly English', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'locale' => 'de',
    ]);

    $this->actingAs($operator)
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('aria-label="Operator sections"', false)
        ->assertSee('Setup checklist')
        ->assertDontSee('Betreiberbereiche')
        ->assertDontSee('Einrichtungscheckliste');
});

test('nobody but a platform operator can read or change it', function (): void {
    $agent = User::factory()->for(Account::factory())->create(['platform_role' => null]);

    $this->actingAs($agent)->get(route('operator.settings.localization.edit'))->assertForbidden();
    $this->actingAs($agent)
        ->post(route('operator.settings.localization.update'), ['language' => 'de', 'timezone' => 'Europe/Berlin'])
        ->assertForbidden();

    expect(app(OperatorSettings::class)->isSet('localization.language'))->toBeFalse();
});

/**
 * The issue's first bullet: asked once during setup, rather than discovered
 * later as a defect. A wrong clock is not a fault anyone reports -- the product
 * simply reads foreign -- so the checklist has to raise it unprompted.
 */
test('first-run setup asks for the language and region, and stops once answered', function (): void {
    $operator = localizationOperator();

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Language and region')
        ->assertSee('Nobody has confirmed that is right.')
        ->assertSee('Set language and region')
        // The step's Configure button must carry the origin, or saving returns
        // the operator to the console instead of back to the checklist.
        ->assertSee(route('operator.settings.localization.edit', ['from' => 'onboarding']), escape: false);

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
            'from' => 'onboarding',
        ])
        ->assertRedirect(route('operator.settings.localization.edit', ['from' => 'onboarding']));

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('The dashboard reads in Deutsch, on Europe/Berlin.')
        ->assertDontSee('Nobody has confirmed that is right.')
        ->assertSee('Manage language and region');
});

test('answering only one half does not count as confirmed', function (): void {
    // Both are asked together and both must be answered, or the checklist would
    // go green on a clock nobody chose.
    app(OperatorSettings::class)->set('localization.language', 'de');

    $this->actingAs(localizationOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Nobody has confirmed that is right.');
});

/**
 * A stored value can stop being a real zone without anyone touching it: tzdata
 * retires identifiers, and the row that was right when it was saved is not
 * right after an upgrade. Presence alone would read as confirmed while the
 * dashboard quietly served UTC -- hiding exactly the unexpected clock this step
 * exists to surface.
 */
test('a stored setting that no longer resolves is not a confirmation', function (): void {
    $settings = app(OperatorSettings::class);
    $settings->set('localization.language', 'de');
    $settings->set('localization.timezone', 'Europe/Berlin');

    $this->actingAs(localizationOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertDontSee('Nobody has confirmed that is right.');

    // The zone is retired by a tzdata update; the row is untouched.
    $settings->set('localization.timezone', 'Europe/Atlantis');

    $this->actingAs(localizationOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Nobody has confirmed that is right.')
        // And it says what the reader will actually get, not what is stored.
        ->assertSee('on UTC.');
});

/**
 * The install default may be a backward-compatible alias -- `US/Eastern` --
 * which the dashboard honours but the canonical menu does not list. Left out
 * of the select, nothing matches, the browser shows the first option as
 * chosen, and the next save moves the INSTALL-WIDE clock to a zone nobody
 * picked. The agent profile was fixed for this; the operator page was written
 * on the other branch and kept the pre-fix shape.
 */
test('an alias install default stays selectable, and survives a save', function (): void {
    $operator = localizationOperator();
    app(OperatorSettings::class)->set('localization.timezone', 'US/Eastern');
    app(OperatorSettings::class)->set('localization.language', 'en');

    $this->actingAs($operator)
        ->get(route('operator.settings.localization.edit'))
        ->assertOk()
        ->assertSee('value="US/Eastern" selected', escape: false);

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'en',
            'timezone' => 'US/Eastern',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(OperatorSettings::class)->effective('localization.timezone'))->toBe('US/Eastern');
});

/**
 * Allow-listing the action alone is the half-wired trap: the body method falls
 * through to a readiness `match`, so a settings change would be captioned
 * "Instance readiness proof was recorded" -- described as something it is not.
 */
test('a language and region change appears in the operator activity feed, described correctly', function (): void {
    $operator = localizationOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Language and region updated')
        ->assertSee('Dashboard language and region were updated (language: de, timezone: Europe/Berlin).')
        ->assertSee('Deutsch')
        ->assertDontSee('Instance readiness proof was recorded.');
});

/**
 * The checklist is what an operator opens when something is wrong, so it has
 * to render when something is wrong.
 *
 * With the documented `SESSION_DRIVER=database` and `CACHE_STORE=redis` an
 * operator stays signed in through a Redis outage. The settings store is read
 * during the request, and the service provider's boot-time catch does not
 * cover a later read -- so this step would have answered 500 on exactly the
 * page that exists to diagnose the outage.
 */
test('the setup checklist still renders when the settings store is unreachable', function (): void {
    $operator = localizationOperator();

    // The install is configured through the environment, which is what the
    // service provider leaves standing in config when the store is gone.
    config()->set('wayfindr.dashboard_locale', 'de');
    config()->set('wayfindr.dashboard_timezone', 'Europe/Berlin');

    // The store goes away mid-session.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));
    Cache::shouldReceive('forget')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Language and region')
        // Unreachable is not "ready": what was chosen is unknown, and the env
        // fallback is what the dashboard is serving meanwhile.
        ->assertSee('Nobody has confirmed that is right.')
        // And it names the clock the dashboard is ACTUALLY on. Reporting the
        // hardcoded defaults would be a second wrong answer during the outage.
        ->assertSee('Deutsch')
        ->assertSee('Europe/Berlin')
        ->assertDontSee('on UTC.');
});

/**
 * Both readiness surfaces or neither.
 *
 * Present only on the onboarding checklist, this step let the two screens
 * contradict each other about the same install: onboarding asking an operator
 * to confirm the clock while the console beside it reported Ready. Whichever
 * screen they happened to open decided whether there was a problem.
 */
test('the operator console raises language and region too, not just the checklist', function (): void {
    $operator = localizationOperator();

    $console = $this->actingAs($operator)->get(route('operator.dashboard'))->assertOk();

    $console->assertSee('Language and region')
        ->assertSee('Nobody has confirmed that is right.');

    $this->actingAs($operator)
        ->post(route('operator.settings.localization.update'), [
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertRedirect();

    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Language and region')
        ->assertDontSee('Nobody has confirmed that is right.');
});
