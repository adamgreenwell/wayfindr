<?php

use App\Support\Translation\Catalogue;
use App\Support\Translation\CataloguePlan;
use App\Support\Translation\CatalogueTranslator;
use App\Support\Translation\EngineBrief;
use App\Support\Translation\Engines\MurfEngine;
use App\Support\Translation\Glossary;
use App\Support\Translation\PolicyScorer;
use App\Support\Translation\Protector;
use App\Support\Translation\TranslationEngine;
use App\Support\Translation\TranslationFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * An engine that records what it was handed and can be told to misbehave.
 *
 * Named after the file rather than the concept: a bare `engine()` in a Pest
 * test file is a GLOBAL function, and the last helper named for its concept
 * collided with a PHP alias.
 */
function translationPipelineEngine(?callable $mangle = null): TranslationEngine
{
    return new class($mangle) implements TranslationEngine
    {
        /** @var array<int, string> */
        public array $seen = [];

        public ?EngineBrief $brief = null;

        public function __construct(private $mangle) {}

        public function name(): string
        {
            return 'recording';
        }

        public function usesBrief(): bool
        {
            return true;
        }

        public function translate(array $texts, EngineBrief $brief): array
        {
            $this->seen = array_merge($this->seen, $texts);
            $this->brief = $brief;

            if ($this->mangle === null) {
                return array_map(static fn (string $t): string => 'DE:'.$t, $texts);
            }

            return array_map($this->mangle, $texts);
        }
    };
}

function translationPipelineCatalogue(array $values, string $docblock = ''): Catalogue
{
    $path = sys_get_temp_dir().'/wf-cat-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, Catalogue::render(Catalogue::nest($values), $docblock));

    $catalogue = Catalogue::read($path);
    @unlink($path);

    return $catalogue;
}

function translationPipelineTranslator(TranslationEngine $engine): CatalogueTranslator
{
    $glossary = Glossary::load();

    return new CatalogueTranslator($engine, $glossary, new Protector($glossary));
}

test('a placeholder is masked on the way out and restored on the way back', function (): void {
    // The reason this is mechanical rather than an instruction: `:elapsed` and
    // `:shown` read as words, so an engine merely ASKED to preserve them
    // sometimes will not. A token has nothing to translate into.
    $engine = translationPipelineEngine();

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['waiting' => 'Waiting on visitor for :elapsed']),
        null,
        'de',
    );

    expect($engine->seen)->toHaveCount(1)
        ->and($engine->seen[0])->not->toContain(':elapsed')
        ->and($plan->translated['waiting'])->toContain(':elapsed')
        ->and($plan->failures)->toBe([]);
});

test('a lost placeholder fails that key instead of shipping a hole', function (): void {
    // The alternative is a Laravel `Missing placeholder` at render time, in
    // front of an agent, discovered by them rather than by this.
    $engine = translationPipelineEngine(static fn (string $t): string => preg_replace('/WFZ\d+/', '', $t) ?? $t);

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['waiting' => 'Waiting on visitor for :elapsed']),
        null,
        'de',
    );

    expect($plan->translated)->toBe([])
        ->and($plan->failures)->toHaveKey('waiting')
        ->and($plan->failures['waiting'])->toContain(':elapsed');
});

test('a plural string travels as segments, never as one string with a pipe in it', function (): void {
    $engine = translationPipelineEngine();

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['tickets' => '{1} 1 ticket|[2,*] :count tickets']),
        null,
        'de',
    );

    expect($engine->seen)->toHaveCount(2);

    foreach ($engine->seen as $sent) {
        expect($sent)->not->toContain('|')
            ->and($sent)->not->toContain('{1}')
            ->and($sent)->not->toContain('[2,*]')
            ->and($sent)->not->toContain(':count');
    }

    expect($plan->translated['tickets'])->toContain('{1}')
        ->and($plan->translated['tickets'])->toContain('[2,*]')
        ->and($plan->translated['tickets'])->toContain(':count')
        ->and(explode('|', $plan->translated['tickets']))->toHaveCount(2);
});

test('a plural string is flagged for review however well it came back', function (): void {
    // Segment COUNT is a target-language decision -- German takes two and
    // Polish takes three -- and no per-segment translation can add a segment it
    // was never given. Flagging it is the honest output.
    $plan = translationPipelineTranslator(translationPipelineEngine())->plan(
        translationPipelineCatalogue(['tickets' => '{1} 1 ticket|[2,*] :count tickets']),
        null,
        'de',
    );

    expect($plan->review)->toHaveKey('tickets')
        ->and($plan->failures)->toBe([]);
});

