<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\User;
use App\Support\DashboardLanguage;
use App\Support\Release\ActionDisposition;
use App\Support\Release\ReleaseManifest;
use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\InstallationCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Read the actual JSON delivered to the browser, not a second translation call. */
function operatorUpdateLanguageDocument(string $html): array
{
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);

    return [$document, $xpath, json_decode(
        $xpath->query('//*[@id="operator-update-copy"]')->item(0)?->textContent ?? '',
        true, 512, JSON_THROW_ON_ERROR,
    )];
}

test('the update catalogue covers every locale and preserves complete messages', function (): void {
    $english = Arr::dot(require lang_path('en/operator_updates.php'));
    $cognates = ['de' => [], 'it' => ['labels.no']];

    foreach (['de', 'it'] as $locale) {
        $translated = Arr::dot(require lang_path($locale.'/operator_updates.php'));
        expect(array_keys($translated))->toBe(array_keys($english));

        foreach ($english as $key => $value) {
            expect($translated[$key])->toBeString()->not->toBeEmpty()->not->toContain('\\');
            preg_match_all('/:[a-z][a-z_]+/', $value, $sourcePlaceholders);
            preg_match_all('/:[a-z][a-z_]+/', $translated[$key], $targetPlaceholders);
            expect($targetPlaceholders[0])->toBe($sourcePlaceholders[0], $locale.': '.$key);

            if (! in_array($key, $cognates[$locale], true)) {
                expect($translated[$key])->not->toBe($value, $locale.': '.$key.' remains English');
            }
        }
    }
});

test('translated update vocabulary covers the real host and release enums', function (): void {
    $host = new ReflectionClass(HostUpdaterClient::class);

    foreach (['en', 'de', 'it'] as $locale) {
        $copy = require lang_path($locale.'/operator_updates.php');

        foreach (['ERRORS' => 'errors', 'EVENTS' => 'event_codes', 'CHECKPOINTS' => 'checkpoints', 'PHASES' => 'outcomes'] as $constant => $group) {
            foreach ($host->getConstant($constant) as $code) {
                expect($copy[$group])->toHaveKey($code);
            }
            expect($copy[$group])->toHaveKey('unknown');
        }

        foreach (InstallationCapabilities::OWNERSHIPS as $ownership) {
            expect($copy['ownership'][$ownership]['label'])->toBeString()->not->toBeEmpty()
                ->and($copy['ownership'][$ownership]['guidance'])->toBeString()->not->toBeEmpty();
        }
        foreach (ReleaseManifest::PHASES as $phase) {
            expect($copy['review'])->toHaveKey('action_phase_'.$phase);
        }
        foreach (ActionDisposition::cases() as $disposition) {
            expect($copy['review'])->toHaveKey('action_'.$disposition->value);
        }
        foreach (['succeeded', 'failed_safe', 'rolled_back', 'cancelled', 'recovery_required', 'reconciliation_required', 'blocked', 'unknown'] as $outcome) {
            expect($copy['outcomes'])->toHaveKey($outcome)
                ->and($copy['outcome_details'])->toHaveKey($outcome);
        }
        expect(array_unique(array_intersect_key($copy['outcomes'], array_flip([
            'succeeded', 'failed_safe', 'rolled_back', 'cancelled', 'recovery_required', 'reconciliation_required', 'blocked',
        ]))))->toHaveCount(7);
    }
});

test('every literal update-script copy lookup exists in the catalogue', function (): void {
    $script = file_get_contents(resource_path('views/components/operator-update-script.blade.php'));
    preg_match_all('/words\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*\)/', $script, $lookups, PREG_SET_ORDER);
    expect($lookups)->not->toBeEmpty();

    foreach (['en', 'de', 'it'] as $locale) {
        $copy = require lang_path($locale.'/operator_updates.php');
        foreach ($lookups as [, $group, $key]) {
            expect($copy[$group])->toHaveKey($key);
        }
    }
});

