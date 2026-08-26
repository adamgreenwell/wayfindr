<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Translation\Catalogue;
use App\Support\Translation\CataloguePlan;
use App\Support\Translation\CatalogueTranslator;
use App\Support\Translation\Engines\MurfEngine;
use App\Support\Translation\Engines\PassthroughEngine;
use App\Support\Translation\Glossary;
use App\Support\Translation\PolicyScore;
use App\Support\Translation\PolicyScorer;
use App\Support\Translation\Protector;
use App\Support\Translation\TranslationEngine;
use App\Support\Translation\TranslationFailed;
use Illuminate\Console\Command;

class TranslateCatalogueCommand extends Command
{
    private bool $writeFailed = false;

    protected $signature = 'wayfindr:translate-catalogue
        {locale : Target locale, e.g. it}
        {--engine=passthrough : passthrough|murf}
        {--catalogue=* : Limit to named catalogues, e.g. --catalogue=nav}
        {--write : Write the result instead of only reporting it}
        {--retranslate : Replace values that already exist -- overwrites reviewed copy}
        {--score : Measure the result against the policy: rejected terms, register, typography}';

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
        $scorer = new PolicyScorer($glossary);
        $this->writeFailed = false;

        /** @var array<int, PolicyScore> $scores */
        $scores = [];
        $failed = false;

        $catalogues = $this->catalogues();

        if ($catalogues === null) {
            return self::FAILURE;
        }

        foreach ($catalogues as $sourcePath) {
            $name = basename($sourcePath, '.php');
            $targetPath = lang_path($locale.'/'.$name.'.php');

            // One catalogue's engine failure must not cost the seven that would
            // have succeeded after it. A gateway timeout on the largest file
            // took the whole run down the first time this drafted Italian for
            // real, and the catalogues already written were the only reason it
            // was not a total loss.
            try {
                $plan = $translator->plan(
                    Catalogue::read($sourcePath),
                    is_file($targetPath) ? Catalogue::read($targetPath) : null,
                    $locale,
                    (bool) $this->option('retranslate'),
                );
            } catch (TranslationFailed $e) {
                $this->newLine();
                $this->error("{$name}: {$e->getMessage()}");
                $this->line('  skipped -- re-run to pick it up; catalogues already written are left alone');
                $failed = true;

                continue;
            }

            $this->report($plan);

            if ($this->option('score')) {
                $reviewed = is_file($targetPath) ? Catalogue::read($targetPath)->values() : [];
                $scores[] = $scorer->score($plan, $reviewed);
            }

            if ($plan->hasFailures()) {
                $failed = true;
            }

            if ($this->option('write')) {
                $this->write($plan, $targetPath, $name);
            }
        }

        if ($scores !== []) {
            $this->reportScore($scores);
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Nothing written. Re-run with --write once the plan reads right.');
        }