test('copy that already exists is never sent, never replaced', function (): void {
    // Reviewed copy is the most valuable thing in the directory. A pipeline
    // that can overwrite it by default is one flag away from undoing a week.
    $engine = translationPipelineEngine();

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['a' => 'Search', 'b' => 'Refresh']),
        translationPipelineCatalogue(['a' => 'Suchen']),
        'de',
    );

    expect($engine->seen)->toBe(['Refresh'])
        ->and($plan->carried)->toBe(['a' => 'Suchen'])
        ->and($plan->translated)->toHaveKey('b')
        ->and($plan->translated)->not->toHaveKey('a');
});

test('a cognate and a fully-protected string never reach the engine at all', function (): void {
    $engine = translationPipelineEngine();

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue([
            'feature' => 'Cobrowse',
            'slot' => ':count',
            'real' => 'Refresh',
        ]),
        null,
        'de',
    );

    expect($engine->seen)->toBe(['Refresh'])
        ->and($plan->carried['feature'])->toBe('Cobrowse')
        ->and($plan->merged()['slot'])->toBe(':count');
});

test('the engine is handed the catalogue docblock and the glossary', function (): void {
    // The differentiator, and the thing Murf's two-field request body has
    // nowhere to put. `lang/en/conversations.php` explains its own plural rule;
    // that is a better brief than most translators are given.
    $engine = translationPipelineEngine();

    translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['x' => 'Refresh'], 'Counts go through trans_choice, verb included.'),
        null,
        'de',
    );

    $instructions = $engine->brief->asInstructions();

    expect($engine->brief->docblock)->toContain('trans_choice')
        ->and($instructions)->toContain('Counts go through trans_choice')
        ->and($instructions)->toContain('Präsenzstatus')
        ->and($instructions)->toContain('Sie')
        ->and($instructions)->toContain('WFZ');
});

test('the source key order survives a failure in the middle', function (): void {
    $engine = translationPipelineEngine(static fn (string $t): string => str_contains($t, 'WFZ')
        ? 'broken'
        : 'DE:'.$t);

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['first' => 'One', 'second' => 'Two :count', 'third' => 'Three']),
        null,
        'de',
    );

    expect($plan->failures)->toHaveKey('second')
        ->and(array_keys($plan->translated))->toBe(['first', 'third']);
});

test('a rendered catalogue reads back identical, apostrophes and all', function (): void {
    // Italian will carry `dell'agente` on day one. Only `\` and `'` are
    // escapable inside single quotes, which is the same narrow rule that once
    // shipped `unbeantwortet\"` to a German agent.
    $values = [
        'plain' => 'Refresh',
        'apostrophe' => "Conferma quello che l'utente vede",
        'quotes' => 'Der Filter „Neue Aktivität“ ist aktiv',
        'placeholder' => 'Waiting on :name for :elapsed',
        'plural' => '{1} 1 ticket|[2,*] :count tickets',
        'nested' => ['deep' => ['deeper' => "It's here"]],
    ];

    $path = sys_get_temp_dir().'/wf-render-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, Catalogue::render(Catalogue::nest(
        ['plain' => $values['plain'], 'apostrophe' => $values['apostrophe'], 'quotes' => $values['quotes'],
            'placeholder' => $values['placeholder'], 'plural' => $values['plural'], 'nested.deep.deeper' => "It's here"]
    ), 'A header.'));

    expect(require $path)->toBe($values);

    @unlink($path);
});

test('the glossary keeps every declared collision apart', function (): void {
    // The pairs exist because each is a distinction English keeps and a
    // careless translation merges. `site`/`page` is the one that would actually
    // happen: German `Seite` means PAGE.
    expect(Glossary::load()->mergedCollisions('de'))->toBe([]);
});

test('a missing glossary is an error rather than an empty run', function (): void {
    expect(fn () => Glossary::load('/nonexistent/glossary.php'))
        ->toThrow(TranslationFailed::class);
});

test('a drafted catalogue carries exactly the keys the English source has', function (): void {
    // End to end against real data: the shipped English nav catalogue, through
    // the translator, through the renderer, and back out of `require`. Key
    // parity is the one property a language pack cannot be shipped without --
    // a missing key is a raw `nav.items.sites` rendered to an agent.
    $source = Catalogue::read(lang_path('en/nav.php'));

    $plan = translationPipelineTranslator(translationPipelineEngine())->plan($source, null, 'de');

    $path = sys_get_temp_dir().'/wf-parity-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, Catalogue::render(Catalogue::nest($plan->merged()), 'Drafted.'));

    $written = Catalogue::read($path);
    @unlink($path);

    expect(array_keys($written->values()))->toBe(array_keys($source->values()))
        ->and($plan->failures)->toBe([]);
});

