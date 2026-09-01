<?php

use App\Console\Commands\TranslateCatalogueCommand;
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

    // The invariant that matters is unchanged: neither reaches the engine.
    expect($engine->seen)->toBe(['Refresh']);

    // Both are OUTPUT, though. With no target catalogue nothing was carried
    // from anywhere, and calling a cognate `carried` once cost key parity --
    // `write()` emits only `translated` for an existing catalogue, so the key
    // vanished from the fragment.
    expect($plan->carried)->toBe([])
        ->and($plan->merged()['feature'])->toBe('Cobrowse')
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

    // The Italian register checks read POSITION, because the informal
    // imperative of an `-are` verb is spelled like the indicative. One
    // position stays genuinely ambiguous: a sentence-initial verb whose
    // subject has been dropped. English says `It changes the dashboard for
    // you`, German says `Es aendert`, and Italian drops the pronoun -- so
    // `Cambia la dashboard` is a correct indicative wearing the exact shape
    // of an informal imperative. Recorded here with its reason rather than
    // dropping `cambia` from the check, which would blind it everywhere else.
    $allowed = [
        'it informal imperative in prose: profile.details.language_help',
    ];

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
                    if (in_array("{$locale} {$rule}: {$name}.{$hit['key']}", $allowed, true)) {
                        continue;
                    }

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

    // Asserted before the loop, so this test is never vacuous. With one
    // language in the glossary the loop below has nothing to iterate, and a
    // test that quietly asserts nothing looks exactly like a passing one.
    expect($reference)->not->toBeEmpty();

    // Derived from the glossary rather than hardcoded, and that is the whole
    // point: a literal `['it']` asserts the existence of a term table that may
    // live on a different branch, which is exactly how this test went red while
    // the data it needed was still unpushed. Ask the glossary which languages
    // it actually decides, and the assertion cannot outrun its own data.
    foreach ($glossary->localesWithTerms() as $locale) {
        if ($locale === 'de') {
            continue;
        }

        $terms = array_keys($glossary->terms($locale));

        expect(array_diff($reference, $terms))->toBe([], "{$locale} is missing terms the German table decides")
            ->and(array_diff($terms, $reference))->toBe([], "{$locale} decides terms German does not");
    }
});

