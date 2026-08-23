<?php

declare(strict_types=1);

namespace App\Support\Reporting;

/**
 * A set of measured durations, reported as median and 90th percentile.
 *
 * Never as a mean. Support work is long-tailed -- most replies are quick and a
 * few take a day -- so an average sits in the empty space between the two and
 * describes neither. The median says what a typical visitor experienced; the
 * p90 says what the unlucky tenth did, which is the number worth acting on.
 *
 * Percentiles are interpolated between the two observations that bracket them,
 * which is the conventional definition and the one a reader assumes. The
 * alternative -- nearest-rank -- was tried first and rejected: it biases small
 * samples low, reporting the faster of two durations as their median. A report
 * that flatters a quiet desk is worst precisely where the sample is thin and
 * someone is trying to decide whether there is a problem yet.
 */
final class DurationSummary
{
    private function __construct(
        public readonly int $count,
        public readonly ?int $median,
        public readonly ?int $p90,
    ) {}

    /**
     * @param  list<int>  $seconds
     */
    public static function fromSeconds(array $seconds): self
    {
        if ($seconds === []) {
            return new self(0, null, null);
        }

        sort($seconds);

        return new self(
            count($seconds),
            self::percentile($seconds, 0.5),
            self::percentile($seconds, 0.9),
        );
    }

    public static function empty(): self
    {
        return new self(0, null, null);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    public function medianLabel(): string
    {
        return self::humanize($this->median);
    }

    public function p90Label(): string
    {
        return self::humanize($this->p90);
    }

    /**
     * Linear-interpolated percentile of an already-sorted list.
     *
     * @param  list<int>  $sorted
     */
    private static function percentile(array $sorted, float $percentile): int
    {
        $count = count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $position = $percentile * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return (int) round($sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($position - $lower));
    }

    /**
     * A duration a person can read at a glance.
     *
     * Two units at most: "2h 15m" is useful, "2h 15m 3s" is noise at the scale
     * anyone reads a report. Seconds only appear when they are the whole story.
     */
    public static function humanize(?int $seconds): string
    {
        if ($seconds === null) {
            return '--';
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            $remainder = $seconds % 60;

            return $remainder === 0 ? $minutes.'m' : $minutes.'m '.$remainder.'s';
        }

        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return $minutes === 0 ? $hours.'h' : $hours.'h '.$minutes.'m';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $hours === 0 ? $days.'d' : $days.'d '.$hours.'h';
    }
}
