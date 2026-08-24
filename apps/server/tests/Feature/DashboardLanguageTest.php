<?php

use App\Models\Account;
use App\Models\User;
use App\Support\DashboardLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Operators who self-host frequently do so for data-residency reasons, which
 * correlates almost exactly with not working primarily in English (#749). The
 * product's strongest advantage was aimed at the buyers least served by its
 * only language.
 */
function languageAgent(?string $locale = null): User
{
    return User::factory()->for(Account::factory())->create(['locale' => $locale]);
}

test('an agent with no preference reads the install default', function (): void {
    // Every agent predates this column, so "not chosen" is the common case and
    // has to be the safe one rather than a broken page.
    $this->actingAs(languageAgent())
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Alert preferences')
        ->assertDontSee('Benachrichtigungseinstellungen');
});

test('an agent who chose German reads German', function (): void {
    // The seam, end to end: preference on the row, middleware, catalogue,
    // rendered page.
    $this->actingAs(languageAgent('de'))
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Benachrichtigungseinstellungen')
        ->assertSee('Passwort ändern')
        ->assertDontSee('Alert preferences');
});

test('copy generated outside the view is translated too', function (): void {
    // The blind spot in any extraction: labels built in a controller or model
    // never appear in the view, so grepping the Blade file says they are done
    // when they are not. These come from `User::alertModeOptions()` and the
    // controller's role map.
    $this->actingAs(languageAgent('de'))
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Ruhemodus')
        ->assertSee('Sammelzustellung bevorzugen, wenn verfügbar')
        ->assertDontSee('Quiet mode');
});

test('one agent choosing a language does not change it for anyone else', function (): void {
    // Per agent, not per account: a team spread across countries should not
    // have to agree with a colleague first.
    $account = Account::factory()->create();
    $german = User::factory()->for($account)->create(['locale' => 'de']);
    $english = User::factory()->for($account)->create(['locale' => null]);

    $this->actingAs($german)->get(route('dashboard.profile.show'))->assertSee('Ihr Profil');
    $this->actingAs($english)->get(route('dashboard.profile.show'))->assertSee('Your profile');
});

test('an agent can choose a language and then stop having chosen one', function (): void {
    $agent = languageAgent();

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'locale' => 'de'])
        ->assertRedirect();

    expect($agent->fresh()->locale)->toBe('de');

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'locale' => ''])
        ->assertRedirect();

    expect($agent->fresh()->locale)->toBeNull();
});

test('a language the dashboard cannot render is refused, not stored', function (): void {
    $agent = languageAgent();

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'locale' => 'kl'])
        ->assertSessionHasErrors('locale');

    expect($agent->fresh()->locale)->toBeNull();
});

test('a regional variant resolves to the language it is a variant of', function (): void {
    // `de-DE` and `de_DE` both mean German. Refusing one over its suffix would
    // be pedantry rather than accuracy.
    expect(DashboardLanguage::normalise('de-DE'))->toBe('de')
        ->and(DashboardLanguage::normalise('de_AT'))->toBe('de')
        ->and(DashboardLanguage::normalise('DE'))->toBe('de')
        ->and(DashboardLanguage::normalise('kl'))->toBeNull()
        ->and(DashboardLanguage::normalise(''))->toBeNull()
        ->and(DashboardLanguage::normalise(null))->toBeNull();
});

test('every catalogue carries the same keys', function (): void {
    // A key present in one language and missing from another renders the key
    // itself -- `profile.alerts.mode` on the page, which reads as a bug rather
    // than as an untranslated string.
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($items as $key => $value) {
            $keys[] = $prefix.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $flatten($value, $prefix.$key.'.'));
            }
        }

        return $keys;
    };

    foreach (glob(lang_path('en/*.php')) as $english) {
        $file = basename($english);
        $german = lang_path('de/'.$file);

        expect(file_exists($german))->toBeTrue("lang/de/{$file} is missing");

        $englishKeys = $flatten(require $english);
        $germanKeys = $flatten(require $german);

        sort($englishKeys);
        sort($germanKeys);

        expect($germanKeys)->toBe($englishKeys, "lang/de/{$file} does not match lang/en/{$file}");
    }
});

test('the extracted surface has no copy left behind in its view', function (): void {
    // Structural, because a missed string is invisible until somebody switches
    // language: the page renders, in two languages at once, and only a reader
    // of that language notices.
    $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/agent/profile/show.blade.php');

    expect($view)->not->toBeFalse();

    // Prose between tags. Anything left is copy the catalogue does not own.
    preg_match_all('/>[A-Z][a-z][^<>{}@]{6,}</', (string) $view, $matches);

    expect($matches[0])->toBe([]);
});
