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
     * @param  array<int, string>  $order  the source's key order, which the
     *                                     merged catalogue is written in
     */
    public function __construct(
        public readonly string $catalogue,
        public readonly string $targetLocale,
        public readonly array $translated = [],
        public readonly array $carried = [],
        public readonly array $failures = [],
        public readonly array $review = [],
        public readonly array $order = [],
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
     * alike, IN THE SOURCE'S ORDER.
     *
     * The order is the point. `translated + carried` produced the right set of
     * keys and the wrong file: a cognate or an all-placeholder value is never
     * sent to the engine, so it landed in `carried` and got appended after
     * every translated key, moving it out of the group it belongs to. Nothing
     * breaks -- Laravel looks up by key -- but the drafted catalogue stops
     * lining up against the English one, and lining up against the English one
     * is how a person reviews it.
     *
     * @return array<string, string>
     */
    public function merged(): array
    {
        $all = $this->translated + $this->carried;

        if ($this->order === []) {
            return $all;
        }

        $ordered = [];

        foreach ($this->order as $key) {
            if (array_key_exists($key, $all)) {
                $ordered[$key] = $all[$key];
            }
        }

        // Anything the source did not name still ships rather than vanishing.
        return $ordered + $all;
    }
}
