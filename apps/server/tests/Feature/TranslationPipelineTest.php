<?php

use App\Support\Translation\Catalogue;
use App\Support\Translation\CatalogueTranslator;
use App\Support\Translation\EngineBrief;
use App\Support\Translation\Glossary;
use App\Support\Translation\Protector;
use App\Support\Translation\TranslationEngine;
use App\Support\Translation\TranslationFailed;

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