test('the update console renders localized controls and browser recovery messages', function (string $locale, string $title, string $password, string $reconnecting, string $sessionLost, string $recovery, bool $mfa): void {
    Http::preventStrayRequests();
    $helper = Mockery::mock(HostUpdaterClient::class);
    $helper->shouldNotReceive('capabilities', 'prepare', 'start', 'cancel', 'status', 'history', 'logs');
    app()->instance(HostUpdaterClient::class, $helper);
    config()->set(['wayfindr.release.version' => '1.2.0-dev+language-marker', 'wayfindr.release.commit' => str_repeat('a', 40)]);
    $operator = User::factory()->for(Account::factory())->create(['platform_role' => PlatformRole::Operator, 'locale' => $locale]);
    if ($mfa) {
        $operator->forceFill(['two_factor_secret' => 'private-mfa-marker', 'two_factor_confirmed_at' => now()])->save();
    }

    $response = $this->actingAs($operator)->get(route('operator.updates.index'))->assertOk()
        ->assertSee($title)->assertSee($password)->assertDontSee('operator_updates.')
        ->assertDontSee('private-mfa-marker');
    [$document, $xpath, $copy] = operatorUpdateLanguageDocument($response->getContent());
    expect($document->documentElement->getAttribute('lang'))->toBe($locale)
        ->and($copy['connection']['reconnecting'])->toBe($reconnecting)
        ->and($copy['connection']['session_lost'])->toBe($sessionLost)
        ->and($copy['outcomes']['recovery_required'])->toBe($recovery)
        ->and($copy['connection']['last_known'])->not->toContain(':time')
        ->and($copy['reauth']['password'])->toBe($password)
        ->and($copy['errors']['unknown'])->not->toBe('unknown');

    // The release marker remains data. It must not be translated, nor announced
    // as prose in the operator's chosen language.
    $current = $xpath->query('//*[@data-update-current]')->item(0);
    expect($current?->textContent)->toBe('1.2.0-dev+language-marker')
        ->and($current?->getAttribute('lang'))->toBe('');

    expect($xpath->query('//input[@name="one_time_code"]')->length)->toBe($mfa ? 1 : 0)
        ->and($xpath->query('//*[@data-update-stage]')->length)->toBe(6)
        ->and($xpath->query('//dialog[@data-update-dialog and @aria-labelledby and @aria-describedby]')->length)->toBe(1)
        ->and($xpath->query('//button[@data-update-close and @type="button" and @autofocus]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-update-outcome and @role="status" and @aria-live="polite" and @aria-atomic="true"]')->length)->toBe(1)
        ->and($xpath->query('//input[@data-update-confirm and @type="checkbox" and @disabled]')->length)->toBe(1);

    Http::assertNothingSent();
})->with([
    ['de', 'Aktualisierungen', 'Aktuelles Passwort', 'Verbindung wird wiederhergestellt. Das ist während eines Updates zu erwarten…', 'Ihre Sitzung wurde beendet. Melden Sie sich erneut an, um den aktuellen Host-Zustand zu lesen. Das Update läuft möglicherweise weiter.', 'Wiederherstellung erforderlich', false],
    ['de', 'Aktualisierungen', 'Aktuelles Passwort', 'Verbindung wird wiederhergestellt. Das ist während eines Updates zu erwarten…', 'Ihre Sitzung wurde beendet. Melden Sie sich erneut an, um den aktuellen Host-Zustand zu lesen. Das Update läuft möglicherweise weiter.', 'Wiederherstellung erforderlich', true],
    ['it', 'Aggiornamenti', 'Password attuale', 'Riconnessione in corso. È normale durante un aggiornamento…', 'La sessione è terminata. Acceda di nuovo per leggere lo stato attuale dell’host. L’aggiornamento potrebbe essere ancora in corso.', 'Ripristino necessario', false],
    ['it', 'Aggiornamenti', 'Password attuale', 'Riconnessione in corso. È normale durante un aggiornamento…', 'La sessione è terminata. Acceda di nuovo per leggere lo stato attuale dell’host. L’aggiornamento potrebbe essere ancora in corso.', 'Ripristino necessario', true],
]);

test('the update console follows the current operator language preference', function (): void {
    expect(DashboardLanguage::EXTRACTED_ROUTES)->toContain('operator.updates.index');
    $operator = User::factory()->for(Account::factory())->create(['platform_role' => PlatformRole::Operator, 'locale' => 'de']);
    $this->actingAs($operator)->get(route('operator.updates.index'))->assertOk()->assertSee('Aktuelles Passwort');
    $operator->update(['locale' => 'it']);
    $this->get(route('operator.updates.index'))->assertOk()->assertSee('Password attuale')->assertDontSee('Aktuelles Passwort');
});

test('an unknown current release remains translated copy rather than unknown-language data', function (): void {
    config()->set('wayfindr.release.version', null);
    $operator = User::factory()->for(Account::factory())->create(['platform_role' => PlatformRole::Operator, 'locale' => 'de']);
    $response = $this->actingAs($operator)->get(route('operator.updates.index'))->assertOk();
    [, $xpath] = operatorUpdateLanguageDocument($response->getContent());
    $current = $xpath->query('//*[@data-update-current]')->item(0);
    expect($current?->textContent)->toBe('Unbekannt')
        ->and($current?->hasAttribute('lang'))->toBeFalse();
});
