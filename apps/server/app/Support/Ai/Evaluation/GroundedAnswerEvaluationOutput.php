<?php

declare(strict_types=1);

namespace App\Support\Ai\Evaluation;

/** One strict provider candidate ready to record and score offline. */
final readonly class GroundedAnswerEvaluationOutput
{
    /** @param list<string> $articleIds */
    public function __construct(
        public string $decision,
        public float $confidencePercent,
        public string $answer,
        public array $articleIds,
        public string $refusalReason,
    ) {}

    /**
     * @return array{
     *   decision: string,
     *   confidence_percent: float,
     *   answer: string,
     *   article_ids: list<string>,
     *   refusal_reason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'confidence_percent' => $this->confidencePercent,
            'answer' => $this->answer,
            'article_ids' => $this->articleIds,
            'refusal_reason' => $this->refusalReason,
        ];
    }
}
