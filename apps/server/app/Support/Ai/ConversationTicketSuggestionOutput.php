<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class ConversationTicketSuggestionOutput
{
    public function __construct(
        public string $title,
        public string $priority,
    ) {}
}
