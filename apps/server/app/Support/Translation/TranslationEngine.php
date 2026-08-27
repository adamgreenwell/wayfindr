<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * One way of turning masked source strings into masked target strings.
 *
 * Implementations receive text that has already had its placeholders, plural
 * ranges and never-translate literals swapped for tokens, and are expected to
 * return the tokens untouched. They are NOT expected to honour the brief -- an
 * engine with no context slot is a legitimate implementation, and the caller
 * checks the result either way.
 */
interface TranslationEngine
{
    public function name(): string;

    /**
     * Whether this engine can use the brief, which decides nothing here and is
     * reported to the operator so a run's quality is not a mystery.
     */
    public function usesBrief(): bool;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string> in the same order, one per input
     */
    public function translate(array $texts, EngineBrief $brief): array;
}
