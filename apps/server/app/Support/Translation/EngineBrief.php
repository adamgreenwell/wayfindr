<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * Everything known about how this catalogue should be translated.
 *
 * The `docblock` is the point of the class. `lang/en/conversations.php` opens by
 * explaining that its counts go through `trans_choice` verb included, and that
 * its sentences carry placeholders rather than being concatenated from
 * fragments -- written for whoever translates it next. That is a better brief
 * than most translators are given, and it is sitting in the file already.
 *
 * An engine is free to ignore all of it. `Murf` does, because its request body
 * is `{targetLanguage, texts}` and has nowhere to put context. That is not a
 * defect in this class; it is the difference between the engines, and the reason
 * the seam exists.
 */
final class EngineBrief
{
    /**
     * @param  array<string, array{term: string, note?: string, confirm?: bool}>  $terms
     * @param  array<int, string>  $neverTranslate
     * @param  array<string, string>  $register
     */
    public function __construct(
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly string $catalogue,
        public readonly string $docblock,
        public readonly array $terms,
        public readonly array $neverTranslate,
        public readonly array $register,
    ) {}

    /**
     * The brief as instructions, for an engine that can read them.
     */
    public function asInstructions(): string
    {
        $lines = [
            "Translate UI strings for a customer-support dashboard from {$this->sourceLocale} to {$this->targetLocale}.",
            '',
            'REGISTER',
        ];

        foreach ($this->register as $rule => $value) {
            $lines[] = "- {$rule}: {$value}";
        }

        $lines[] = '';
        $lines[] = 'GLOSSARY -- these terms are decided; use them exactly.';

        foreach ($this->terms as $key => $entry) {
            $line = "- {$key}: {$entry['term']}";

            if (($entry['note'] ?? '') !== '') {
                $line .= "  ({$entry['note']})";
            }

            $lines[] = $line;
        }

        if ($this->neverTranslate !== []) {
            $lines[] = '';
            $lines[] = 'NEVER TRANSLATE: '.implode(', ', $this->neverTranslate);
        }

        $lines[] = '';
        $lines[] = 'Tokens matching WFZ<number> are protected values. Reproduce each one';
        $lines[] = 'exactly once, unchanged, in the position the target grammar needs.';

        if (trim($this->docblock) !== '') {
            $lines[] = '';
            $lines[] = "NOTES FROM THE CATALOGUE ({$this->catalogue}) -- written for whoever";
            $lines[] = 'translates it, and authoritative where it conflicts with the above:';
            $lines[] = '';
            $lines[] = $this->docblock;
        }

        return implode("\n", $lines);
    }
}
