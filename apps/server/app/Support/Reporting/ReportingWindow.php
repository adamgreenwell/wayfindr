<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The span a report covers, and the days it is bucketed into.
 *
 * Buckets are built here and filled in PHP rather than grouped in SQL, and that
 * is a deliberate constraint rather than a shortcut. Day truncation has no
 * portable spelling: `date_trunc` is PostgreSQL, `strftime` is SQLite, and this
 * suite runs on SQLite while every documented install runs on PostgreSQL. A
 * driver-specific grouping expression would therefore be green in CI and broken
 * in production -- the one failure mode worth designing against.
 *
 * The queries stay ANSI, the bucketing happens here, and both databases get the
 * same arithmetic.
 */
final class ReportingWindow
{
    public const DEFAULT_DAYS = 30;

    /**
     * Ranges an operator can ask for.
     *
     * Ninety days is the ceiling because a day bucket stops being readable
     * beyond roughly that many columns, not because the query could not take
     * it. A longer range wants weekly buckets, which is a different screen.
     *
     * @var list<int>
     */
    public const CHOICES = [7, 30, 90];

    private function __construct(
        public readonly int $days,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    /**
     * Read a range off user input, falling back rather than failing.
     *
     * A range is a view preference, not an assertion: a stale bookmark or a
     * hand-edited query string should show the default report, not an error
     * page.
     */
    public static function fromRequestValue(mixed $value): self
    {
        $days = is_numeric($value) ? (int) $value : 0;

        return self::ofDays(in_array($days, self::CHOICES, true) ? $days : self::DEFAULT_DAYS);
    }

    /**
     * Days are the application's days.
     *
     * `now()` respects `app.timezone`, so an install configured for UTC buckets
     * by UTC dates even when the person reading it is somewhere else. That is
     * the same clock every other timestamp in the dashboard is shown in, and one
     * report disagreeing with the conversation list about which day something
     * happened would be worse than either convention.
     */
    public static function ofDays(int $days): self
    {
        $end = CarbonImmutable::now()->endOfDay();

        // Inclusive of today, so "last 7 days" is today plus the six before it.
        return new self($days, $end->subDays($days - 1)->startOfDay(), $end);
    }

    /**
     * One entry per day in the window, oldest first.
     *
     * Every day is present whether or not anything happened on it. A chart that
     * silently omits quiet days compresses the timeline and makes a weekend
     * look like an afternoon.
     *
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [];

        for ($day = $this->start; $day->lessThanOrEqualTo($this->end); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * An empty bucket per day, keyed the way {@see self::bucketKey()} keys them.
     *
     * @return array<string, int>
     */
    public function emptyBuckets(): array
    {
        $buckets = [];

        foreach ($this->days() as $day) {
            $buckets[$this->bucketKey($day)] = 0;
        }

        return $buckets;
    }

    public function bucketKey(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->format('Y-m-d');
    }

    public function label(): string
    {
        return 'Last '.$this->days.' days';
    }
}
