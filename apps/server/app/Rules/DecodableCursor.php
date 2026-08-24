<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Pagination\Cursor;

/**
 * A pagination cursor that actually decodes.
 *
 * Laravel treats an undecodable cursor as no cursor, which for a walk through
 * a support inbox is the worst possible failure: an integration holding a
 * truncated or corrupted cursor silently receives page one again, so it
 * reprocesses rows it has already seen, or loops forever, with a 200 every
 * time and nothing to notice.
 *
 * The contract says a malformed pagination parameter is a 422 (ADR 0018), so
 * this makes that true rather than aspirational.
 */
class DecodableCursor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (Cursor::fromEncoded($value) === null) {
            $fail('The :attribute is not a valid pagination cursor.');
        }
    }
}
