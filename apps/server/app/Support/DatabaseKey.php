<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether a raw route value could be a primary key at all.
 *
 * Range as well as shape. A value constrained only to `[0-9]+` still admits a
 * thirty-digit number, and PostgreSQL raises casting that to a bigint -- so a
 * malformed URL becomes a 500 where the endpoint meant to answer 404. Casting
 * to int in PHP is no help: the overflow this exists to catch happens during
 * the cast. Route segments are now bounded before they arrive (see
 * ROUTE_PATTERN); this remains the check for raw values that are not, and the
 * second line for the ones that are.
 *
 * Lives here, out of the controller that needed it, because the failure it
 * prevents cannot be reproduced on SQLite -- the suite runs SQLite and every
 * documented install runs PostgreSQL, so the only way to prove this correct is
 * to test the predicate directly.
 */
final class DatabaseKey
{
    /**
     * The route constraint for every parameter that names an integer key.
     *
     * Applied globally, by parameter name, in AppServiceProvider. Bounded as
     * well as numeric, because implicit model binding hands the segment to the
     * database with no range check of its own: `[0-9]+` let a twenty-digit id
     * through to a bigint comparison, and an `int` controller parameter threw a
     * TypeError on it -- on either engine.
     *
     * Eighteen digits, not nineteen: the largest bigint has nineteen and not
     * every nineteen-digit number fits, while every run of eighteen fits both a
     * PostgreSQL bigint and a PHP int. No sequence reaches 10^18, so no real id
     * is refused. Everything this admits also passes isValid().
     */
    public const ROUTE_PATTERN = '[0-9]{1,18}';

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