test('a rejected term is flagged, inside a German compound as well as alone', function (): void {
    // Substring matching is load-bearing rather than sloppy: a real run returned
    // `Konversationswarteschlange`, and the rejected term is buried in the
    // middle of it. Word boundaries would miss every compound German builds.
    $scorer = new PolicyScorer(Glossary::load());

    $score = $scorer->score(new CataloguePlan(
        catalogue: 'probe',
        targetLocale: 'de',
        translated: [
            'compound' => 'Warten auf Live-Konversationsaktualisierungen.',
            'alone' => 'Der Schnappschuss ist veraltet.',
            'clean' => 'Die Unterhaltung ist geschlossen.',
        ],
    ));

    expect($score->violations['rejected term'] ?? [])->toHaveCount(2)
        ->and(array_column($score->violations['rejected term'], 'key'))->toBe(['compound', 'alone']);
});

test('the requester term is not mistaken for the rejected request verb', function (): void {
    // `Anfragender` -- the decided word for a ticket's requester -- begins with
    // the exact letters of `anfragen`. The first version of the rejected list
    // flagged three correct strings, which is how the entry came to be removed
    // rather than boundary-matched.
    $score = (new PolicyScorer(Glossary::load()))->score(new CataloguePlan(
        catalogue: 'probe',
        targetLocale: 'de',
        translated: ['requester' => 'Ticket #123, Support-Code, Betreff, Anfragender'],
    ));

    expect($score->violations)->toBe([]);
});

test('informal address and the generic masculine pronoun are both counted', function (): void {
    $score = (new PolicyScorer(Glossary::load()))->score(new CataloguePlan(
        catalogue: 'probe',
        targetLocale: 'de',
        translated: [
            'informal' => 'Nur Tickets, die dir zugewiesen sind.',
            'pronoun' => 'Fragen Sie den Besucher, was er sieht.',
        ],
    ));

    expect($score->violations)->toHaveKeys(['informal address', 'generic masculine pronoun'])
        ->and($score->violationCount())->toBe(2);
});

test('agreement is null with nothing to agree with, and counts only drafted keys', function (): void {
    // Reporting 0% for a language that has no reviewed copy would be a number
    // that means nothing wearing the costume of a bad one. And a CARRIED value
    // agrees by construction, so counting it would inflate the score with
    // strings the engine never saw.
    $plan = new CataloguePlan(
        catalogue: 'probe',
        targetLocale: 'de',
        translated: ['a' => 'Suchen', 'b' => 'Falsch'],
        carried: ['c' => 'Aktualisieren'],
    );

    $scorer = new PolicyScorer(Glossary::load());

    expect($scorer->score($plan)->agreementPercent())->toBeNull();

    $scored = $scorer->score($plan, ['a' => 'Suchen', 'b' => 'Richtig', 'c' => 'Aktualisieren']);

    expect($scored->drafted)->toBe(2)
        ->and($scored->agreed)->toBe(1)
        ->and($scored->agreementPercent())->toBe(50.0)
        ->and($scored->scored)->toBe(3);
});

