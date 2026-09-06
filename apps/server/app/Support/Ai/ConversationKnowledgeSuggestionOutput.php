<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class ConversationKnowledgeSuggestionOutput
{
    /** @param list<string> $queries */
    public function __construct(public array $queries) {}
}
