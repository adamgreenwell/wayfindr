<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Account;
use App\Models\TicketLabel;
use Illuminate\Support\Str;
use JsonException;

/** Match existing account labels locally; no label catalogue leaves the install. */
final class TicketLabelSuggestionMatcher
{
    private const MAX_SUGGESTIONS = 5;

    /** @return list<int> */
    public function match(Account $account, string $boundedConversationContext): array
    {
        try {
            $context = json_decode($boundedConversationContext, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($context)) {
            return [];
        }

        $text = [is_string($context['subject'] ?? null) ? $context['subject'] : ''];

        foreach (is_array($context['messages'] ?? null) ? $context['messages'] : [] as $message) {
            if (is_array($message) && is_string($message['body'] ?? null)) {
                $text[] = $message['body'];
            }
        }

        $haystack = $this->normalize(implode(' ', $text));

        if ($haystack === '') {
            return [];
        }

        $matches = [];

        /** @var TicketLabel $label */
        foreach ($account->ticketLabels()->select(['id', 'name', 'slug'])->lazyById(100) as $label) {
            $candidates = array_unique([
                $this->normalize($label->name),
                $this->normalize($label->slug),
            ]);

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && str_contains(' '.$haystack.' ', ' '.$candidate.' ')) {
                    $matches[] = (int) $label->id;

                    break;
                }
            }

            if (count($matches) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $matches;
    }

    private function normalize(string $value): string
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower($value));

        return trim((string) preg_replace('/\s+/u', ' ', $normalized ?? ''));
    }
}
