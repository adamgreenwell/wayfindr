<?php

namespace App\Rules;

use App\Support\DatabaseKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A pagination cursor the paginator can actually use.
 *
 * Laravel treats an undecodable cursor as NO cursor, which for a walk through a
 * support inbox is the worst failure available: an integration holding a
 * truncated cursor silently receives page one again, so it reprocesses rows it
 * has already seen, or loops, with a 200 every time.
 *
 * Everything here validates the DECODED PAYLOAD rather than a `Cursor` object,
 * and that is the lesson of five review rounds on this one rule. Each earlier
 * version checked one layer further in and was still wrong at the next:
 *
 * 1. it decodes -- but `Cursor::fromEncoded()` accepts any JSON object;
 * 2. it has the ordering keys -- but they may hold arrays;
 * 3. they hold scalars -- but a scalar may be `null`;
 * 4. they are non-null scalars -- but `"not-a-timestamp"` is a fine string that
 *    PostgreSQL refuses to compare with a timestamp column;
 * 5. and every one of those ran on a `Cursor` object, which a payload missing
 *    its direction marker never becomes -- `fromEncoded()` returns null, the
 *    paginator reads null as NO cursor, and the walk restarts at page one with
 *    a 200. The exact failure this rule exists to prevent, reached by the one
 *    route that skipped the rule entirely.
 *
 * Each check was necessary and none was sufficient, which is what validating
 * encoded JSON from a client looks like. So the payload is parsed here, and
 * nothing about it is assumed.
 */
class DecodableCursor implements ValidationRule
{
    /** The format the paginator emits for a timestamp column. */
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param  array<string, string>  $orderedBy  Column => `key` or `timestamp`.
     */
    public function __construct(
        private readonly array $orderedBy = ['created_at' => 'timestamp', 'id' => 'key'],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $payload = self::decode($value);

        if ($payload === null) {
            $fail('The :attribute is not a valid pagination cursor.');

            return;
        }

        // The direction marker, checked here because `fromEncoded()` answers
        // null for a payload without one -- indistinguishable, downstream, from
        // a request that sent no cursor at all. A non-bool marker is worse than
        // it looks: it is carried through to `pointsToNextItems()` unchecked,
        // so `null` or `"yes"` silently decides which way the page walks.
        if (! array_key_exists('_pointsToNextItems', $payload) || ! is_bool($payload['_pointsToNextItems'])) {
            $fail('The :attribute is not a valid pagination cursor.');

            return;
        }

        foreach ($this->orderedBy as $parameter => $kind) {
            if (! array_key_exists($parameter, $payload) || ! self::isUsable($payload[$parameter], $kind)) {
                $fail('The :attribute is not a valid pagination cursor.');

                return;
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $value): ?array
    {
        $json = base64_decode(strtr($value, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Whether a carried value is one the database can be asked to compare.
     */
    private static function isUsable(mixed $value, string $kind): bool
    {
        // Booleans first, and explicitly. `(string) true` is `"1"`, which looks
        // exactly like a valid key -- so a cursor carrying `true` would page
        // from a position the client never supplied rather than being refused.
        if (is_bool($value) || $value === null || is_array($value) || is_object($value)) {
            return false;
        }

        if ($kind === 'key') {
            return is_int($value)
                ? DatabaseKey::isValid((string) $value)
                : is_string($value) && DatabaseKey::isValid($value);
        }

        // The exact shape the paginator emits, not "something Carbon can
        // parse". Carbon happily reads `first day of next month` and `@123`,
        // and the ORIGINAL string is what gets bound to the query -- where
        // PostgreSQL rejects it as timestamp input.
        return is_string($value) && preg_match(self::TIMESTAMP, $value) === 1;
    }
}
