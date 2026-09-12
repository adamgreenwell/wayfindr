<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;

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
 * The order was left at the call site on the theory that it is per-surface
 * knowledge. It is not: all seven surfaces want the same order, and what they
 * were really rebuilding by hand was a four-item list plus the decision to
 * redact the host's identifier. Three remembered the redaction and three did
 * not, so a host that stores an email address in `external_id` had it shown on
 * the queue, the visitor list and the merge list while the detail pages hid it.
 *
 * So `forVisitor()` owns the order AND the redaction, and the only thing a
 * surface still supplies is the sentence to say when there is nothing to say,
 * because each says that in its own words.
 */
final class VisitorLabel
{
    /**
     * The four ways to name a visitor, in the one order every surface wants.
     *
     * `external_id` is the only one the HOST wrote rather than the visitor or
     * us, so it is the only one that can carry something a support desk should
     * not display. It is redacted here rather than at six call sites.
     *
     * @param  string  $fallback  what to say when the visitor has no identifier
     *                            at all; pass an empty string where the reader's
     *                            language is not known yet (a broadcast payload)
     *                            and let the surface supply its own sentence.
     * @return array{label: string, is_theirs: bool}
     */
    public static function forVisitor(?Visitor $visitor, string $fallback): array
    {
        return self::fromCandidates([
            $visitor?->name,
            $visitor?->email,
            (new VisitorContextSanitizer)->sanitizeIdentifier($visitor?->external_id),
            $visitor?->anonymous_id,
        ], $fallback);
    }

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
