<?php

declare(strict_types=1);

namespace App\Support\Ai\Refinement;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Parsed private proposal only; shape/membership checks grant no Apply authority. */
final readonly class DraftRefinementProposal implements JsonSerializable
{
    public function __construct(
        #[SensitiveParameter] private string $candidate,
        private array $citations,
        #[SensitiveParameter] private ?string $limitation,
    ) {}

    public function previewData(): array
    {
        return ['candidate' => $this->candidate, 'citations' => $this->citations, 'limitation' => $this->limitation];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Explicitly select a freshly authorized private preview.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Refinement proposals cannot enter shared queues or durable history.');
    }

    public function __debugInfo(): array
    {
        return ['candidate_characters' => mb_strlen($this->candidate), 'citation_count' => count($this->citations), 'has_limitation' => $this->limitation !== null];
    }
}