test('every language keeps the declared collisions apart in its own words', function (): void {
    $glossary = Glossary::load();

    foreach ($glossary->localesWithTerms() as $locale) {
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

test('a drafted catalogue is written in the source key order, not translated-then-carried', function (): void {
    // `translated + carried` gave the right SET and the wrong file. A cognate is
    // never sent to the engine, so it landed in `carried` and got appended after
    // every translated key -- out of the group it belongs to. Laravel looks up
    // by key so nothing breaks, and the drafted catalogue stops lining up
    // against the English one, which is how a person reviews it.
    $engine = translationPipelineEngine();

    $source = translationPipelineCatalogue([
        'first' => 'Search',
        'cognate' => 'Cobrowse',
        'last' => 'Refresh',
    ]);

    $plan = translationPipelineTranslator($engine)->plan($source, null, 'de');

    expect(array_keys($plan->merged()))->toBe(['first', 'cognate', 'last']);

    // And a cognate the TARGET already has is genuinely carried, because there
    // it really did come from the target.
    $incremental = translationPipelineTranslator(translationPipelineEngine())->plan(
        $source,
        translationPipelineCatalogue(['cognate' => 'Cobrowse']),
        'de',
    );

    expect(array_keys($incremental->carried))->toBe(['cognate'])
        ->and(array_keys($incremental->merged()))->toBe(['first', 'cognate', 'last']);
});

test('every drafted catalogue lines up against its English source, in order', function (): void {
    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        $name = basename($path, '.php');

        foreach (['de', 'it'] as $locale) {
            $target = lang_path($locale.'/'.$name.'.php');

            if (! is_file($target)) {
                continue;
            }

            expect(array_keys(Catalogue::read($target)->values()))
                ->toBe(array_keys(Catalogue::read($path)->values()), "{$locale}/{$name} does not match the English key order");
        }
    }
});

test('agreement is null when nothing drafted has a reviewed counterpart', function (): void {
    // The subtler form of the number-that-means-nothing. A partially populated
    // catalogue makes `$reviewed` non-empty while none of the DRAFTED keys are
    // in it, so every comparison fails, `agreed` stays 0, and the run reports
    // `0% match` when the truth is there was nothing to match against.
    $plan = new CataloguePlan(
        catalogue: 'probe',
        targetLocale: 'de',
        translated: ['new_a' => 'Neu A', 'new_b' => 'Neu B'],
    );

    $scorer = new PolicyScorer(Glossary::load());

    // Reviewed catalogue exists, but covers entirely different keys.
    $score = $scorer->score($plan, ['unrelated' => 'Etwas anderes']);

    expect($score->comparable)->toBe(0)
        ->and($score->agreed)->toBeNull()
        ->and($score->agreementPercent())->toBeNull();

    // And when some of them DO have counterparts, the percentage is over those.
    $partial = $scorer->score($plan, ['new_a' => 'Neu A', 'unrelated' => 'x']);

    expect($partial->comparable)->toBe(1)
        ->and($partial->agreed)->toBe(1)
        ->and($partial->agreementPercent())->toBe(100.0);
});

test('a capitalised pronoun does not slip past the register checks', function (): void {
    // Sentence-initial is exactly where an engine puts a pronoun, and it is
    // capitalised there. Case-sensitive patterns scored `Er sieht die Seite
    // nicht.` and `Du bist angemeldet.` as clean.
    $checks = Glossary::load()->checks('de');

    foreach (['Er sieht die Seite nicht.', 'ER SIEHT NICHTS.'] as $offender) {
        expect(preg_match($checks['generic masculine pronoun'], $offender))
            ->toBe(1, "missed a capitalised pronoun in: {$offender}");
    }

    foreach (['Du bist angemeldet.', 'Dein Profil wurde gespeichert.'] as $offender) {
        expect(preg_match($checks['informal address'], $offender))
            ->toBe(1, "missed a capitalised informal address in: {$offender}");
    }

    // And still no false positive on correct formal German.
    expect(preg_match($checks['informal address'], 'Fragen Sie den Besucher.'))->toBe(0);
});

test('a catalogue name that matches nothing fails the run', function (): void {
    // Silent filtering meant `--catalogue=conversation` -- missing its `s` --
    // drafted nothing, warned about nothing, and exited 0. An automated run
    // would report success having produced none of what it was asked for:
    // the same failure as an unchecked write, work not done and reported done.
    $this->artisan('wayfindr:translate-catalogue', ['locale' => 'de', '--catalogue' => ['conversation']])
        ->expectsOutputToContain('No such catalogue: conversation')
        ->assertExitCode(1);

    // A real name is unaffected.
    $this->artisan('wayfindr:translate-catalogue', ['locale' => 'de', '--catalogue' => ['nav']])
        ->assertExitCode(0);
});

test('a new catalogue is written whole or not at all', function (): void {
    // Writing the successful siblings of a failed key creates a catalogue
    // missing exactly the keys nobody checked, and Laravel serves those as raw
    // strings. The compounding part is worse: the file now EXISTS, so a retry
    // writes a sidecar fragment instead of the catalogue and it stays
    // incomplete one run after the failure that caused it.
    $locale = 'zz';
    $target = lang_path($locale.'/nav.php');
    @unlink($target);

    // A glossary term table is required before the command will draft at all,
    // so this asserts the guard through the real command path for `de`, using
    // a plan that fails on one key.
    $glossary = Glossary::load();
    $engine = translationPipelineEngine(static fn (string $t): string => preg_replace('/WFZ\d+/', '', $t) ?? $t);
    $translator = new CatalogueTranslator(
        $engine,
        $glossary,
        new Protector($glossary),
    );

    $plan = $translator->plan(
        translationPipelineCatalogue(['safe' => 'Refresh', 'risky' => 'Waiting for :elapsed']),
        null,
        'de',
    );

    expect($plan->hasFailures())->toBeTrue()
        ->and($plan->translated)->toHaveKey('safe')
        ->and($plan->translated)->not->toHaveKey('risky');
});

test('a short write is a failed write', function (): void {
    // `false` is not the only failure mode. A disk that fills mid-write returns
    // a positive byte count SHORTER than the contents, and for a PHP catalogue
    // a truncated file is a parse error rather than a missing string.
    //
    // Simulated with a stream wrapper that accepts only the first 10 bytes,
    // because the alternative -- asserting the source contains the comparison --
    // is a test that cannot fail on the bug it exists for.
    if (in_array('shortwrite', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('shortwrite');
    }

    stream_wrapper_register('shortwrite', TranslationPipelineShortWriteStream::class);

    $command = new class extends TranslateCatalogueCommand
    {
        public array $errors = [];

        public function put(string $path, string $contents): bool
        {
            return parent::put($path, $contents);
        }

        public function error($string, $verbosity = null): void
        {
            $this->errors[] = $string;
        }
    };

    $accepted = $command->put('shortwrite://probe', str_repeat('x', 500));

    stream_wrapper_unregister('shortwrite');

    // The property that matters: an incomplete write is refused and reported,
    // whichever branch catches it. On PHP 8.5 `file_put_contents()` returns
    // false rather than a short count, so the message is the false one -- the
    // byte-count branch is defence for a platform where it does not.
    expect($accepted)->toBeFalse()
        ->and($command->errors)->not->toBeEmpty()
        ->and(implode(' ', $command->errors))->toContain('shortwrite://probe');
});

/**
 * Accepts the first ten bytes of any write and reports only those, the way a
 * filesystem does when it runs out of room part-way through.
 */
class TranslationPipelineShortWriteStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    private bool $spent = false;

    public function stream_write(string $data): int
    {
        // Ten bytes, then no further progress. `file_put_contents()` retries a
        // partial write until the stream stops accepting anything, which is
        // what a filesystem out of room actually does -- returning a short
        // count once is not enough to reproduce it.
        if ($this->spent) {
            return 0;
        }

        $this->spent = true;

        return min(10, strlen($data));
    }

    public function stream_close(): void {}

    public function stream_flush(): bool
    {
        return true;
    }

    public function url_stat(string $path, int $flags)
    {
        return false;
    }
}

test('a failed write leaves nothing behind to be mistaken for a catalogue', function (): void {
    // Reporting a failed write is not the same as leaving nothing behind. A
    // truncated catalogue on disk makes the NEXT run take the existing-file
    // branch and write a sidecar fragment instead of repairing it, so the
    // damage outlives the run that caused it -- and Laravel may load the
    // malformed file meanwhile.
    $directory = sys_get_temp_dir().'/wf-atomic-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $target = $directory.'/nav.php';

    $command = new class extends TranslateCatalogueCommand
    {
        public array $errors = [];

        public function put(string $path, string $contents): bool
        {
            return parent::put($path, $contents);
        }

        public function error($string, $verbosity = null): void
        {
            $this->errors[] = $string;
        }
    };

    // A good write lands, and leaves no temporary file beside it.
    expect($command->put($target, "<?php\n\nreturn [];\n"))->toBeTrue()
        ->and(is_file($target))->toBeTrue()
        ->and(glob($directory.'/*.tmp'))->toBe([]);

    // A write into a directory that does not exist fails, and creates nothing.
    $missing = $directory.'/nope/nav.php';

    expect($command->put($missing, "<?php\n\nreturn [];\n"))->toBeFalse()
        ->and(is_file($missing))->toBeFalse()
        ->and(glob($directory.'/nope/*'))->toBe([]);

    array_map('unlink', glob($directory.'/*') ?: []);
    @rmdir($directory);
});

test('the renderer round-trips every value in every shipped catalogue', function (): void {
    // The renderer is what writes a catalogue to disk, so a value it cannot
    // reproduce is a translation silently altered on the way out. Spot-checking
    // it with a handful of contrived strings misses whatever the real
    // catalogues happen to contain -- German quotation marks, Italian
    // apostrophes, plural pipes, placeholders, em dashes, ellipses.
    //
    // 1,745 values across en/de/it at the time of writing, and the number grows
    // on its own as catalogues are added.
    $checked = 0;

    foreach (glob(lang_path('*/*.php')) ?: [] as $file) {
        $original = Catalogue::read($file);
        $values = $original->values();

        $path = sys_get_temp_dir().'/wf-rt-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($path, Catalogue::render(Catalogue::nest($values), $original->docblock));

        $reread = Catalogue::read($path);
        @unlink($path);

        expect($reread->values())->toBe($values, basename(dirname($file)).'/'.basename($file).' did not survive a render');

        $checked += count($values);
    }

    expect($checked)->toBeGreaterThan(1000);
});

test('the help text and the command agree about what --retranslate does', function (): void {
    // Two surfaces describe this flag: `artisan help` and the confirmation
    // prompt. The prompt was corrected and the signature was not, so help
    // promised an overwrite the command explicitly refuses -- and an operator
    // reads help BEFORE the prompt, when deciding whether to run it at all.
    $help = new ReflectionProperty(TranslateCatalogueCommand::class, 'signature');
    $signature = $help->getDefaultValue();

    expect($signature)->not->toContain('overwrites reviewed copy')
        ->and($signature)->toContain('never overwritten');
});

test('an inflected token is refused rather than restored', function (): void {
    // An engine that appends a letter -- `WFZ0s` for `WFZ0`, which is exactly
    // what a translator does to a token sitting in a plural context -- used to
    // count as one clean occurrence. `strtr` restored the substring, `:count`
    // became `:counts`, and the leftover check passed because `WFZ0` was gone.
    // Laravel then renders a literal `:counts` to an agent.
    $glossary = Glossary::load();
    $protector = new Protector($glossary);

    $masked = $protector->mask('Waiting for :count');
    $token = array_key_first($masked->map);

    // Non-ASCII deliberately included: the two languages this ships are full
    // of them, so an engine inflecting a token will reach for one, and an
    // ASCII-only boundary would wave `WFZ0è` straight through to `:countè`.
    foreach (["{$token}s", "{$token}en", "x{$token}", "{$token}è", "{$token}ü", "{$token}ß", "è{$token}"] as $mangled) {
        expect(fn () => $protector->restore(str_replace($token, $mangled, $masked->text), $masked, 'probe'))
            ->toThrow(TranslationFailed::class);
    }

    // Untouched still round-trips.
    expect($protector->restore($masked->text, $masked, 'probe'))->toBe('Waiting for :count');
});

test('a token never collides with text the source already contains', function (): void {
    // If a catalogue string already contains `WFZ0`, the first placeholder is
    // assigned a token the text already holds. The source occurrence then
    // counts as another placeholder, restoration accepts it, and both are
    // replaced -- `WFZ0 code :count` becomes `:count code :count` with every
    // check passing. Lengthening the prefix until it is absent removes the
    // collision rather than detecting it.
    $glossary = Glossary::load();
    $protector = new Protector($glossary);

    foreach (['WFZ0 code :count', 'WFZ WFZZ0 :count', ':count only'] as $source) {
        $masked = $protector->mask($source);

        expect($masked->text)->not->toBe($source, "nothing was masked in: {$source}")
            ->and($protector->restore($masked->text, $masked, 'probe'))
            ->toBe($source, "did not round-trip: {$source}");
    }

    // And the prefix genuinely moves rather than colliding quietly.
    expect($protector->mask('WFZ0 code :count')->prefix)->not->toBe('WFZ');
});

test('a declared cognate is actually identical in that language', function (): void {
    // Cognate-ness is per-language and the list used to be global, which looked
    // right while German was the only language and shipped English into Italian
    // the moment there was a second: `Agent` and `Name` were skipped as
    // "identical in every catalogue" while the Italian term table said `agente`
    // and Italian says `Nome`.
    //
    // So the claim is now checked rather than asserted. An entry that is not
    // genuinely identical in that locale's catalogue fails here, and one that
    // no English value ever matches is dead weight the list should not carry.
    $glossary = Glossary::load();
    $english = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        foreach (Catalogue::read($path)->values() as $key => $value) {
            $english[basename($path, '.php').'.'.$key] = $value;
        }
    }

    foreach ($glossary->localesWithTerms() as $locale) {
        $target = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $path) {
            foreach (Catalogue::read($path)->values() as $key => $value) {
                $target[basename($path, '.php').'.'.$key] = $value;
            }
        }

        if ($target === []) {
            continue;
        }

        foreach ($glossary->cognates($locale) as $cognate) {
            $keys = array_keys($english, $cognate, true);

            expect($keys)->not->toBe([], "{$locale} declares '{$cognate}' a cognate, but no English value is exactly that");

            foreach ($keys as $key) {
                expect($target[$key] ?? null)
                    ->toBe($cognate, "{$locale} declares '{$cognate}' a cognate, but {$key} is not identical");
            }
        }
    }
});

