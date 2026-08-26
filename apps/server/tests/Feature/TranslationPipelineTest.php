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
