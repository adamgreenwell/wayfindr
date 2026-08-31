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
        ->assertSee('Language and region')
        ->assertSee('value="de" selected', escape: false)
        ->assertSee('value="Europe/Berlin" selected', escape: false)
        ->assertSee('<optgroup label="Europe">', escape: false);

    // Discoverable, not just addressable: an operator who does not know the URL
    // has to be able to find it.
    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee(route('operator.settings.localization.edit'), escape: false);
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
