<?php

declare(strict_types=1);

namespace App\Support\Updates;

/** A frozen review receipt. Execution must recheck its facts before applying it. */
final readonly class UpdatePlan
{
    /** @param array<string, mixed> $facts */
    public function __construct(private array $facts) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(self::canonical($this->facts), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['schema' => 1, 'plan_id' => $this->fingerprint()] + $this->facts;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
