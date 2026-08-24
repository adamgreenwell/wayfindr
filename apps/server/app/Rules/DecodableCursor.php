<?php

namespace App\Rules;

use App\Support\DatabaseKey;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Pagination\Cursor;
use Throwable;
use UnexpectedValueException;

/**
 * A pagination cursor that decodes AND carries what the query orders by.
 *
 * Laravel treats an undecodable cursor as no cursor, which for a walk through a
 * support inbox is the worst failure available: an integration holding a
 * truncated cursor silently receives page one again, so it reprocesses rows it
 * has already seen, or loops forever, with a 200 every time.
 *
 * Decoding alone is not enough. `Cursor::fromEncoded()` happily returns a
 * cursor for any decodable JSON object, so a crafted `{"_pointsToNextItems":
 * true}` passes that check and then fails inside `cursorPaginate()` when it
 * reaches for an ordering column that is not there -- turning a malformed
 * parameter into a 500 where the contract promises a 422.
 *
 * So the rule is told which parameters the endpoint orders by, and checks the
 * cursor actually carries them.
 */
class DecodableCursor implements ValidationRule
{
    /**
     * @param  array<string, string>  $orderedBy  Column => expected kind, one of
     *                                            `key` or `timestamp`.
     */
    public function __construct(
        private readonly array $orderedBy = ['created_at' => 'timestamp', 'id' => 'key'],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $cursor = Cursor::fromEncoded($value);

        if ($cursor === null) {
            $fail('The :attribute is not a valid pagination cursor.');

            return;
        }

        foreach ($this->orderedBy as $parameter => $kind) {
            try {
                $carried = $cursor->parameter($parameter);
            } catch (UnexpectedValueException) {
                $fail('The :attribute is not a valid pagination cursor.');

                return;
            }

            if (! self::isUsable($carried, $kind)) {
                $fail('The :attribute is not a valid pagination cursor.');

                return;
            }
        }
    }

    /**
     * Whether a carried value can be compared against the column it orders by.
     *
     * Scalar is not enough, which is where the previous version stopped. On
     * PostgreSQL a cursor carrying `"not-a-timestamp"` is a perfectly good
     * string that the database refuses to compare with a timestamp column --
     * another 500 where the contract documents a 422, and again the reading is
     * "the server broke" rather than "your request was wrong". SQLite compares
     * anything to anything, so none of this surfaces in the suite by accident.
     */
    private static function isUsable(mixed $value, string $kind): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        if ($kind === 'key') {
            return DatabaseKey::isValid((string) $value);
        }

        try {
            CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
