<?php

declare(strict_types=1);

namespace App\Support\Translation;

/**
 * What a run would do, before it does any of it.
 *
 * A plan is the unit the policy asks for: the pipeline optimises for a
 * reviewable draft rather than a better first draft, and a draft nobody can
 * inspect before it lands is neither.
 */
final class CataloguePlan
{
    /**
     * @param  array<string, string>  $translated  key => proposed value
     * @param  array<string, string>  $carried  key => value kept as-is
     * @param  array<string, string>  $failures  key => why it could not be produced
     * @param  array<string, string>  $review  key => why a human should look
     */
    public function __construct(
        public readonly string $catalogue,
        public readonly string $targetLocale,
        public readonly array $translated = [],
        public readonly array $carried = [],
        public readonly array $failures = [],
        public readonly array $review = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->translated === [];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /**
     * Everything the target catalogue should contain, translated and carried
     * alike, so key parity with the source is structural rather than hoped for.
     *
     * @return array<string, string>
     */
    public function merged(): array
    {
        return $this->translated + $this->carried;
    }
}
