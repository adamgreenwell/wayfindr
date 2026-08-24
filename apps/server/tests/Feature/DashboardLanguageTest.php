<?php

use App\Models\Account;
use App\Models\User;
use App\Support\DashboardLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;

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

test('an install that sets its own language gets it, for agents who chose nothing', function (): void {
    // "Use the install default" has to mean the install's default. Every agent
    // on an upgraded install has no preference, so this IS the common path.
    config()->set('wayfindr.dashboard_locale', 'de');

    $this->actingAs(languageAgent())
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Ihr Profil')
        ->assertDontSee('Your profile');
});

test('rendering for one agent does not change the default for the next', function (): void {
    // `App::setLocale()` mutates `config('app.locale')`, so reading the install
    // default from there meant a request rendered for a German agent left the
    // config saying "de" -- and the next agent with no preference silently
    // inherited a language they never chose. Two requests in one process is all
    // it took.
    $account = Account::factory()->create();
    $german = User::factory()->for($account)->create(['locale' => 'de']);
    $unset = User::factory()->for($account)->create(['locale' => null]);

    $this->actingAs($german)->get(route('dashboard.profile.show'))->assertOk();

    $this->actingAs($unset)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Your profile')
        ->assertDontSee('Ihr Profil');
});

test('nothing on the profile page reads the same in both languages', function (): void {
    // Rewritten, because the previous version could not see the thing it was
    // for. It checked that no string FROM THE CATALOGUE leaked into a German
    // render -- which says nothing about copy that was never extracted, and
    // three separate misses got past it: two readiness details, the persisted
    // digest message, and every success flash.
    //
    // This compares the two renders instead. Any sentence that survives a
    // change of language unchanged is either untranslated or a proper noun, and
    // the allowlist below has to name which.
    // Rendered in several STATES, not just the default one. Copy that only
    // appears on a branch -- a cadence nobody selected, a digest that has run,
    // the flash after a save -- is exactly the copy an extraction misses, and
    // a single default render reaches none of it. The first version of this
    // test compared one page in one state and let three real misses through.
    // Named, so the test can tell the account's DATA apart from its copy: a
    // name renders identically in both languages and should.
    $account = Account::factory()->create(['name' => 'Acme Support Datenpunkt']);

    $states = [
        'default' => [],
        'unattended, email off' => [
            'alert_preferences' => ['cadence' => User::ALERT_CADENCE_UNATTENDED, 'email' => false],
        ],
        'unattended, email on' => [
            'alert_preferences' => ['cadence' => User::ALERT_CADENCE_UNATTENDED, 'email' => true],
        ],
        'digest already run' => [
            'alert_preferences' => [
                'cadence' => User::ALERT_CADENCE_DIGEST,
                'email' => true,
                'digest_delivery' => [
                    'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                    'candidate_count' => 3,
                    'message' => 'Queued digest email with 3 alerts.',
                    'last_attempted_at' => now()->subHour()->toJSON(),
                ],
            ],
        ],
    ];

    $sentences = function (string $html): array {
        // The page's own region, not the shell around it. The topbar, search
        // and navigation belong to the app shell, which is its own surface and
        // extracts with itself -- comparing them here would report the shell's
        // copy as this page's debt forever.
        //
        // A boundary the test can enforce, rather than an allowlist of strings
        // that drifts the moment somebody edits one.
        if (preg_match('/<main\b[^>]*>(.*)<\/main>/is', $html, $main) === 1) {
            $html = $main[1];
        }

        // Script and style bodies survive `strip_tags`, and they are full of
        // English prose that is not copy -- comments, font paths, CSS. They are
        // not translated and never should be.
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($html);
        $lines = preg_split('/[\r\n]+/u', html_entity_decode($text)) ?: [];

        return collect($lines)
            ->map(fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''))
            // Long enough to be prose rather than a label, a number or a name.
            ->filter(fn (string $line): bool => mb_strlen($line) >= 25)
            ->unique()
            ->values()
            ->all();
    };

    // The one documented exception: `OperatorReadiness` supplies the mail
    // summary and action, and its vocabulary belongs to the operator console.
    // See docs/product/dashboard-language.md.
    // Matched on `MAIL_MAILER`, the config key that sentence names, rather than
    // on "mail". The first version of this allowlist said `str_contains('mail')`
    // -- which also matches every sentence containing "eMAIL", and therefore
    // exempted precisely the alert copy the exception was never meant to cover.
    // Three mutations survived behind it.
    $allowed = fn (string $line): bool => str_contains($line, 'MAIL_MAILER')
        // Data rather than copy. An account name, an agent name and an email
        // address read the same in every language, correctly.
        || str_contains($line, 'Acme Support Datenpunkt')
        || str_contains($line, 'Ada Agent')
        || str_contains($line, '@');

    foreach ($states as $label => $attributes) {
        $english = User::factory()->for($account)->create($attributes + ['locale' => 'en', 'name' => 'Ada Agent']);
        $german = User::factory()->for($account)->create($attributes + ['locale' => 'de', 'name' => 'Ada Agent']);

        $inEnglish = $sentences($this->actingAs($english)->get(route('dashboard.profile.show'))->getContent());
        $inGerman = $sentences($this->actingAs($german)->get(route('dashboard.profile.show'))->getContent());

        $shared = array_values(array_filter(
            array_intersect($inEnglish, $inGerman),
            fn (string $line): bool => ! $allowed($line),
        ));

        expect(array_values($shared))->toBe([], "untranslated copy in state: {$label}");
    }

    // And the flash after a save, which no GET can reach.
    $flashEnglish = User::factory()->for($account)->create(['locale' => 'en', 'name' => 'Ada Agent']);
    $flashGerman = User::factory()->for($account)->create(['locale' => 'de', 'name' => 'Ada Agent']);

    // Read from the RENDERED page, not from `session('status')`. The flash
    // travels as a catalogue key and is translated where it is displayed, so
    // the session holds the same key in both languages -- comparing it there
    // compares two identical keys and reports success no matter what the agent
    // actually sees.
    $flashOf = function (User $agent): string {
        // The locale goes with it. This PUT carries the whole form, so omitting
        // the select clears the preference -- and the German agent would then
        // be measured after being quietly turned back into an English one.
        $this->actingAs($agent)->put(route('dashboard.profile.update'), [
            'name' => 'Ada Agent',
            'locale' => (string) $agent->locale,
        ]);

        $html = $this->actingAs($agent)->get(route('dashboard.profile.show'))->getContent();

        preg_match('/<p class="status-message">(.*?)<\/p>/is', (string) $html, $flash);

        return trim($flash[1] ?? '');
    };

    $inGerman = $flashOf($flashGerman);
    $inEnglish = $flashOf($flashEnglish);

    expect($inGerman)->not->toBe('')
        ->and($inEnglish)->not->toBe('')
        // A raw key reaching the page is the specific failure of flashing one.
        ->and($inGerman)->not->toContain('profile.flash')
        ->and($inGerman)->not->toBe($inEnglish);
});