test('every shipped catalogue obeys the policy it was written against', function (): void {
    // The self-audit, as an assertion. It runs backwards from the usual
    // direction: adding a term to the glossary's rejected list immediately
    // measures the catalogue against it, so a decision cannot be recorded in
    // one place and quietly contradicted in another.
    $glossary = Glossary::load();
    $scorer = new PolicyScorer($glossary);
    $offenders = [];

    foreach (['de', 'it'] as $locale) {
        foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
            $name = basename($path, '.php');
            $target = lang_path($locale.'/'.$name.'.php');

            if (! is_file($target)) {
                continue;
            }

            $score = $scorer->score(new CataloguePlan(
                catalogue: $name,
                targetLocale: $locale,
                carried: Catalogue::read($target)->values(),
            ));

            foreach ($score->violations as $rule => $hits) {
                foreach ($hits as $hit) {
                    $offenders[] = "{$locale} {$rule}: {$name}.{$hit['key']} -- {$hit['detail']}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('every language carries the same term keys, so a gap is a failure not a silence', function (): void {
    // A locale missing a term does not error -- it just quietly translates that
    // concept however the engine feels, which is the drift the table exists to
    // stop. Parity makes an omission loud at the point it is introduced.
    $glossary = Glossary::load();
    $reference = array_keys($glossary->terms('de'));

    foreach (['it'] as $locale) {
        $terms = array_keys($glossary->terms($locale));

        expect(array_diff($reference, $terms))->toBe([], "{$locale} is missing terms the German table decides")
            ->and(array_diff($terms, $reference))->toBe([], "{$locale} decides terms German does not");
    }
});

test('every language keeps the declared collisions apart in its own words', function (): void {
    $glossary = Glossary::load();

    foreach (['de', 'it'] as $locale) {
        expect($glossary->mergedCollisions($locale))->toBe([], "{$locale} merges a declared collision pair");
    }
});

test('a duplicated protection token fails the key instead of doubling a placeholder', function (): void {
    // Presence is not the guarantee this pipeline claims. An engine that
    // returns one token twice would write `:elapsed` twice into the catalogue,
    // and the agent reads a duplicated value in a sentence that parses fine --
    // no missing token, so nothing downstream can see it.
    $engine = translationPipelineEngine(
        static fn (string $t): string => preg_replace('/(WFZ\d+)/', '$1 $1', $t) ?? $t
    );

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['waiting' => 'Waiting on visitor for :elapsed']),
        null,
        'de',
    );

    expect($plan->failures)->toHaveKey('waiting')
        ->and($plan->translated)->not->toHaveKey('waiting');
});

test('a token is not corrupted by another token that is a prefix of it', function (): void {
    // WFZ1 is a prefix of WFZ10, and `str_replace` applies its search array in
    // order: WFZ1 landed first and rewrote WFZ10 into "<whatever WFZ1 stood
    // for>0". Nothing could see it -- no token survives that, so the
    // leftover-token check passed on a corrupted string.
    //
    // Eleven protected values in one sentence is what it takes to reach, which
    // is why it had never been reached.
    // Alphabetic names on purpose: the placeholder pattern stops at a digit,
    // so `:value10` masks as one token plus a literal "0" and never reaches
    // eleven distinct tokens at all.
    $placeholders = [
        ':alpha', ':bravo', ':charlie', ':delta', ':echo', ':foxtrot',
        ':golf', ':hotel', ':india', ':juliett', ':kilo',
    ];

    $source = 'Report '.implode(' ', $placeholders);

    // The engine returns the masked text untouched, so any corruption here is
    // ours rather than the engine's.
    $engine = translationPipelineEngine(static fn (string $t): string => $t);

    $plan = translationPipelineTranslator($engine)->plan(
        translationPipelineCatalogue(['report' => $source]),
        null,
        'de',
    );

    expect($plan->failures)->toBe([]);

    // Every placeholder back, whole, and none of them mangled into a stray
    // digit -- which is what ":value1" followed by a literal "0" would be.
    foreach ($placeholders as $placeholder) {
        expect($plan->translated['report'])->toContain($placeholder);
    }

    expect($plan->translated['report'])->toBe($source);
});

test('a transient gateway failure is waited out rather than surfaced', function (): void {
    // A 504 on the largest catalogue cost it from an eight-catalogue draft the
    // first time this ran for real. The batch is slow work at the far end and a
    // gateway in front of it gives up before the service does.
    Sleep::fake();

    Http::fake([
        'api.murf.ai/*' => Http::sequence()
            ->push('<html>504</html>', 504)
            ->push('<html>502</html>', 502)
            ->push(['translations' => [['source_text' => 'Refresh', 'translated_text' => 'Aggiorna']]], 200),
    ]);

    $engine = new MurfEngine('test-key');

    $out = $engine->translate(['Refresh'], new EngineBrief(
        sourceLocale: 'en', targetLocale: 'it', catalogue: 'nav',
        docblock: '', terms: [], neverTranslate: [], register: [],
    ));

    expect($out)->toBe(['Aggiorna']);

    // Two failures, so two waits, and they grow rather than hammering.
    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
    ]);
});

test('a status that is not transient fails immediately rather than retrying', function (): void {
    // 402 means the account is out of credit. Waiting eight seconds and asking
    // again four times does not add credit, it just makes the failure slower.
    Http::fake([
        'api.murf.ai/*' => Http::response('{"error":"payment required"}', 402),
    ]);

    $engine = new MurfEngine('test-key');

    expect(fn () => $engine->translate(['Refresh'], new EngineBrief(
        sourceLocale: 'en', targetLocale: 'it', catalogue: 'nav',
        docblock: '', terms: [], neverTranslate: [], register: [],
    )))->toThrow(TranslationFailed::class, 'after 1 attempt');
});
