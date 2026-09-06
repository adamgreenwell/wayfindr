<?php

declare(strict_types=1);

namespace App\Support\Ai;

final readonly class ConversationTextContext
{
    public function __construct(
        public string $input,
        public int $messageCount,
        public int $lastMessageId,
    ) {}
}