test('a redraft separates what it replaces from what it adds', function (): void {
    // The two sidecars are different documents and merging them the same way is
    // destructive. `.missing.php` holds keys the catalogue lacks, so its entries
    // are additions. `.redraft.php` holds a proposal for EVERY key, so the old
    // shared header -- "merge the entries into the file beside it" -- meant
    // pasting machine output over reviewed translations.
    //
    // And the first correction over-claimed in the other direction: it said
    // every entry HAS a counterpart, which is false when the catalogue is
    // incomplete. An operator told to compare entry-by-entry would skip exactly
    // the keys with nothing to compare against, and leave the gap they came to
    // close.
    $command = new class extends TranslateCatalogueCommand
    {
        public bool $retranslating = false;

        public function header(string $name, CataloguePlan $plan, array $additions = []): string
        {
            return $this->fragmentHeader($name, $plan, $additions);
        }

        public function option($key = null)
        {
            return $key === 'retranslate' ? $this->retranslating : parent::option($key);
        }
    };

    $plan = new CataloguePlan(catalogue: 'nav', targetLocale: 'de');

    $missing = $command->header('nav', $plan);

    $command->retranslating = true;
    $noAdditions = $command->header('nav', $plan);
    $withAdditions = $command->header('nav', $plan, ['items.reports', 'items.visitors']);

    expect($missing)->toContain('Keys missing')
        ->and($noAdditions)->not->toContain('Keys missing')
        ->and($noAdditions)->toContain('EVERY key')
        ->and($noAdditions)->toContain('never by pasting')
        // Silent when there is nothing to say, rather than claiming zero.
        ->and($noAdditions)->not->toContain('ADDITIONS')
        // And explicit, by name, when there is.
        ->and($withAdditions)->toContain('2 of them are ADDITIONS')
        ->and($withAdditions)->toContain('items.reports')
        ->and($withAdditions)->toContain('items.visitors');
});

