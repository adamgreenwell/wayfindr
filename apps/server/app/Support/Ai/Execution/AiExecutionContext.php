<?php

declare(strict_types=1);

namespace App\Support\Ai\Execution;

use App\Support\Ai\Network\AiNetworkException;
use SensitiveParameter;

/** Immutable server-owned identity. Contains no prompt or credentials. */
final readonly class AiExecutionContext
{
    public function __construct(
        #[SensitiveParameter] public string $kind,
        public int $accountId,
        public int $actorId,
        #[SensitiveParameter] public string $purpose,
        #[SensitiveParameter] public string $attemptId,
        public ?int $artifactId = null,
        #[SensitiveParameter] public ?string $generation = null,
        #[SensitiveParameter] public ?string $proposedRevision = null,
        #[SensitiveParameter] public ?string $testGeneration = null,
        #[SensitiveParameter] public ?string $policyRevision = null,
        public ?int $maxContextCharacters = null,
    ) {
        if (! in_array($kind, ['support', 'refinement', 'probe', 'discovery'], true) || $accountId <= 0 || $actorId <= 0
            || ($kind === 'probe' && $purpose !== 'configuration_probe')
            || ($kind === 'discovery' && $purpose !== 'model_discovery')
            || ($kind === 'support' && ! array_key_exists($purpose, AiSupportUsage::ARTIFACTS))
            || ($kind === 'refinement' && $purpose !== 'conversation_draft_refinement')
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $attemptId) !== 1) {
            throw new AiNetworkException('execution_identity_invalid');
        }
    }

    public function requiresReservation(): bool
    {
        return in_array($this->kind, ['support', 'refinement'], true);
    }

    public static function probe(int $accountId, int $actorId, #[SensitiveParameter] string $proposedRevision, #[SensitiveParameter] string $testGeneration): self
    {
        return new self('probe', $accountId, $actorId, 'configuration_probe', $testGeneration,
            generation: $testGeneration, proposedRevision: $proposedRevision, testGeneration: $testGeneration);
    }

    public static function discovery(int $accountId, int $actorId, #[SensitiveParameter] string $proposedRevision, #[SensitiveParameter] string $attemptId): self
    {
        return new self('discovery', $accountId, $actorId, 'model_discovery', $attemptId, proposedRevision: $proposedRevision);
    }
}
