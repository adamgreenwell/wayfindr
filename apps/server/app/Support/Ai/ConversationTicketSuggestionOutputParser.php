<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\TicketPriority;
use JsonException;

/** Reject malformed provider output instead of guessing at support-record fields. */
final class ConversationTicketSuggestionOutputParser
{
    public function parse(string $output): ?ConversationTicketSuggestionOutput
    {
        try {
            $decoded = json_decode(trim($output), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        $keys = array_keys($decoded);
        sort($keys);

        if ($keys !== ['priority', 'title'] || ! is_string($decoded['title']) || ! is_string($decoded['priority'])) {
            return null;
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', $decoded['title']));
        $priority = TicketPriority::tryFrom($decoded['priority']);

        if ($title === '' || mb_strlen($title) > 120 || $priority === null) {
            return null;
        }

        return new ConversationTicketSuggestionOutput($title, $priority->value);
    }
}