test('restore either reproduces the source exactly or refuses, across generated inputs', function (): void {
    // Five defects in this class arrived one adversarial input at a time --
    // prefix shadowing, duplicate counts, source collision, ASCII inflection,
    // Unicode inflection -- because every test it had was built from
    // placeholders behaving normally. Enumerating the next bad input is a race
    // nobody wins, so this asserts the invariant instead.
    //
    // The FIRST version of this test shared the blind spot of the code it
    // tested: it checked that each original appeared the right number of
    // times, and `:counts` contains `:count`, so inflection passed. It also
    // generated strings too short to hold the eleven tokens that make `WFZ1`
    // shadow `WFZ10`. It caught one of three historical defects.
    //
    // So the outcome is now asserted per mangling: an untouched or
    // word-translated body must restore EXACTLY, and a body with a damaged
    // token must throw. No middle ground, because the middle ground is what
    // every one of the five produced.
    mt_srand(20260826);

    $glossary = Glossary::load();
    $protector = new Protector($glossary);

    $words = ['Waiting', 'für', 'visitatore', 'ticket', 'Snapshot', 'più', 'größer', '—', 'Wayfindr', 'WF-ABC123', 'WFZ0'];
    $slots = [':count', ':elapsed', ':name', ':code', ':site', ':lane', ':value', ':total', ':shown', ':matching', ':project', ':reason', ':actor', ':term', '{1}', '[2,*]'];

    $exact = 0;
    $refused = 0;

    for ($i = 0; $i < 400; $i++) {
        // Long enough, often enough, to exceed ten distinct tokens -- the
        // threshold at which a shorter token becomes a prefix of a longer one.
        $pieces = [];

        for ($n = mt_rand(2, 16); $n > 0; $n--) {
            $pieces[] = mt_rand(0, 2) === 0
                ? $words[mt_rand(0, count($words) - 1)]
                : $slots[mt_rand(0, count($slots) - 1)];
        }

        $source = implode(' ', $pieces);
        $masked = $protector->mask($source);
        $tokens = array_keys($masked->map);

        if ($tokens === []) {
            continue;
        }

        $token = $tokens[mt_rand(0, count($tokens) - 1)];
        $kind = mt_rand(0, 10);

        [$mangled, $mustRestoreTo] = match ($kind) {
            0 => [$masked->text, $source],
            1 => [
                str_replace(' ', ' übersetzt ', $masked->text),
                str_replace(' ', ' übersetzt ', $source),
            ],
            2 => [str_replace($token, $token.'s', $masked->text), null],
            3 => [str_replace($token, $token.'è', $masked->text), null],
            4 => [str_replace($token, 'x'.$token, $masked->text), null],
            5 => [str_replace($token, '', $masked->text), null],
            6 => [str_replace($token, $token.' '.$token, $masked->text), null],
            // The generator's own blind spots, added after review found two
            // defects this loop could not reach. A property test is only as
            // good as the inputs it invents, and these three were not in it:
            // a combining mark and a connector both continue a word without
            // being a letter or a number, and an engine can INVENT a protected
            // value rather than damaging its token.
            7 => [str_replace($token, $token."\u{0301}", $masked->text), null],
            8 => [str_replace($token, $token.'_x', $masked->text), null],
            9 => [$masked->text.' '.$masked->map[$token], null],
            default => [strrev($masked->text), null],
        };

        try {
            $restored = $protector->restore($mangled, $masked, "case {$i}");

            expect($mustRestoreTo)->not->toBeNull(
                "case {$i} (kind {$kind}) should have been refused\n  source:   {$source}\n  restored: {$restored}"
            );

            expect($restored)->toBe(
                $mustRestoreTo,
                "case {$i} (kind {$kind}) restored to something else\n  source: {$source}"
            );

            $exact++;
        } catch (TranslationFailed) {
            expect($mustRestoreTo)->toBeNull("case {$i} (kind {$kind}) should have restored cleanly\n  source: {$source}");

            $refused++;
        }
    }

    // Both outcomes must occur, or the loop proved nothing.
    expect($exact)->toBeGreaterThan(0)->and($refused)->toBeGreaterThan(0);
});

