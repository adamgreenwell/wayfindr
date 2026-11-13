<?php

declare(strict_types=1);

namespace App\Support\Ai\Policy;

final readonly class ResolvedAiAssistPolicy
{
    public function __construct(
        public int $accountId, public int $siteId, public bool $explicit,
        public string $mode, public bool $summary, public bool $replyDraft,
        public bool $relevantArticles, public bool $ticketDetails,
        public int $maxContextCharacters, public bool $articleContextAllowed,
        public string $revision,
    ) {}

    public function allows(string $purpose): bool
    {
        if ($this->mode === 'off') {
            return false;
        }

        return match ($purpose) {
            'conversation_summary' => $this->summary,
            'conversation_reply_draft', 'conversation_draft_refinement' => $this->replyDraft,
            'conversation_knowledge_suggestion' => $this->relevantArticles,
            'conversation_ticket_suggestion' => $this->ticketDetails,
            default => false,
        };
    }

    public function isEnabled(): bool
    {
        return $this->mode !== 'off' && ($this->summary || $this->replyDraft || $this->relevantArticles || $this->ticketDetails);
    }

    public function revisionFor(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, $this->revision);
    }
}
