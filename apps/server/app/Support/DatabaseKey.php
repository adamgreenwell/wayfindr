<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a raw route value could be a primary key at all.
 *
 * Range as well as shape. A route constrained to `[0-9]+` still admits a
 * thirty-digit number, and PostgreSQL raises casting that to a bigint -- so a
 * malformed URL becomes a 500 where the endpoint meant to answer 404. Casting
 * to int in PHP is no help: the overflow this exists to catch happens during
 * the cast.
 *
 * Lives here, out of the controller that needed it, because the failure it
 * prevents cannot be reproduced on SQLite -- the suite runs SQLite and every
 * documented install runs PostgreSQL, so the only way to prove this correct is
 * to test the predicate directly.
 */
final class DatabaseKey
{
    public static function isValid(string $value): bool
    {
        if ($value === '' || ! ctype_digit($value)) {
            return false;
        }

        // Compared as digit strings rather than with bcmath, which is not a
        // declared dependency and is used nowhere else in this codebase --
        // reaching for it would make a self-hosted install fail on a missing
        // extension for the sake of one bounds check.
        $trimmed = ltrim($value, '0');
        $trimmed = $trimmed === '' ? '0' : $trimmed;
        $max = (string) PHP_INT_MAX;

        return strlen($trimmed) < strlen($max)
            || (strlen($trimmed) === strlen($max) && strcmp($trimmed, $max) <= 0);
    }
}
