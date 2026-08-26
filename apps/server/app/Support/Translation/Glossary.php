<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * The translation policy, in the form a program can act on.
 *
 * Reads `resources/translation/glossary.php`. The prose half lives in
 * `docs/product/translation-policy.md` and is not duplicated here -- a rule that
 * cannot be expressed as data stays there, and this class does not pretend to
 * enforce it.
 *
 * The term table is the reason this file exists. Forty-odd decisions govern
 * eight hundred strings, and an engine handed the table produces consistent
 * vocabulary where an engine handed eight hundred strings one at a time does
 * not. Consistency is the thing machine translation is worst at and the thing a
 * reviewer notices first.
 */
final class Glossary
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    public static function load(?string $path = null): self
    {
        $path ??= resource_path('translation/glossary.php');

        if (! is_file($path)) {
            throw new TranslationFailed("No glossary at {$path}.");
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new TranslationFailed("The glossary at {$path} did not return an array.");
        }

        return new self($data);
    }

    /**
     * Regexes whose matches are masked before a string is sent anywhere.
     *
     * @return array<string, string>
     */
    public function protectPatterns(): array
    {
        return $this->data['protect'] ?? [];
    }

    /**
     * @return array<int, string>
     */
    public function neverTranslate(): array
    {
        return $this->data['never_translate'] ?? [];
    }

    /**
     * Values this language leaves in English on purpose.
     *
     * @return array<int, string>
     */
    public function cognates(string $locale): array
    {
        return $this->data['cognates'][$locale] ?? [];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public function collisions(): array
    {
        return $this->data['collisions'] ?? [];
    }

    /**
     * Every language this glossary decides a vocabulary for.
     *
     * Exists so a test can ask rather than hardcode. A literal locale list in a
     * test asserts that a term table exists, which is a claim about a FILE and
     * not about the code -- and it goes red the moment the table moves to
     * another branch while the assertion stays behind.
     *
     * @return array<int, string>
     */
    public function localesWithTerms(): array
    {
        return array_keys($this->data['terms'] ?? []);
    }

    public function hasTermsFor(string $locale): bool
    {
        return isset($this->data['terms'][$locale]);
    }

    /**
     * The full entries, notes included.
     *
     * @return array<string, array{term: string, note?: string, confirm?: bool}>
     */
    public function terms(string $locale): array
    {
        return $this->data['terms'][$locale] ?? [];
    }

    /**
     * Entries still waiting on a human, which a run reports rather than blocks
     * on -- an unconfirmed term is a review note, not a broken pipeline.
     *
     * @return array<string, string>
     */
    public function unconfirmed(string $locale): array
    {
        $out = [];

        foreach ($this->terms($locale) as $key => $entry) {
            if ($entry['confirm'] ?? false) {
                $out[$key] = $entry['term'];
            }
        }

        return $out;
    }

    /**
     * Terms a draft must not contain => what was decided instead.
     *
     * @return array<string, string>
     */
    public function rejected(string $locale): array
    {
        return $this->data['rejected'][$locale] ?? [];
    }

    /**
     * Named regexes a draft is measured against.
     *
     * @return array<string, string>
     */
    public function checks(string $locale): array
    {
        return $this->data['checks'][$locale] ?? [];
    }

    /**
     * How this language is addressed, as instructions rather than as prose.
     *
     * @return array<string, string>
     */
    public function register(string $locale): array
    {
        return $this->data['register'][$locale] ?? [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function typography(string $locale): array
    {
        return $this->data['typography'][$locale] ?? [];
    }

    /**
     * Pairs a collision names, resolved to their terms.
     *
     * Returns the offending pair when two sides share a word, so a caller can
     * refuse to run rather than produce a catalogue that merges them.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public function mergedCollisions(string $locale): array
    {
        $terms = $this->terms($locale);
        $merged = [];

        foreach ($this->collisions() as [$a, $b]) {
            $left = $terms[$a]['term'] ?? null;
            $right = $terms[$b]['term'] ?? null;

            if ($left !== null && $left === $right) {
                $merged[] = [$a, $b, $left];
            }
        }

        return $merged;
    }
}
