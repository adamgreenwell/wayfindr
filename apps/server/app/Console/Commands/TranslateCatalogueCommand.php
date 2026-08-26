<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Translation\Catalogue;
use App\Support\Translation\CataloguePlan;
use App\Support\Translation\CatalogueTranslator;
use App\Support\Translation\Engines\MurfEngine;
use App\Support\Translation\Engines\PassthroughEngine;
use App\Support\Translation\Glossary;
use App\Support\Translation\Protector;
use App\Support\Translation\TranslationEngine;
use App\Support\Translation\TranslationFailed;
use Illuminate\Console\Command;

class TranslateCatalogueCommand extends Command
{
    protected $signature = 'wayfindr:translate-catalogue
        {locale : Target locale, e.g. it}
        {--engine=passthrough : passthrough|murf}
        {--catalogue=* : Limit to named catalogues, e.g. --catalogue=nav}
        {--write : Write the result instead of only reporting it}
        {--retranslate : Replace values that already exist -- overwrites reviewed copy}';

    protected $description = 'Draft a language catalogue from the English source, the glossary, and the policy.';

    public function handle(): int
    {
        $locale = strtolower(trim((string) $this->argument('locale')));

        try {
            $glossary = Glossary::load();
        } catch (TranslationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $glossary->hasTermsFor($locale)) {
            $this->error("The glossary has no term table for '{$locale}'.");
            $this->line('A language without a decided vocabulary is not ready to be drafted; see docs/product/translation-policy.md section 3.');

            return self::FAILURE;
        }

        // A merged collision is the one failure worth refusing on. Every string
        // in the run inherits it, and it is invisible in a diff read file by
        // file -- which is the entire reason the pairs are declared.
        $merged = $glossary->mergedCollisions($locale);

        if ($merged !== []) {
            foreach ($merged as [$a, $b, $term]) {
                $this->error("Glossary collision: '{$a}' and '{$b}' both resolve to '{$term}'.");
            }

            return self::FAILURE;
        }

        try {
            $engine = $this->engine();
        } catch (TranslationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Only the WRITE is dangerous. Planning a retranslation changes nothing
        // and refusing to do it without a human at the keyboard just makes the
        // safe way to inspect a run the one you cannot script.
        if ($this->option('retranslate') && $this->option('write') && ! $this->confirmRetranslate($locale)) {
            return self::FAILURE;
        }

        $this->line("Engine: {$engine->name()}".($engine->usesBrief() ? '' : '  (cannot read the brief: no context slot)'));

        $unconfirmed = $glossary->unconfirmed($locale);

        if ($unconfirmed !== []) {
            $this->warn('Glossary terms still unconfirmed, so anything inheriting them is provisional:');

            foreach ($unconfirmed as $key => $term) {
                $this->line("  {$key} = {$term}");
            }
        }

        $translator = new CatalogueTranslator($engine, $glossary, new Protector($glossary));
        $failed = false;

        foreach ($this->catalogues() as $sourcePath) {
            $name = basename($sourcePath, '.php');
            $targetPath = lang_path($locale.'/'.$name.'.php');

            $plan = $translator->plan(
                Catalogue::read($sourcePath),
                is_file($targetPath) ? Catalogue::read($targetPath) : null,
                $locale,
                (bool) $this->option('retranslate'),
            );

            $this->report($plan);

            if ($plan->hasFailures()) {
                $failed = true;
            }

            if ($this->option('write')) {
                $this->write($plan, $targetPath, $name);
            }
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Nothing written. Re-run with --write once the plan reads right.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function engine(): TranslationEngine
    {
        return match ((string) $this->option('engine')) {
            'passthrough' => new PassthroughEngine,
            'murf' => new MurfEngine,
            default => throw new TranslationFailed("Unknown engine '{$this->option('engine')}'. Known: passthrough, murf."),
        };
    }

    private function confirmRetranslate(string $locale): bool
    {
        $this->warn("--retranslate replaces copy in lang/{$locale} that a person may already have reviewed.");

        return $this->confirm('Replace existing translations?', false);
    }

    /**
     * @return array<int, string>
     */
    private function catalogues(): array
    {
        $only = (array) $this->option('catalogue');
        $paths = glob(lang_path('en/*.php')) ?: [];

        if ($only === []) {
            return $paths;
        }

        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => in_array(basename($path, '.php'), $only, true),
        ));
    }

    private function report(CataloguePlan $plan): void
    {
        $this->newLine();
        $this->line("<info>{$plan->catalogue}</info> -> {$plan->targetLocale}");
        $this->line(sprintf(
            '  %d drafted, %d kept, %d failed, %d flagged for review',
            count($plan->translated),
            count($plan->carried),
            count($plan->failures),
            count($plan->review),
        ));

        foreach ($plan->failures as $key => $why) {
            $this->error("  FAILED {$key}: {$why}");
        }

        foreach ($plan->review as $key => $why) {
            $this->line("  <comment>review</comment> {$key}: {$why}");
        }
    }

    private function write(CataloguePlan $plan, string $targetPath, string $name): void
    {
        if ($plan->isEmpty()) {
            $this->line('  nothing to write');

            return;
        }

        @mkdir(dirname($targetPath), 0o755, true);

        // The rule the class exists for: an existing catalogue is never
        // regenerated. Its in-array comments would go with it, and the loss
        // would look exactly like a successful run.
        if (is_file($targetPath)) {
            $fragment = dirname($targetPath).'/'.$name.'.missing.php';

            file_put_contents($fragment, Catalogue::render(
                Catalogue::nest($plan->translated),
                $this->fragmentHeader($name, $plan),
            ));

            $this->line("  wrote <info>{$fragment}</info> -- merge by hand; the catalogue beside it carries comments a rewrite would delete");

            return;
        }

        file_put_contents($targetPath, Catalogue::render(
            Catalogue::nest($plan->merged()),
            $this->catalogueHeader($name, $plan),
        ));

        $this->line("  wrote <info>{$targetPath}</info>");
    }

    private function catalogueHeader(string $name, CataloguePlan $plan): string
    {
        return implode("\n", [
            "Drafted from lang/en/{$name}.php. NOT YET REVIEWED.",
            '',
            'Machine output against the glossary in resources/translation/glossary.php',
            'and the rules in docs/product/translation-policy.md. Every value here is a',
            'proposal: the pipeline optimises for a diff somebody can check, not for a',
            'translation nobody has to.',
            '',
            'Review order that actually finds things: the glossary terms first, then the',
            'short strings against the rendered surface, then register in the prose.',
            'Placeholders and plural segments are held by the pipeline and are not worth',
            'your attention.',
            '',
            count($plan->review) > 0
                ? count($plan->review).' plural string(s) need their segment count checked against '.$plan->targetLocale.'.'
                : 'No plural strings in this catalogue.',
        ]);
    }

    private function fragmentHeader(string $name, CataloguePlan $plan): string
    {
        return implode("\n", [
            "Keys missing from lang/{$plan->targetLocale}/{$name}.php, drafted and NOT REVIEWED.",
            '',
            'This is a fragment, not a catalogue. Merge the entries into the file beside',
            'it and delete this one -- the catalogue carries comments that a regenerated',
            'file would silently drop, which is why nothing regenerated it.',
        ]);
    }
}