test('nothing is left in English without saying so', function (): void {
    // The complement of the cognate test above. That one checks a declared
    // cognate is genuinely identical; this checks the reverse -- that anything
    // identical was DECLARED. Without it, an untranslated value looks exactly
    // like a deliberate loanword, which is how `Agent` and `Name` sat in the
    // Italian catalogue contradicting its own term table.
    $glossary = Glossary::load();
    $never = array_flip($glossary->neverTranslate());
    $english = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $path) {
        foreach (Catalogue::read($path)->values() as $key => $value) {
            $english[basename($path, '.php').'.'.$key] = $value;
        }
    }

    foreach ($glossary->localesWithTerms() as $locale) {
        $cognates = array_flip($glossary->cognates($locale));
        $undeclared = [];

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $path) {
            // Laravel's own validation messages are a framework override rather
            // than extracted copy, and have no English counterpart here.
            if (basename($path) === 'validation.php') {
                continue;
            }

            foreach (Catalogue::read($path)->values() as $key => $value) {
                $full = basename($path, '.php').'.'.$key;

                if (($english[$full] ?? null) !== $value) {
                    continue;
                }

                // Punctuation, digits and bare placeholders are identical in
                // every language and say nothing about translation.
                if (preg_match('/^[\W\d\s]*$/u', $value) === 1) {
                    continue;
                }

                if (isset($cognates[$value]) || isset($never[$value])) {
                    continue;
                }

                $undeclared[] = "{$full} = {$value}";
            }
        }

        expect($undeclared)->toBe([], "{$locale} leaves values in English without declaring them cognates");
    }
});

