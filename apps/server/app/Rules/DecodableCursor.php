<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Pagination\Cursor;
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
     * @param  list<string>  $orderedBy  The columns the paginated query sorts on.
     */
    public function __construct(private readonly array $orderedBy = ['created_at', 'id']) {}

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

        foreach ($this->orderedBy as $parameter) {
            try {
                $value = $cursor->parameter($parameter);
            } catch (UnexpectedValueException) {
                $fail('The :attribute is not a valid pagination cursor.');

                return;
            }

            // Present is not the same as usable. `parameter()` only checks the
            // key exists, so a cursor carrying an array or object under
            // `created_at` passes and then breaks when the paginator binds it
            // to the query -- a 500 where the contract promises a 422, which
            // reads as the server failing rather than the request being wrong.
            // Null is not exempt. A cursor carrying `{"created_at":null}`
            // decodes, has the key, and then asks the database to order
            // against nothing -- which is the same 500 by a shorter route.
            if (! is_scalar($value)) {
                $fail('The :attribute is not a valid pagination cursor.');

                return;
            }
        }
    }
}
