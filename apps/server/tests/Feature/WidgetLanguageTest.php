<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\WidgetLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function languageWorld(AccountRole $role = AccountRole::Admin): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => $role]);

    return compact('account', 'site', 'agent');
}

test('the languages an operator is offered are the ones the widget can actually speak', function (): void {
    // The list lives twice because the widget has no build step and cannot read
    // PHP. Offering a language whose catalogue was never added would set a site
    // default the widget silently ignores, which looks like the setting is
    // broken rather than missing.
    $widget = file_get_contents(base_path('../../packages/widget-js/src/wayfindr-widget.js'));

    expect($widget)->toBeString();

    $start = strpos($widget, 'var MESSAGES = {');

    expect($start)->not->toBeFalse();

    preg_match_all('/^    ([a-z]{2}(?:-[a-z]{2})?): \{$/m', substr($widget, $start), $matches);

    $shipped = $matches[1];

    sort($shipped);
    $offered = array_keys(WidgetLanguage::SUPPORTED);
    sort($offered);

    expect($shipped)->not->toBeEmpty()
        ->and($offered)->toBe($shipped);
});

test('a site with no language configured lets the visitor decide', function (): void {
    $world = languageWorld();

    // Null is what every install had before this setting existed, and it stays
    // the default: follow the browser, fall back to English.
    expect(WidgetLanguage::for($world['site']))->toBeNull();

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => 'anon-language',
    ])->assertSuccessful();

    expect($response->json('data.site.locale'))->toBeNull();
});

test('a configured language reaches the widget through the bootstrap payload', function (): void {
    $world = languageWorld();

    $this->actingAs($world['agent'])
        ->put("/dashboard/sites/{$world['site']->id}/language", ['widget_locale' => 'de'])
        ->assertRedirect();

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => 'anon-language',
    ])->assertSuccessful();

    expect($response->json('data.site.locale'))->toBe('de');
});

test('a language the widget does not carry is refused rather than stored', function (): void {
    $world = languageWorld();

    $this->actingAs($world['agent'])
        ->put("/dashboard/sites/{$world['site']->id}/language", ['widget_locale' => 'kl'])
        ->assertSessionHasErrors('widget_locale');

    expect(WidgetLanguage::for($world['site']->fresh()))->toBeNull();
});

test('a stored language that is no longer shipped reads as unconfigured', function (): void {
    $world = languageWorld();

    // A catalogue removed, or a hand-edited settings blob. Passing it through
    // would tell the widget it had been overruled, and it would fall back to
    // English having already discarded the visitor's browser answer.
    $world['site']->forceFill(['settings' => ['locale' => 'kl']])->save();

    expect(WidgetLanguage::for($world['site']->fresh()))->toBeNull();
});

test('choosing a language does not disturb the rest of a site’s settings', function (): void {
    $world = languageWorld();

    $world['site']->forceFill(['settings' => [
        'mask_selectors' => ['#card'],
        'intake' => ['fields' => ['email' => 'required'], 'intro' => 'Tell us who you are.'],
    ]])->save();

    $this->actingAs($world['agent'])
        ->put("/dashboard/sites/{$world['site']->id}/language", ['widget_locale' => 'de'])
        ->assertRedirect();

    $settings = $world['site']->fresh()->settings;

    // One form must not blank another's fields by omitting them.
    expect($settings['locale'])->toBe('de')
        ->and($settings['mask_selectors'])->toBe(['#card'])
        ->and($settings['intake']['intro'])->toBe('Tell us who you are.');
});

test('an agent who cannot manage the site cannot change its language', function (): void {
    $world = languageWorld(AccountRole::Agent);

    $this->actingAs($world['agent'])
        ->put("/dashboard/sites/{$world['site']->id}/language", ['widget_locale' => 'de'])
        ->assertForbidden();

    expect(WidgetLanguage::for($world['site']->fresh()))->toBeNull();
});

test('the site page offers the languages and says what the setting does not decide', function (): void {
    $world = languageWorld();

    $this->actingAs($world['agent'])
        ->get("/dashboard/sites/{$world['site']->id}")
        ->assertOk()
        ->assertSee('What language the widget speaks', false)
        ->assertSee("Follow the visitor's browser", false)
        ->assertSee('Deutsch (German)', false)
        // The host page's own override outranks this, and an operator reading
        // this screen is the person who would need to know that.
        ->assertSee('data-wayfindr-locale', false);
});
