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
     * Exactly 0..PHP_INT_MAX (which is also PostgreSQL's largest bigint), after
     * any leading zeroes -- which isValid() strips and PostgreSQL casts away:
     * any run of up to eighteen digits, or nineteen digits no greater than
     * 9223372036854775807. A plain eighteen-digit cap refused real ids an
     * import or an advanced sequence can hold, which isValid() accepts; this
     * admits the same numbers isValid() does, written out as a regex because a
     * route requirement cannot call code. Built from the bound digit by digit:
     * for each position, the bound's prefix, then any smaller digit, then any
     * digits for the rest.
     */
    public const ROUTE_PATTERN = '0*(?:[0-9]{1,18}'
        .'|[0-8][0-9]{18}'
        .'|9[0-1][0-9]{17}'
        .'|92[0-1][0-9]{16}'
        .'|922[0-2][0-9]{15}'
        .'|9223[0-2][0-9]{14}'
        .'|92233[0-6][0-9]{13}'
        .'|922337[0-1][0-9]{12}'
        .'|92233720[0-2][0-9]{10}'
        .'|922337203[0-5][0-9]{9}'
        .'|9223372036[0-7][0-9]{8}'
        .'|92233720368[0-4][0-9]{7}'
        .'|922337203685[0-3][0-9]{6}'
        .'|9223372036854[0-6][0-9]{5}'
        .'|92233720368547[0-6][0-9]{4}'
        .'|922337203685477[0-4][0-9]{3}'
        .'|9223372036854775[0-7][0-9]{2}'
        .'|922337203685477580[0-6]'
        .'|9223372036854775807)';

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
