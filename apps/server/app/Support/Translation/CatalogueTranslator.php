<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * Turns one English catalogue into a proposed catalogue in another language.
 *
 * The order of operations is the whole design:
 *
 * 1. Anything already translated is left alone. Reviewed copy is the most
 *    valuable thing in the directory and a pipeline that can overwrite it is a
 *    pipeline that can undo a week of review in one flag.
 * 2. Cognates and fully-protected strings never reach the engine. `Cobrowse`
 *    does not need translating and `:count` is not a word; sending either is a
 *    charge for a round trip that can only introduce error.
 * 3. Plural strings are split into segments and each segment travels alone,
 *    because a pipe sent whole comes back as punctuation.
 * 4. Everything else is masked, batched into ONE engine call, and restored
 *    strictly -- a lost token fails that key rather than shipping a hole.
 * 5. Plural strings are flagged for review regardless of how well they came
 *    back, because segment COUNT is a language decision this cannot make.
 */
final class CatalogueTranslator
{
    public function __construct(
        private readonly TranslationEngine $engine,
        private readonly Glossary $glossary,
        private readonly Protector $protector,
    ) {}

    public function plan(
        Catalogue $source,
        ?Catalogue $target,
        string $targetLocale,
        bool $retranslate = false,
    ): CataloguePlan {
        $existing = $target?->values() ?? [];
        $cognates = array_flip($this->glossary->cognates($targetLocale));

        $translated = [];
        $carried = [];
        $failures = [];
        $review = [];

        /** @var array<int, array{key: string, segment: int, masked: MaskedText}> $units */
        $units = [];

        foreach ($source->values() as $key => $value) {
            if (! $retranslate && array_key_exists($key, $existing)) {
                $carried[$key] = $existing[$key];

                continue;
            }

            // A cognate reaches here only when the key is ABSENT from the
            // target -- the branch above would have caught it otherwise. So it
            // is new OUTPUT that happens not to need an engine, and calling it
            // `carried` was a lie with consequences: `write()` emits only
            // `translated` for an existing catalogue, so the key vanished from
            // the fragment and an incremental run quietly lost key parity.
            //
            // Routed through the unit list like any other value, so it is
            // masked, restored and ordered on exactly the same path.
            if (isset($cognates[$value])) {
                $units[] = [
                    'key' => $key,
                    'segment' => 0,
                    'masked' => $this->protector->mask($value),
                    'skip' => true,
                ];

                continue;
            }

            foreach (PluralString::segments($value) as $index => $segment) {
                $masked = $this->protector->mask($segment);

                if ($masked->isFullyMasked()) {
                    $units[] = ['key' => $key, 'segment' => $index, 'masked' => $masked, 'skip' => true];

                    continue;
                }

                $units[] = ['key' => $key, 'segment' => $index, 'masked' => $masked, 'skip' => false];
            }

            if (PluralString::isPlural($value)) {
                $review[$key] = 'plural: segment count is a target-language decision, not this run\'s';
            }
        }

        $outbound = [];

        foreach ($units as $index => $unit) {
            if ($unit['skip'] === false) {
                $outbound[$index] = $unit['masked']->text;
            }
        }

        $returned = $outbound === []
            ? []
            : array_combine(
                array_keys($outbound),
                $this->engine->translate(array_values($outbound), $this->brief($source, $targetLocale)),
            );

        /** @var array<string, array<int, string>> $assembled */
        $assembled = [];

        foreach ($units as $index => $unit) {
            $key = $unit['key'];

            if (isset($failures[$key])) {
                continue;
            }

            if ($unit['skip']) {
                // Nothing was sent, so nothing came back -- but the text still
                // holds tokens and must be unmasked exactly like a translated
                // one. Writing `$masked->text` straight through here put `WFZ0`
                // into the catalogue where `:count` belonged.
                $assembled[$key][$unit['segment']] = $this->protector->restore(
                    $unit['masked']->text,
                    $unit['masked'],
                    $key,
                );

                continue;
            }

            try {
                $assembled[$key][$unit['segment']] = $this->protector->restore(
                    $returned[$index] ?? '',
                    $unit['masked'],
                    $key,
                );
            } catch (TranslationFailed $e) {
                $failures[$key] = $e->getMessage();
                unset($assembled[$key]);
            }
        }

        foreach ($assembled as $key => $segments) {
            ksort($segments);
            $translated[$key] = PluralString::join(array_values($segments));
        }

        // Restore the source's key order: assembly walks units, and a failure
        // in the middle would otherwise reorder what survived.
        $ordered = [];

        foreach (array_keys($source->values()) as $key) {
            if (array_key_exists($key, $translated)) {
                $ordered[$key] = $translated[$key];
            }
        }

        return new CataloguePlan(
            catalogue: $source->name,
            targetLocale: $targetLocale,
            translated: $ordered,
            carried: $carried,
            failures: $failures,
            review: $review,
            order: array_keys($source->values()),
        );
    }

    private function brief(Catalogue $source, string $targetLocale): EngineBrief
    {
        return new EngineBrief(
            sourceLocale: 'en',
            targetLocale: $targetLocale,
            catalogue: $source->name,
            docblock: $source->docblock,
            terms: $this->glossary->terms($targetLocale),
            neverTranslate: $this->glossary->neverTranslate(),
            register: $this->glossary->register($targetLocale),
        );
    }
}
