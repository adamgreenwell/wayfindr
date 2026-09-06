<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class ConversationSummaryContext
{
    public function __construct(
        public AgentCopilotPrompt $prompt,
        public int $messageCount,
        public int $lastMessageId,
    ) {}
}