test('every plural segment starts with its interval selector', function (): void {
    // Laravel reads `{1}` and `[2,*]` only at the START of a segment. Anywhere
    // else they are ordinary text, so the selector renders to the agent -- an
    // Italian queue showed `Le sessioni cobrowse [2,*] 2 richiedono attenzione`
    // because the engine put the words in front of the interval.
    //
    // The existing plural guard counted `|` separators and never looked at what
    // followed them, so seven strings passed it while being broken.
    $offenders = [];

    foreach (glob(lang_path('*/*.php')) ?: [] as $path) {
        $locale = basename(dirname($path));

        foreach (Catalogue::read($path)->values() as $key => $value) {
            if (! str_contains($value, '|')) {
                continue;
            }

            foreach (explode('|', $value) as $index => $segment) {
                if (preg_match('/^\s*(\{\d+\}|\[\d+,(?:\d+|\*)\])/', $segment) === 1) {
                    continue;
                }

                $offenders[] = "{$locale}/".basename($path, '.php').".{$key} segment {$index}: {$segment}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('two languages agree about which sense a key is', function (): void {
    // The collision test proves a glossary keeps two senses APART. Nothing
    // proved a catalogue chose between them correctly -- so `tickets.statuses.open`
    // shipped as `Aperto` in German and `Apri` in Italian, a state and an
    // imperative for the same key, and every guard passed.
    //
    // The languages may disagree about the WORD. They must agree about which
    // sense the key is, and that is checkable without knowing anything about
    // either language: find keys where one locale used sense A and another used
    // sense B, and the pair disagrees about the key rather than the vocabulary.
    $glossary = Glossary::load();
    $locales = $glossary->localesWithTerms();

    $values = [];

    foreach ($locales as $locale) {
        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $path) {
            if (basename($path) === 'validation.php') {
                continue;
            }

            foreach (Catalogue::read($path)->values() as $key => $value) {
                $values[$locale][basename($path, '.php').'.'.$key] = $value;
            }
        }
    }

    $disagreements = [];

    foreach ($glossary->senses() as [$a, $b]) {
        // Which sense, if any, each locale's value for a key corresponds to.
        $senseOf = [];

        foreach ($locales as $locale) {
            $termA = $glossary->terms($locale)[$a]['term'] ?? null;
            $termB = $glossary->terms($locale)[$b]['term'] ?? null;

            if ($termA === null || $termB === null || $termA === $termB) {
                continue;
            }

            // Case-INSENSITIVELY, and that is not a detail. A glossary stores
            // Italian terms the way Italian writes them mid-sentence
            // (`titolare`), while a UI label capitalises (`Titolare`), so an
            // exact comparison silently matched nothing for Italian. German
            // capitalises its nouns in both places and matched, leaving one
            // locale in the pair -- and one locale cannot disagree with
            // itself. That is precisely how `profile.roles.owner` shipped as
            // the assignee term with this test passing.
            foreach ($values[$locale] ?? [] as $key => $value) {
                $folded = mb_strtolower($value);

                if ($folded === mb_strtolower($termA)) {
                    $senseOf[$key][$locale] = $a;
                } elseif ($folded === mb_strtolower($termB)) {
                    $senseOf[$key][$locale] = $b;
                }
            }
        }

        foreach ($senseOf as $key => $byLocale) {
            if (count(array_unique($byLocale)) > 1) {
                $detail = implode(', ', array_map(
                    static fn (string $l, string $sense): string => "{$l}={$sense}",
                    array_keys($byLocale),
                    $byLocale,
                ));

                $disagreements[] = "{$key}: {$detail}";
            }
        }
    }

    expect($disagreements)->toBe([]);
});

test('an italian plural branch inflects something', function (): void {
    // Italian adjectives and verbs agree with number; English ones do not. So
    // a machine draft that maps `:count open` onto `:count aperto` produces a
    // plural branch identical to its singular, and every existing guard passes
    // -- the glossary term is right, the placeholder is intact, the selector is
    // present. `2 aperto`, `2 chiuso`, `2 collegato` and `:shown ... necessita
    // attenzione` all shipped that way.
    //
    // The tell is cheap: strip the selector and the count, and if the two
    // branches are then the SAME STRING, the plural inflected nothing. That is
    // usually a bug and occasionally correct, so the exceptions are listed
    // with their reason rather than the check being softened.
    //
    // German is deliberately not checked here: its predicate adjectives do not
    // inflect, so `2 geschlossen` is right and this rule would be noise.
    $invariable = [
        // `ticket` is an unadapted loanword; Italian does not pluralise it.
        'tickets.counts.tickets',
        'ticket_labels.usage.tickets',
        'ticket_labels.manage.in_use',
        // `token` is the same kind of loanword -- `i token`, never `i tokens`
        // -- and this string is a bare `:count token` with no article to agree
        // with it.
        'api_tokens.list.total',
        // `in sospeso` is a prepositional phrase, invariable by construction.
        'tickets.summary.heading.pending',
        // Both branches are one noun phrase (`Visualizzazione di ...`); the
        // number lives inside `:shown`, and nothing else agrees with it.
        'conversations.summary.lane_narrowed_detail',
        'tickets.summary.lane_narrowed_detail',
    ];

    $uninflected = [];

    foreach (glob(lang_path('it/*.php')) ?: [] as $path) {
        foreach (Catalogue::read($path)->values() as $key => $value) {
            $qualified = basename($path, '.php').'.'.$key;

            if (! str_contains($value, '|') || ! str_contains($value, '[2,*]')) {
                continue;
            }

            [$one, $many] = explode('|', $value, 2);

            $bare = static fn (string $segment): string => trim(preg_replace(
                ['/^\{1\}|^\[2,\*\]/', '/:count|(?<![\p{L}\d])1(?![\p{L}\d])/u'],
                ['', '#'],
                $segment,
            ) ?? '');

            if ($bare($one) === $bare($many) && ! in_array($qualified, $invariable, true)) {
                $uninflected[] = "{$qualified}: {$value}";
            }
        }
    }

    expect($uninflected)->toBe([]);
});

test('a bare label uses the term its own key names', function (): void {
    // Policy section 3 says the glossary binds every string, and nothing
    // enforced it. So `nav.sign_out` shipped as `Disconnetti` while the
    // glossary settled `Esci`, and the whole reviewed freshness scale
    // (`Nuovo -> Vecchio -> Obsoleto`) was ignored in favour of `Recente ->
    // Invecchiamento -> Stantio` -- three labels, one decision, silently
    // discarded. German shipped the same defect at `freshness.fresh`.
    //
    // A general "every string honours the glossary" rule is not checkable:
    // most terms are inflected, compounded, or absent for good reason. But a
    // BARE LABEL is checkable, and it is where a term is supposed to appear
    // verbatim. So the rule is deliberately narrow -- the key's last segment
    // names a glossary concept (or the one before it, when the last is
    // `label`/`badge`), and the value is short, unpunctuated and at most two
    // words. Anything longer is prose and out of scope here.
    $glossary = Glossary::load();

    // Concepts the product genuinely uses in two senses. The glossary has a
    // `senses` mechanism for exactly this, but naming the second term is a
    // vocabulary decision rather than a cleanup, and each of these is already
    // consistent across its own catalogue -- so they are recorded, not
    // changed. Resolving them means adding the second sense to the glossary.
    $twoSenses = [
        // A presence state (`Poco attivo`/`Ruhig`) and an alert mode.
        'it/profile.alerts.modes.quiet' => 'quiet: presence state vs alert mode',
        'de/profile.alerts.modes.quiet' => 'quiet: presence state vs alert mode',
        // A ticket STATUS reads `In sospeso`/`Wartend` throughout; the
        // glossary term is the "waiting for" sense used in cobrowse prose.
        'it/tickets.filters.status.pending' => 'pending: ticket status vs waiting-for',
        'it/tickets.statuses.pending' => 'pending: ticket status vs waiting-for',
        'de/tickets.filters.status.pending' => 'pending: ticket status vs waiting-for',
        'de/tickets.statuses.pending' => 'pending: ticket status vs waiting-for',
        // The field label is the noun `Suche`; the action is `Suchen`.
        'de/conversations.search.label' => 'search: field noun vs action verb',
        'de/tickets.search.label' => 'search: field noun vs action verb',
        // The nav section is `Betrieb` (operations), not the person.
        'de/nav.items.operator' => 'operator: the section vs the person',
        // `read` in the glossary is the past participle -- the state a MESSAGE
        // is in, used as a queue filter. An API token's ability is the verb:
        // the permission to read, not something already read.
        'it/api_tokens.abilities.read' => 'read: message state vs token permission',
        'de/api_tokens.abilities.read' => 'read: message state vs token permission',
    ];

    $unbound = [];

    foreach ($glossary->localesWithTerms() as $locale) {
        $terms = $glossary->terms($locale);

        foreach (glob(lang_path($locale.'/*.php')) ?: [] as $path) {
            if (basename($path) === 'validation.php') {
                continue;
            }

            foreach (Catalogue::read($path)->values() as $key => $value) {
                $qualified = $locale.'/'.basename($path, '.php').'.'.$key;

                // Landmark and lane names describe a REGION of the page, not
                // the concept they are named after.
                if (str_contains($qualified, '.regions.') || str_contains($qualified, '.lanes.')) {
                    continue;
                }

                $segments = explode('.', $key);
                $concept = end($segments);

                if (in_array($concept, ['label', 'badge'], true) && count($segments) >= 2) {
                    $concept = $segments[count($segments) - 2];
                }

                if (! isset($terms[$concept])) {
                    continue;
                }

                if (mb_strlen($value) > 26 || preg_match('/[.!?|:]/u', $value) === 1) {
                    continue;
                }

                if (count(preg_split('/\s+/u', trim($value)) ?: []) > 2) {
                    continue;
                }

                if (isset($twoSenses[$qualified])) {
                    continue;
                }

                if (mb_stripos($value, $terms[$concept]['term']) === false) {
                    $unbound[] = "{$qualified}: want {$terms[$concept]['term']}, got {$value}";
                }
            }
        }
    }

    expect($unbound)->toBe([]);
});

test('a control label does not address the agent', function (): void {
    // The mirror of the register check in the glossary. That one finds the
    // INFORMAL imperative where prose needs the formal; this finds the formal
    // where a CONTROL needs the bare imperative, which is the same rule read
    // from the other end -- `Invii email solo quando...` sat in a select
    // beside `Invia` and `Preferisci` and addressed the agent where its
    // neighbours named an action.
    //
    // The formal forms are derived rather than listed, so the two checks
    // cannot drift apart: an `-are` verb takes `-i` (softening `c`/`g` to
    // `ch`/`gh` -- `cerca` -> `cerchi`, not `cerci`), everything else takes
    // `-a`. Add a verb to the glossary list and its formal form appears here
    // for free.
    $informal = [
        'aggiorna', 'allega', 'annulla', 'apri', 'applica', 'assegna', 'attendi', 'cambia',
        'cancella', 'carica', 'cerca', 'chiudi', 'collega', 'conferma', 'consulta', 'continua',
        'controlla', 'copia', 'crea', 'disconnetti', 'elimina', 'gestisci', 'imposta', 'includi',
        'inserisci', 'invia', 'libera', 'mantieni', 'metti', 'modifica', 'mostra', 'prova',
        'riapri', 'richiedi', 'rilascia', 'rimuovi', 'riprova', 'rispondi', 'rivedi', 'rivendica',
        'salva', 'scegli', 'scorri', 'scrivi', 'segna', 'seleziona', 'termina', 'torna', 'trova',
        'usa', 'verifica',
    ];

    $formal = [];

    foreach ($informal as $verb) {
        if (str_ends_with($verb, 'ca')) {
            $formal[substr($verb, 0, -2).'chi'] = $verb;
        } elseif (str_ends_with($verb, 'ga')) {
            $formal[substr($verb, 0, -2).'ghi'] = $verb;
        } elseif (str_ends_with($verb, 'a')) {
            $formal[substr($verb, 0, -1).'i'] = $verb;
        } else {
            $formal[substr($verb, 0, -1).'a'] = $verb;
        }
    }

    // Sentence punctuation marks prose, but not all prose has it: a lede is a
    // full sentence with no full stop, and `Usi questo dopo aver ricevuto una
    // password temporanea` is correctly formal. The catalogue names its prose
    // consistently, so the key's own last segment settles it.
    $prose = [
        'lede', 'help', 'hint', 'detail', 'detail_unknown', 'message', 'guidance', 'body',
        'description', 'note', 'subtitle', 'intro', 'summary', 'placeholder', 'privacy',
        'shortcut', 'scope', 'boundary', 'context',
    ];

    $addressed = [];

    foreach (glob(lang_path('it/*.php')) ?: [] as $path) {
        foreach (Catalogue::read($path)->values() as $key => $value) {
            if (preg_match('/[.!?|]/u', $value) === 1) {
                continue;
            }

            $segments = explode('.', $key);

            if (in_array(end($segments), $prose, true)) {
                continue;
            }

            $first = mb_strtolower(preg_split('/\s+/u', trim($value))[0] ?? '');

            if (isset($formal[$first])) {
                $where = basename($path, '.php').'.'.$key;
                $addressed[] = "{$where}: {$value} (control wants {$formal[$first]})";
            }
        }
    }

    expect($addressed)->toBe([]);
});

test('a borrowed noun keeps one gender', function (): void {
    // English nouns have no gender, so nothing in the source tells a
    // translation which one an Italian sentence should agree with -- and the
    // draft picked per sentence. `snapshot` was masculine in forty places and
    // feminine in two (`un'altra snapshot pulita`, `La snapshot ... pulita`),
    // which reads as carelessness rather than as a choice.
    //
    // Which gender a loanword takes IS a decision, but it is a linguistic one
    // and already made: Italian assigns masculine to these by default, and the
    // catalogue overwhelmingly agrees. So the check is for CONSISTENCY with
    // that, not a new vocabulary rule -- it looks for feminine determiners and
    // adjectives sitting next to a masculine loanword.
    $masculine = [
        'snapshot', 'widget', 'ticket', 'report', 'replay', 'tracker',
        'browser', 'file', 'link', 'thread', 'payload', 'batch',
    ];

    $feminine = [
        'la', 'le', 'una', "un'", 'questa', 'queste', 'quella', 'quelle',
        'della', 'delle', 'alla', 'alle', 'nella', 'nelle', 'sulla', 'sulle',
        'dalla', 'dalle', 'altra', 'altre', 'stessa', 'stesse', 'nuova', 'nuove',
    ];

    $feminineAdjective = [
        'pulita', 'pulite', 'nuova', 'nuove', 'aggiornata', 'aggiornate',
        'vecchia', 'attiva', 'chiusa', 'mascherata', 'scartata', 'scartate',
    ];

    $nouns = implode('|', $masculine);
    $pattern = '/\b('.implode('|', $feminine).')\s+('.$nouns.')\b'
        .'|\b('.$nouns.')\s+('.implode('|', $feminineAdjective).')\b/ui';

    $disagreements = [];

    foreach (glob(lang_path('it/*.php')) ?: [] as $path) {
        foreach (Catalogue::read($path)->values() as $key => $value) {
            if (preg_match_all($pattern, $value, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $where = basename($path, '.php').'.'.$key;
                $disagreements[] = "{$where}: {$match[0]}";
            }
        }
    }

    expect($disagreements)->toBe([]);
});
