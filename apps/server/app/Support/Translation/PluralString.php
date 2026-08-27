<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * Laravel's `trans_choice` strings, which are several sentences in a trench
 * coat.
 *
 * `'{1} 1 ticket matches|[2,*] :count tickets match'` is one catalogue value and
 * two pieces of copy. Sent whole to a translation engine it comes back with the
 * pipe treated as punctuation and the range syntax treated as prose, which is
 * how thirty-three strings become thirty-three defects at once.
 *
 * What this class deliberately does NOT do is decide how many segments the
 * target needs. German and Italian take two, Polish takes three, and no
 * per-segment translation can add a segment it was not given. So the source
 * shape is preserved and the string is reported for review instead -- the
 * pipeline's job is a reviewable draft, not a plural rule it is not qualified
 * to invent.
 */
final class PluralString
{
    public static function isPlural(string $value): bool
    {
        return str_contains($value, '|');
    }

    /**
     * @return array<int, string>
     */
    public static function segments(string $value): array
    {
        return explode('|', $value);
    }

    /**
     * @param  array<int, string>  $segments
     */
    public static function join(array $segments): string
    {
        return implode('|', $segments);
    }
}
