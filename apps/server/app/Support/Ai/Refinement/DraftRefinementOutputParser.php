<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use App\Support\Ai\Grounding\ConversationGroundingSelection;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;
use stdClass;

/** Strict shape and known-source membership; semantic preservation needs separate validation. */
final class DraftRefinementOutputParser
{
    /** 5,000 characters at 12 bytes per escaped surrogate pair, plus keys and citations. */
    private const MAX_BYTES = 65536;

    public function parse(#[SensitiveParameter] string $output, #[SensitiveParameter] array $suppliedSources): ?DraftRefinementProposal
    {
        if (strlen($output) > self::MAX_BYTES) {
            return null;
        }
        try {
            $sources = new ConversationGroundingSelection($suppliedSources === [] ? 'no_sources' : 'selected', $suppliedSources, []);
            if ($sources->encodedCharacters() > 3000) {
                return null;
            }
            $decoded = json_decode($output, false, 8, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass || $this->duplicateKeys($output)) {
                return null;
            }
        } catch (InvalidArgumentException|JsonException) {
            return null;
        }
        $keys = array_keys(get_object_vars($decoded));
        sort($keys);
        if ($keys !== ['candidate', 'citations', 'limitation'] || ! $this->text($decoded->candidate, DraftRefinementInput::MAX_CHARACTERS)
            || ! is_array($decoded->citations) || ! array_is_list($decoded->citations) || count($decoded->citations) > 3
            || ($decoded->limitation !== null && ! $this->text($decoded->limitation, 1000))) {
            return null;
        }
        $known = $sources->references();
        $seen = [];
        foreach ($decoded->citations as $reference) {
            if (! is_string($reference) || ! in_array($reference, $known, true) || isset($seen[$reference])) {
                return null;
            }
            $seen[$reference] = true;
        }

        return new DraftRefinementProposal($decoded->candidate, $decoded->citations, $decoded->limitation);
    }

    private function text(mixed $value, int $maximum): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && preg_match('/[^\s\p{Z}]/u', $value) === 1 && mb_strlen($value) <= $maximum
            && preg_match('/(?![\t\r\n])\p{Cc}/u', $value) !== 1;
    }

    /** json_decode otherwise accepts a repeated root key, including escaped spellings. */
    private function duplicateKeys(string $json): bool
    {
        $depth = 0;
        $expectsKey = false;
        $seen = [];
        $length = strlen($json);
        for ($index = 0; $index < $length; $index++) {
            $character = $json[$index];
            if ($character === '"') {
                $end = $index + 1;
                while ($end < $length && $json[$end] !== '"') {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                if ($depth === 1 && $expectsKey) {
                    $key = json_decode(substr($json, $index, $end - $index + 1), true, 2, JSON_THROW_ON_ERROR);
                    if (isset($seen[$key])) {
                        return true;
                    }
                    $seen[$key] = true;
                    $expectsKey = false;
                }
                $index = $end;
            } elseif ($character === '{' || $character === '[') {
                $depth++;
                if ($depth === 1) {
                    $expectsKey = true;
                }
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            } elseif ($character === ',' && $depth === 1) {
                $expectsKey = true;
            }
        }

        return false;
    }
}
