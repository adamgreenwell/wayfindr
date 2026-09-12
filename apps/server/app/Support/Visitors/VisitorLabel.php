<?php

declare(strict_types=1);

namespace App\Support\Visitors;

/**
 * What a visitor is CALLED, as opposed to how they are looked up.
 *
 * Four surfaces answered this differently -- the conversation page, the visitor
 * profile, the ticket page and the visitors list -- and unifying them by copying
 * the same `?:` chain into each controller reproduced one bug four times: `?:`
 * is falsy-tested, so a visitor whose name or identifier is the literal string
 * "0" was skipped and announced as unknown. Widget bootstrap validates
 * `anonymous_id` as `required|string|max:255`, so "0" is a value this product
 * accepts and then could not name.
 *
 * The candidate ORDER stays at the call site, because it is per-surface
 * knowledge, and so does the fallback sentence, because each surface says it in
 * its own words. What lives here is the part that must never vary: first filled
 * wins, and whether the result is the visitor's own string or ours.
 */
final class VisitorLabel
{
    /**
     * @param  list<string|null>  $candidates  in the order the surface prefers them
     * @param  string  $fallback  what to say when the visitor has no identifier at all
     * @return array{label: string, is_theirs: bool}
     */
    public static function fromCandidates(array $candidates, string $fallback): array
    {
        foreach ($candidates as $candidate) {
            // filled(), never a truthiness test: blank() treats only null, '',
            // an empty array and whitespace-only strings as absent, so "0"
            // survives here and did not survive `?:`.
            if (filled($candidate)) {
                return ['label' => (string) $candidate, 'is_theirs' => true];
            }
        }

        // `is_theirs` is returned WITH the label rather than derived again by
        // the caller. Marking our own fallback with `lang=""` announces English
        // copy as an unknown language, and two expressions that must agree
        // eventually will not -- which is why every caller previously carried a
        // comment promising to keep them in step.
        return ['label' => $fallback, 'is_theirs' => false];
    }
}
