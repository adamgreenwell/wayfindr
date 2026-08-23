<?php

use App\Models\Account;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

uses(RefreshDatabase::class);

/**
 * An install with nobody in it redirects sign-in to first-run setup, so a test
 * about the sign-in page has to give it somebody to sign in.
 */
function installedWorkspace(): void
{
    User::factory()->for(Account::factory())->create();
}

/**
 * The dashboard being readable in a language other than English.
 *
 * Extraction is proceeding a surface at a time. What these tests hold is not
 * "the console is translated" -- it is not -- but that the seam works, that
 * English did not change while being moved, and that a half-finished language
 * cannot be offered to anybody.
 */
test('the sign-in surface renders in the active language', function (): void {
    installedWorkspace();

    App::setLocale('de');

    $this->get('/login')
        ->assertOk()
        ->assertSee('Anmelden', false)
        ->assertSee('Passwort vergessen?', false)
        ->assertSee('Diesen Browser merken', false);
});

test('moving copy into a catalogue did not change a word of it', function (): void {
    installedWorkspace();

    // The whole suite asserts English prose in roughly three thousand places.
    // Extraction has to be invisible in English or it is not extraction, it is
    // a rewrite.
    $this->get('/login')
        ->assertOk()
        ->assertSee('Agent Login')
        ->assertSee('Sign in to your Wayfindr support workspace.')
        ->assertSee('Remember this browser')
        ->assertSee('Forgotten your password?');

    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee('Reset your password')
        ->assertSee('We will email you a link to set a new one.')
        ->assertSee('Email me a reset link');
});

test('a language either answers every question English answers, or it is out of parity', function (): void {
    // Read from the files, so a key added to en and forgotten in de fails here
    // rather than showing one English sentence in an otherwise German page.
    foreach (array_keys(Locales::CARRIED) as $locale) {
        expect(Locales::missingKeys($locale))->toBe([], $locale.' is missing keys English has');
    }

    expect(Locales::keys('en'))->not->toBeEmpty();
});

test('no half-translated console is offered to anybody', function (): void {
    // Parity is a drift check, not a readiness signal: `de` matches `en` across
    // everything extracted so far while most of the console is still English
    // literals no catalogue knows about.
    expect(Locales::hasFullParity('de'))->toBeTrue()
        ->and(Locales::EXTRACTION_COMPLETE)->toBeFalse()
        ->and(array_keys(Locales::offerable()))->toBe(['en']);
});

test('the document says which language it is in, and which way it runs', function (): void {
    installedWorkspace();

    $english = $this->get('/login')->assertOk()->getContent();

    expect($english)->toContain('lang="en"')
        ->and($english)->toContain('dir="ltr"');

    App::setLocale('de');
    $german = $this->get('/login')->assertOk()->getContent();

    expect($german)->toContain('lang="de"')
        ->and($german)->toContain('dir="ltr"');
});

test('a right-to-left language lays the console out right-to-left', function (): void {
    installedWorkspace();

    // No right-to-left catalogue ships, so this is the mechanism rather than a
    // shipped language. Direction is a layout question and is answerable for
    // any language, which is why it can be settled before the words exist.
    expect(Locales::direction('ar'))->toBe('rtl')
        ->and(Locales::direction('he_IL'))->toBe('rtl')
        ->and(Locales::direction('ur-PK'))->toBe('rtl')
        ->and(Locales::direction('de'))->toBe('ltr')
        ->and(Locales::direction('en_GB'))->toBe('ltr');

    App::setLocale('ar');

    expect($this->get('/login')->assertOk()->getContent())->toContain('dir="rtl"');
});
