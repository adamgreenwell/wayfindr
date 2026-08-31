<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Support\ReaderClock;
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
        /**
         * The clock whose days these are.
         *
         * `start` and `end` are kept as UTC instants regardless, because they
         * are bound straight into `whereBetween` against columns that store
         * UTC -- Laravel formats a datetime binding in the instance's OWN
         * zone, so a Berlin-carrying bound would silently shift every query by
         * the offset. The zone lives here instead, and only bucketing and
         * labelling use it.
         */
        public readonly string $zone,
    ) {}

    /**
     * Read a range off user input, falling back rather than failing.
     *
     * A range is a view preference, not an assertion: a stale bookmark or a
     * hand-edited query string should show the default report, not an error
     * page.
     */
    public static function fromRequestValue(mixed $value, ?string $zone = null): self
    {
        $days = is_numeric($value) ? (int) $value : 0;

        return self::ofDays(in_array($days, self::CHOICES, true) ? $days : self::DEFAULT_DAYS, $zone);
    }

    /**
     * Days are the READER's days.
     *
     * A day boundary only means something on a clock, so the window is cut on
     * the reader's -- `ReaderClock::zone()` by default, which is the signed-in
     * agent's.
     *
     * This used to argue for the install's clock, on the grounds that a report
     * disagreeing with the conversation list about which day something happened
     * would be worse than either convention. The consistency was the right thing
     * to want and UTC was the wrong way to get it: an agent in Berlin read a
     * "yesterday" that ended at 02:00 their time, and the conversation list
     * beside it was equally wrong in the same direction. Cutting the window on
     * the reader's clock keeps them agreeing AND makes them right.
     */
    public static function ofDays(int $days, ?string $zone = null): self
    {
        $zone ??= ReaderClock::zone();

        $end = CarbonImmutable::now($zone)->endOfDay();

        // Inclusive of today, so "last 7 days" is today plus the six before it.
        $start = $end->subDays($days - 1)->startOfDay();

        // Stored as the UTC instants those local boundaries fall on. The
        // boundary is the reader's midnight; the value bound into SQL has to be
        // what that midnight is in the column's clock.
        return new self($days, $start->setTimezone('UTC'), $end->setTimezone('UTC'), $zone);
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
        $day = $this->start->setTimezone($this->zone)->startOfDay();
        $end = $this->end->setTimezone($this->zone);

        // `startOfDay()` again on every step rather than a flat 24-hour stride:
        // across a DST change a day is 23 or 25 hours long, and striding would
        // walk the boundary off the midnight it started on.
        while ($day->lessThanOrEqualTo($end)) {
            $days[] = $day;
            $day = $day->addDay()->startOfDay();
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

    /**
     * Which day a moment belongs to, on the reader's clock.
     *
     * The conversion is the whole point. Rows arrive as UTC instants, and
     * formatting one without moving it first files 00:30 in Berlin under the
     * previous day -- into a bucket that exists, so nothing looks broken, and
     * the count is simply wrong for every moment in the offset band.
     */
    public function bucketKey(DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($this->zone)->format('Y-m-d');
    }

    public function label(): string
    {
        return 'Last '.$this->days.' days';
    }
}