        return ($failed || $this->writeFailed) ? self::FAILURE : self::SUCCESS;
    }

    private function engine(): TranslationEngine
    {
        return match ((string) $this->option('engine')) {
            'passthrough' => new PassthroughEngine,
            'murf' => new MurfEngine,
            default => throw new TranslationFailed("Unknown engine '{$this->option('engine')}'. Known: passthrough, murf."),
        };
    }

    /**
     * Ask the question the run will actually answer.
     *
     * The earlier prompt said `Replace existing translations?` and the writer
     * never replaces an existing catalogue -- it cannot, because regenerating
     * one deletes the comments inside it. So the confirmed destructive act did
     * not happen, which is a worse failure than refusing: the operator believes
     * they authorised an overwrite and no overwrite occurs.
     *
     * What `--retranslate` genuinely changes is the PLAN. Every key is
     * redrafted rather than only the missing ones, and for an existing
     * catalogue that lands beside it as `<name>.redraft.php` to be merged by
     * hand. Worth confirming, because it spends engine credit on strings that
     * already have reviewed copy.
     */
    private function confirmRetranslate(string $locale): bool
    {
        $this->warn("--retranslate redrafts every key in lang/{$locale}, including copy a person has already reviewed.");
        $this->line('An existing catalogue is never overwritten: the redraft is written beside it for you to merge.');

        return $this->confirm('Redraft everything?', false);
    }

    /**
     * The source catalogues this run covers.
     *
     * A name that matches nothing is an error rather than an empty result. The
     * earlier version filtered silently, so `--catalogue=conversation` (missing
     * its `s`) drafted nothing, printed no warning, and exited 0 -- an
     * automated run would report success having produced none of what it was
     * asked for. Same failure as an unchecked write: work not done, reported
     * as done.
     *
     * @return array<int, string>|null null when a requested name matched nothing
     */
    private function catalogues(): ?array
    {
        $paths = glob(lang_path('en/*.php')) ?: [];
        $only = array_values(array_filter((array) $this->option('catalogue')));

        if ($only === []) {
            return $paths;
        }

        $available = array_map(static fn (string $path): string => basename($path, '.php'), $paths);
        $unmatched = array_diff($only, $available);

        if ($unmatched !== []) {
            $this->error('No such catalogue: '.implode(', ', $unmatched));
            $this->line('  available: '.implode(', ', $available));

            return null;
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

    /**
     * @param  array<int, PolicyScore>  $scores
     */
    private function reportScore(array $scores): void
    {
        $this->newLine();
        $this->line('<info>Policy score</info>');

        $scored = 0;
        $drafted = 0;
        $agreed = 0;
        $comparable = 0;
        $hasReviewed = false;

        /** @var array<string, array<int, array{key: string, detail: string}>> $all */
        $all = [];

        foreach ($scores as $score) {
            $scored += $score->scored;
            $drafted += $score->drafted;

            if ($score->agreed !== null) {
                $hasReviewed = true;
                $agreed += $score->agreed;
                $comparable += $score->comparable;
            }

            foreach ($score->violations as $rule => $hits) {
                foreach ($hits as $hit) {
                    $all[$rule][] = ['key' => $score->catalogue.'.'.$hit['key'], 'detail' => $hit['detail']];
                }
            }
        }

        $this->line("  {$scored} strings measured, {$drafted} of them newly drafted");

        // Over what was COMPARABLE, matching `PolicyScore::agreementPercent()`.
        // Dividing by every drafted key reports one matching counterpart beside
        // nine genuinely new strings as `1 of 10 (10%)`, which reads as a bad
        // engine rather than as nine keys the reviewed catalogue never had.
        if ($hasReviewed && $comparable > 0) {
            $this->line(sprintf(
                '  %d of %d comparable strings (%.0f%%) match the reviewed catalogue already',
                $agreed,
                $comparable,
                100 * $agreed / $comparable,
            ));
        }

        if ($all === []) {
            $this->line('  <info>no policy violations</info>');

            return;
        }

        $total = array_sum(array_map('count', $all));
        $this->newLine();
        $this->warn("  {$total} policy violation(s) -- review notes, not failures:");

        foreach ($all as $rule => $hits) {
            $this->line('  <comment>'.$rule.'</comment> x'.count($hits));

            foreach (array_slice($hits, 0, 3) as $hit) {
                $this->line("      {$hit['key']}: {$hit['detail']}");
            }

            if (count($hits) > 3) {
                $this->line('      … and '.(count($hits) - 3).' more');
            }
        }
    }

    private function write(CataloguePlan $plan, string $targetPath, string $name): void
    {
        if ($plan->isEmpty()) {
            $this->line('  nothing to write');

            return;
        }

        $directory = dirname($targetPath);

        // Same class as the unchecked write below: a locale directory that
        // cannot be created must not be followed by a report of success.
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->error("  could not create {$directory}");
            $this->writeFailed = true;

            return;
        }

        // A NEW catalogue is written whole or not at all.
        //
        // When a key fails -- a lost protection token, a duplicated one --
        // `plan()` keeps its successful siblings, and writing them alone
        // creates a catalogue that is missing exactly the keys nobody checked.
        // Laravel then serves those keys as raw strings to an agent. Worse, the
        // file now EXISTS, so a retry takes the branch below and writes a
        // sidecar fragment instead of the catalogue -- leaving it permanently
        // incomplete, one run after the failure that caused it.
        //
        // A fragment beside an existing catalogue is a different case and is
        // still written: it is merged by hand, by someone who has just been
        // shown the failures, and Laravel never reads it.
        if ($plan->hasFailures() && ! is_file($targetPath)) {
            $this->error('  not writing '.basename($targetPath).': '.count($plan->failures).' key(s) failed, and a partial catalogue would be served as if it were whole');
            $this->writeFailed = true;

            return;
        }

        // The rule the class exists for: an existing catalogue is never
        // regenerated. Its in-array comments would go with it, and the loss
        // would look exactly like a successful run.
        if (is_file($targetPath)) {
            // Named for what it holds. Under `--retranslate` nothing is
            // missing -- every key was redrafted -- and calling that file
            // `.missing.php` misdescribes it to the person merging it.
            $suffix = $this->option('retranslate') ? '.redraft.php' : '.missing.php';
            $fragment = dirname($targetPath).'/'.$name.$suffix;

            if (! $this->put($fragment, Catalogue::render(
                Catalogue::nest($plan->translated),
                $this->fragmentHeader($name, $plan),
            ))) {
                return;
            }

            $this->line("  wrote <info>{$fragment}</info> -- merge by hand; the catalogue beside it carries comments a rewrite would delete");

            return;
        }

        if (! $this->put($targetPath, Catalogue::render(
            Catalogue::nest($plan->merged()),
            $this->catalogueHeader($name, $plan),
        ))) {
            return;
        }

        $this->line("  wrote <info>{$targetPath}</info>");
    }

    /**
     * Write, and say so only if it happened.
     *
     * `file_put_contents()` returns false on an unwritable directory, a full
     * disk, or a permissions problem, and the earlier version ignored it --
     * printing `wrote <path>` and exiting 0 while nothing reached the disk. A
     * drafting tool that reports success it did not achieve is worse than one
     * that fails, because the operator goes looking for a file that is not
     * there and concludes the run was the problem.
     */
    protected function put(string $path, string $contents): bool
    {
        // Written to a sibling temporary file and renamed into place, because
        // reporting a failed write is not the same as leaving nothing behind.
        //
        // A direct write that fills the disk or is interrupted leaves a
        // truncated catalogue on disk. The run reports failure -- and the file
        // still exists, so the NEXT run takes the existing-catalogue branch and
        // writes a sidecar fragment instead of repairing it. Laravel may load
        // the malformed file in the meantime, which for a PHP catalogue is a
        // parse error rather than a missing string.
        //
        // `rename()` within a directory is atomic on POSIX, so the target only
        // ever exists complete or not at all -- including when the process is
        // killed mid-write, which no return-value check can catch.
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $expected = strlen($contents);

        // Suppressed deliberately. A short write also raises a PHP warning,
        // which an error handler turns into an exception and which says less
        // than the check below -- this reports the path and both byte counts,
        // and returns a failure the caller already knows how to propagate.
        // Suppressing it makes this method the single place the failure is
        // reported, and the only place it has to be tested.
        $written = @file_put_contents($temporary, $contents);

        // Both conditions, and the honest note is which one fires.
        //
        // The review finding said a disk filling mid-write returns a positive
        // count shorter than the contents. On PHP 8.5 it does not: a stream
        // that stalls part-way and one that never completes both make
        // `file_put_contents()` return `false`, verified with a stream wrapper.
        // So `=== false` is the branch that actually catches a truncated write
        // here, and the length comparison is defence for a platform or version
        // where the documented short-count return does occur. It costs one
        // comparison and rules out a class of silent truncation, so it stays --
        // but nobody should read it as the guard doing the work today.
        if ($written === false || $written !== $expected) {
            @unlink($temporary);

            $this->error($written === false
                ? "  could not write {$path}"
                : "  short write to {$path}: {$written} of {$expected} bytes");
            $this->writeFailed = true;

            return false;
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            $this->error("  could not move the finished file into {$path}");
            $this->writeFailed = true;

            return false;
        }

        return true;
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