test('a validation failure is reported in the agent language', function (): void {
    // The error path is a normal path -- mistyping a current password is the
    // single most likely thing to go wrong on this page -- and it renders
    // through Laravel's own catalogue rather than the profile one, which the
    // rest of these tests never touch.
    $agent = languageAgent('de');

    // Followed to the page, because what matters is the sentence under the
    // field rather than the string in the bag.
    $this->actingAs($agent)
        ->from(route('dashboard.profile.show'))
        ->followingRedirects()
        ->put(route('dashboard.profile.password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'short',
            'password_confirmation' => 'mismatched',
        ])
        ->assertOk()
        ->assertSee('Das Passwort ist nicht korrekt.')
        ->assertSee('Die Bestätigung für Passwort stimmt nicht überein.')
        ->assertDontSee('The password is incorrect.')
        // Named as the form names it, not as the column spells it.
        ->assertDontSee('current_password muss');

    // A second submission for the length rule. The field renders only its
    // first error, so behind a mismatched confirmation the length message is
    // never on the page -- asserting it above would have passed against a
    // sentence nobody could see.
    $this->actingAs($agent)
        ->from(route('dashboard.profile.show'))
        ->followingRedirects()
        ->put(route('dashboard.profile.password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->assertOk()
        ->assertSee('Passwort muss mindestens 8 Zeichen lang sein.')
        ->assertDontSee('at least 8 characters');
});

test('a validation failure an agent can read is still English for anyone else', function (): void {
    $agent = languageAgent();

    $this->actingAs($agent)
        ->from(route('dashboard.profile.show'))
        ->followingRedirects()
        ->put(route('dashboard.profile.password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'short',
            'password_confirmation' => 'mismatched',
        ])
        ->assertOk()
        ->assertSee('The password is incorrect.')
        ->assertDontSee('Das Passwort ist nicht korrekt.');
});

test('a rule with no German line falls back to English, never to a raw key', function (): void {
    // The German catalogue covers the rules the dashboard validates with, not
    // all hundred Laravel ships. That is only safe if a missing line falls
    // through to English prose -- if it surfaced the key instead, every rule
    // added later would ship a broken message to German agents silently.
    App::setLocale('de');

    foreach (['validation.uuid', 'validation.ip', 'validation.alpha_dash', 'validation.regex'] as $key) {
        $message = trans($key, ['attribute' => 'test']);

        expect($message)->not->toBe($key)
            ->and($message)->not->toContain('validation.');
    }

    // And one that IS covered, so this is measuring fallback rather than
    // measuring that nothing is translated at all.
    expect(trans('validation.required', ['attribute' => 'Name']))->toBe('Name muss ausgefüllt werden.');
});

test('the flash after changing language speaks the new language', function (): void {
    // The one action whose confirmation is written under a different language
    // than the page that shows it: the request that saves the change is still
    // running as the language being left behind.
    $agent = languageAgent();

    $this->actingAs($agent)
        ->from(route('dashboard.profile.show'))
        ->put(route('dashboard.profile.update'), ['name' => $agent->name, 'locale' => 'de'])
        ->assertRedirect(route('dashboard.profile.show'));

    $this->actingAs($agent->fresh())
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Profil aktualisiert.')
        ->assertDontSee('Profile updated.');

    // And the reverse: a German agent going back to the install default must
    // not be confirmed in German on an English page.
    $german = languageAgent('de');

    $this->actingAs($german)
        ->from(route('dashboard.profile.show'))
        ->put(route('dashboard.profile.update'), ['name' => $german->name, 'locale' => '']);

    $this->actingAs($german->fresh())
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Profile updated.')
        ->assertDontSee('Profil aktualisiert.');
});

test('the language selector names each language in its own language', function (): void {
    // The general comparison test cannot see this class of miss: it ignores
    // anything under 25 characters, so `Deutsch (German)` -- an English word
    // sitting inside the German page, in the language selector of all places --
    // read as identical in both renders and was skipped as a label.
    //
    // Lowering that floor is not the fix; it would sweep in names, numbers and
    // one-word labels. This asserts the specific rule instead.
    expect(DashboardLanguage::SUPPORTED)->toBe([
        'en' => 'English',
        'de' => 'Deutsch',
    ]);

    foreach (DashboardLanguage::SUPPORTED as $label) {
        // A gloss is the shape this went wrong in: correct for one audience,
        // foreign copy for every other.
        expect($label)->not->toContain('(');
    }

    // And it renders that way, in both languages, which is the correct
    // exception to "nothing reads the same in both".
    foreach (['en', 'de'] as $locale) {
        $this->actingAs(languageAgent($locale))
            ->get(route('dashboard.profile.show'))
            ->assertOk()
            ->assertSee('Deutsch')
            ->assertDontSee('Deutsch (German)');
    }
});

test('an unextracted page is English all the way down, not only at the root', function (): void {
    // Marking the DOCUMENT English while leaving the LOCALE German left German
    // fragments scattered inside it -- a model's option labels here, a Carbon
    // relative time there, a validation message somewhere else. Each is a
    // separate leak with a separate fix and there is no end to the list.
    //
    // So the locale is scoped rather than the attribute: on a surface that has
    // not been extracted there is nothing German to be inconsistent with, and
    // `lang="en"` is simply true rather than a claim a second mechanism has to
    // keep honest.
    $agent = languageAgent('de');

    $alerts = $this->actingAs($agent)->get(route('dashboard.alerts.index'))->assertOk();

    // `User::alertModeOptions()` is catalogue-backed and reaches this page.
    $alerts->assertSee('All site alerts I can support')
        ->assertDontSee('Alle Website-Benachrichtigungen');

    // The same agent, on the surface that HAS been extracted, still reads
    // German -- so this measures scoping rather than a translation that broke.
    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Alle Website-Benachrichtigungen, die ich betreuen kann')
        ->assertDontSee('All site alerts I can support');
});

test('an unextracted page renders its relative times in English too', function (): void {
    // Carbon reads the application locale, so a scoped `lang` attribute alone
    // left `vor 3 Stunden` inside a document declaring itself English. Nothing
    // about the view says "translate this"; the locale simply reached it.
    $agent = languageAgent('de');

    App::setLocale('de');
    $inGerman = now()->subHours(3)->diffForHumans();

    App::setLocale('en');
    $inEnglish = now()->subHours(3)->diffForHumans();

    expect($inGerman)->not->toBe($inEnglish);

    $this->actingAs($agent)
        ->get(route('dashboard.alerts.index'))
        ->assertOk()
        ->assertDontSee($inGerman);
});

test('the shell says English and the page region says its own language', function (): void {
    // The mirror of the leak the scoped locale fixed. There, English copy sat
    // inside a document claiming German; here the SHELL is English -- the
    // navigation, the topbar, the support-code search all still say "Work",
    // "Conversations", "Sign out" -- so a root declaring German would be lying
    // about most of its own chrome, and a screen reader would pronounce those
    // words with German phonetics.
    //
    // The root states the shell's language and the page region states its own,
    // which is what `lang` is for. When the shell is extracted the root follows
    // the locale and the region attribute stops being needed.
    $agent = languageAgent('de');

    $profile = $this->actingAs($agent)->get(route('dashboard.profile.show'))->assertOk();

    $profile->assertSee('<html lang="en"', false)
        ->assertSee('<main class="page" lang="de"', false);

    // On a surface that is not extracted, both say English and mean it.
    $this->actingAs($agent)
        ->get(route('dashboard.alerts.index'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertSee('<main class="page" lang="en"', false);
});

test('translated copy outside the page region says which language it is', function (): void {
    // Two strings escape `<main>`: the document `<title>`, and the topbar
    // breadcrumb, which falls back to the page title on surfaces that have no
    // rail item -- Profile is one. Both are page copy sitting in shell
    // territory, so without saying so they inherit the root and get pronounced
    // as English.
    //
    // The crumb is the interesting one: its language depends on which branch it
    // took. A rail label is the shell's copy and stays English; the fallback is
    // the page's and follows the agent.
    $agent = languageAgent('de');

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('<title lang="de">', false)
        ->assertSee('<span class="wf-crumb-current" lang="de">', false);

    // A surface WITH a rail item takes the shell's label, which is English --
    // and is not extracted anyway, so both agree.
    $this->actingAs($agent)
        ->get(route('dashboard.alerts.index'))
        ->assertOk()
        ->assertSee('<title lang="en">', false)
        ->assertSee('<span class="wf-crumb-current" lang="en">', false);

    // An English agent sees English everywhere, so this measures the marking
    // rather than a locale that stopped switching.
    $this->actingAs(languageAgent('en'))
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('<title lang="en">', false)
        ->assertDontSee('lang="de"', false);
});

test('an untranslated page is still marked as the English it is', function (): void {
    // The dashboard is being translated a surface at a time, so an agent who
    // chose German reads a few pages in German and most still in English. The
    // application locale is German throughout -- it has to be, or the pages
    // that ARE translated would not be -- but `lang` describes the DOCUMENT,
    // and telling a screen reader that an English page is German makes it
    // pronounce English words with German phonetics. A sighted agent never
    // sees this; someone listening to the page hears nothing else.
    $agent = languageAgent('de');

    // The ROOT is the shell's language, which is still English everywhere --
    // see 'the shell says English and the page region says its own language'.
    // What moves per surface is the page region.
    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('lang="de"', false);

    // A surface that has not been extracted yet says English, and means it.
    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<html lang="en"', false)
        ->assertDontSee('lang="de"', false);
});

test('an English agent is told English everywhere', function (): void {
    $agent = languageAgent('en');

    foreach ([route('dashboard.profile.show'), route('dashboard')] as $url) {
        $this->actingAs($agent)->get($url)->assertOk()->assertSee('<html lang="en"', false);
    }
});

test('no catalogue string carries an escape that PHP did not honour', function (): void {
    // `'„unbeantwortet\"'` in a SINGLE-quoted PHP string keeps the backslash:
    // PHP only honours `\\` and `\'` there, so the page rendered
    // `unbeantwortet\"` to a German agent.
    //
    // The comparison test cannot see this class at all -- a malformed German
    // sentence still differs from its English original, which is the only
    // question that test asks. Copy can be wrong without being English.
    $offenders = [];

    foreach (glob(lang_path('*/*.php')) ?: [] as $file) {
        $walk = function (array $values, string $path) use (&$walk, &$offenders, $file): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $walk($value, $path.$key.'.');

                    continue;
                }

                if (is_string($value) && str_contains($value, '\\')) {
                    $offenders[] = basename(dirname($file)).'/'.basename($file).': '.$path.$key.' = '.$value;
                }
            }
        };

        $walk(require $file, '');
    }

    expect($offenders)->toBe([]);
});

test('German copy uses German quotation marks', function (): void {
    // „…“ rather than "…". A straight quote is not a German quotation mark, and
    // it is what a translator reaches for by habit when the surrounding code is
    // English -- the same slip that produced the escaped backslash above.
    $offenders = [];

    foreach (glob(lang_path('de/*.php')) ?: [] as $file) {
        $walk = function (array $values, string $path) use (&$walk, &$offenders, $file): void {
            foreach ($values as $key => $value) {
                if (is_array($value)) {
                    $walk($value, $path.$key.'.');

                    continue;
                }

                if (is_string($value) && preg_match('/["\']/', $value) === 1) {
                    $offenders[] = basename($file).': '.$path.$key.' = '.$value;
                }
            }
        };

        $walk(require $file, '');
    }

    expect($offenders)->toBe([]);
});
