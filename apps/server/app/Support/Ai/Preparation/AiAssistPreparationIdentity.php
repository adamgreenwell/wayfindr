<?php

declare(strict_types=1);

namespace App\Support\Ai\Preparation;

/** Server-only, content-free snapshot. This is neither a reservation nor authority to call a provider. */
final readonly class AiAssistPreparationIdentity
{
    public function __construct(
        public int $accountId,
        public int $siteId,
        public int $conversationId,
        public string $purpose,
        public string $mode,
        public string $state,
        public string $policyRevision,
        public string $connectionRevision,
        public string $sourceRevision,
        public ?string $catalogueRevision,
        public ?int $lastMessageId,
        public int $maxContextCharacters,
        public string $fingerprint,
        public array $sourceSnapshot = [],
    ) {}

    public function matches(self $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }

    /** An actual authenticated open must still be proven by the future POST coordinator. */
    public function canPrepareOnOpen(string $workspace): bool
    {
        if ($this->mode !== 'prepare' || $this->state !== 'eligible') {
            return false;
        }

        return match ($workspace) {
            'conversation' => in_array($this->purpose, ['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion'], true),
            'conversion' => $this->purpose === 'conversation_ticket_suggestion',
            default => false,
        };
    }

    /**
     * Classify an exact receipt only. Output reads/Use, cooldown and atomic
     * reservation remain separate gates; a cache miss never authorizes I/O.
     * A matching failure, unknown state or expired pending receipt is terminal
     * for automatic ensure, rather than a reason to retry on every open.
     */
    public function reuseState(?string $storedFingerprint, string $storedStatus, bool $pendingFresh = false): ?string
    {
        if ($storedFingerprint === null || ! hash_equals($this->fingerprint, $storedFingerprint)) {
            return null;
        }
        if ($this->state === 'insufficient_context') {
            return 'insufficient_context';
        }

        return match ($storedStatus) {
            'pending', 'running' => $pendingFresh ? 'preparing' : 'unavailable',
            'ready' => 'ready',
            'insufficient_context' => 'insufficient_context',
            default => 'unavailable',
        };
    }
}
