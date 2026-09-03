<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\DashboardLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * Italian, at the altitude a catalogue cannot reach.
 *
 * The catalogue guards -- key parity, placeholder integrity, the policy score --
 * all pass on a language nobody has ever rendered. They read files. Adding a
 * locale to `DashboardLanguage::SUPPORTED` makes it selectable by a real agent,
 * and the question that then matters is whether the pages come back at all.
 *
 * Deliberately narrower than the German net. That one compares two renders
 * token by token and needs a cognate list to excuse the words that are
 * identical on purpose; building the Italian equivalent means measuring which
 * words those are, which is its own piece of work. This asserts the properties
 * that fail loudest and soonest instead: the surface renders, it says it is
 * Italian, and it is not English.
 */
function italianDashboardWorld(): array
{
    $account = Account::factory()->create(['name' => 'Acme Datenpunkt']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Datenpunkt Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-datenpunkt']);

    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ITA001',
        'subject' => 'Datenpunkt checkout',
        'status' => 'open',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create(['subject' => 'Datenpunkt ticket']);

    return [
        'account' => $account,
        'conversation' => $conversation,
        'ticket' => $ticket,
        'it' => User::factory()->for($account)->create(['locale' => 'it', 'name' => 'Ada Datenpunkt']),
        'en' => User::factory()->for($account)->create(['locale' => 'en', 'name' => 'Ada Datenpunkt']),
    ];
}

test('Italian is offered, and is named in Italian', function (): void {
    // Autonyms are not translated and are identical in every rendering
    // language: this option asks an agent which language THEY read, so its
    // reader is by definition someone who reads it.
    expect(DashboardLanguage::SUPPORTED)->toHaveKey('it')
        ->and(DashboardLanguage::SUPPORTED['it'])->toBe('Italiano')
        ->and(DashboardLanguage::normalise('it-IT'))->toBe('it')
        ->and(DashboardLanguage::normalise('IT'))->toBe('it');
});

test('every extracted surface renders in Italian and says so', function (): void {
    // The failure this exists for is a page that 500s or renders half-English
    // the first time a real agent picks the language -- which no amount of
    // catalogue checking can see, because the catalogue is fine.
    $world = italianDashboardWorld();

    $urls = [
        route('dashboard.profile.show'),
        route('dashboard.conversations.index'),
        route('dashboard.tickets.index'),
        route('dashboard.tickets.show', $world['ticket']),
        route('dashboard.conversations.show', $world['conversation']->support_code),
    ];

    foreach ($urls as $url) {
        $this->actingAs($world['it'])
            ->get($url)
            ->assertOk()
            ->assertSee('<html lang="it"', false);
    }
});

test('an Italian surface is not just the English one with a lang attribute', function (): void {
    // A catalogue can be complete and unreached: the locale has to actually be
    // scoped to the surface, which is `SetDashboardLocale` plus
    // `EXTRACTED_ROUTES`, not the presence of `lang/it`.
    $world = italianDashboardWorld();

    $italian = $this->actingAs($world['it'])
        ->get(route('dashboard.conversations.index'))->assertOk()->getContent();

    $english = $this->actingAs($world['en'])
        ->get(route('dashboard.conversations.index'))->assertOk()->getContent();

    expect($italian)->not->toBe($english);

    // Ruled terms, at the point they are rendered rather than in the table.
    expect($italian)->toContain('Coda conversazioni')
        ->and($italian)->not->toContain('Conversation queue');
});

test('the ruled Italian vocabulary survives to the page', function (): void {
    // Terms an agent actually sees on the queue, checked where they are
    // rendered rather than in the table. `Gestore` is deliberately NOT here:
    // the operator nav item is role-gated and never reaches this surface, so
    // asserting it would be asserting the fixture rather than the translation.
    // The catalogue self-audit holds that one -- `operatore` is on the rejected
    // list, so it cannot reappear anywhere without failing.
    $world = italianDashboardWorld();

    $page = $this->actingAs($world['it'])
        ->get(route('dashboard.conversations.index'))->assertOk()->getContent();

    expect($page)->toContain('Sito')
        ->and($page)->toContain('Presenza')
        ->and($page)->toContain('Visitatore')
        // The three a real engine got wrong, at the altitude they would be read.
        ->and($page)->not->toContain('Operatore')
        ->and($page)->not->toContain('Standort')
        ->and($page)->not->toContain('istantanea');
});

test('every offered language answers a validation failure in its own words', function (): void {
    // Iterates SUPPORTED rather than naming Italian, so a fourth language
    // inherits this the day it is offered. That is the shape of the bug it
    // exists for: Italian became selectable and its validation messages stayed
    // English, because `lang/it/validation.php` did not exist and Laravel's
    // per-key fallback made the omission invisible -- a clean English sentence
    // on an otherwise Italian page, which no catalogue check can see.
    $leaks = [];

    foreach (array_keys(DashboardLanguage::SUPPORTED) as $locale) {
        App::setLocale($locale);

        $validator = Validator::make(
            ['name' => '', 'body' => str_repeat('x', 5000)],
            ['name' => 'required', 'body' => 'max:4000'],
        );

        $validator->fails();
        $messages = $validator->errors()->all();

        foreach ($messages as $message) {
            if (str_contains($message, 'validation.')) {
                $leaks[] = "{$locale}: raw translation key -- {$message}";
            }

            if ($locale === 'en') {
                // `The body field must not be...` is CORRECT English: `body` is
                // the humanised column and there is nothing to translate it to.
                // The two checks below are about translated surfaces only.
                continue;
            }

            // The failure the German file was written for: an unnamed attribute
            // puts the column name into the middle of a translated sentence.
            if (str_contains($message, ' body ')) {
                $leaks[] = "{$locale}: column name in a translated sentence -- {$message}";
            }

            if (str_contains($message, 'field is required')) {
                $leaks[] = "{$locale}: English message on a {$locale} surface -- {$message}";
            }
        }
    }

    App::setLocale('en');

    expect($leaks)->toBe([]);
});
