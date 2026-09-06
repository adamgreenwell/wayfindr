<?php

declare(strict_types=1);

namespace App\Support\Ai;

use JsonException;
use stdClass;

/** Validate a small set of provider-suggested local article search phrases. */
final class ConversationKnowledgeSuggestionOutputParser
{
    private const MAX_OUTPUT_CHARACTERS = 8_000;

    public function parse(string $output): ?ConversationKnowledgeSuggestionOutput
    {
        if (mb_strlen($output) > self::MAX_OUTPUT_CHARACTERS) {
            return null;
        }

        try {
            $decoded = json_decode(trim($output), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $decoded instanceof stdClass || array_keys(get_object_vars($decoded)) !== ['queries']) {
            return null;
        }

        $rawQueries = $decoded->queries;

        if (! is_array($rawQueries) || ! array_is_list($rawQueries) || count($rawQueries) < 1 || count($rawQueries) > 5) {
            return null;
        }

        $queries = [];
        $seen = [];

        foreach ($rawQueries as $rawQuery) {
            if (! is_string($rawQuery)) {
                return null;
            }

            $query = trim((string) preg_replace('/\s+/u', ' ', $rawQuery));
            $key = mb_strtolower($query);

            if (mb_strlen($query) < 3 || mb_strlen($query) > 80) {
                return null;
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $queries[] = $query;
        }

        return $queries === [] ? null : new ConversationKnowledgeSuggestionOutput($queries);
    }
}
