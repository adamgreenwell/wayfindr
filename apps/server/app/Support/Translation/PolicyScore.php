<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * How far a draft is from the policy, counted rather than felt.
 */
final class PolicyScore
{
    /**
     * @param  array<string, array<int, array{key: string, detail: string}>>  $violations  rule => occurrences
     */
    public function __construct(
        public readonly string $catalogue,
        public readonly int $scored,
        public readonly int $drafted,
        public readonly array $violations = [],
        public readonly ?int $agreed = null,
    ) {}

    public function violationCount(): int
    {
        return array_sum(array_map('count', $this->violations));
    }

    /**
     * Agreement with an already-reviewed catalogue, where one exists.
     *
     * Null rather than zero when there is nothing to compare against: a new
     * language has no reviewed copy, and reporting 0% agreement for it would be
     * a number that means nothing pretending to be a bad one.
     */
    public function agreementPercent(): ?float
    {
        if ($this->agreed === null || $this->drafted === 0) {
            return null;
        }

        return 100 * $this->agreed / $this->drafted;
    }
}
