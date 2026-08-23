<?php

namespace App\Support\Visitors;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How recently a visitor was seen, in one place.
 *
 * The two-minute and fifteen-minute cutoffs were written twice -- once in
 * Visitor::presenceState() to label a single record, once in
 * ConversationQueueQuery::applyPresence() to filter a list -- and a visitor
 * index would have been the third copy. ConversationQueueQuery's own docblock
 * names that as the failure it exists to prevent: a decision implemented twice,
 * one copy running a version behind.
 *
 * Not to be confused with SiteInstallHealth's thirty minutes, which answers a
 * different question -- whether the widget is installed and reporting at all,
 * rather than whether somebody is at the other end right now.
 */
final class VisitorPresence
{
    public const ACTIVE = 'active';

    public const RECENT = 'recent';

    public const QUIET = 'quiet';

    public const NOT_REPORTED = 'not_reported';

    /** Seen within this, and somebody is at the other end. */
    public const ACTIVE_MINUTES = 2;

    /** Seen within this, and they were here a moment ago. */
    public const RECENT_MINUTES = 15;

    /**
     * @return array<int, string>
     */
    public static function states(): array
    {
        return [self::ACTIVE, self::RECENT, self::QUIET, self::NOT_REPORTED];
    }

    public static function stateFor(?CarbonInterface $lastSeenAt, ?CarbonInterface $now = null): string
    {
        if ($lastSeenAt === null) {
            return self::NOT_REPORTED;
        }

        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now());

        if ($lastSeenAt->greaterThanOrEqualTo($at->subMinutes(self::ACTIVE_MINUTES))) {
            return self::ACTIVE;
        }

        if ($lastSeenAt->greaterThanOrEqualTo($at->subMinutes(self::RECENT_MINUTES))) {
            return self::RECENT;
        }

        return self::QUIET;
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::ACTIVE => 'Active recently',
            self::RECENT => 'Recently active',
            self::QUIET => 'Quiet',
            default => 'Not reported',
        };
    }

    /**
     * Narrow a query on the column holding the visitor's last-seen time.
     *
     * The buckets are mutually exclusive, so filtering by "recent" does not
     * also return everyone who is active right now.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function constrain(Builder $query, string $state, string $column = 'last_seen_at'): Builder
    {
        $active = now()->subMinutes(self::ACTIVE_MINUTES);
        $recent = now()->subMinutes(self::RECENT_MINUTES);

        return match ($state) {
            self::ACTIVE => $query->where($column, '>=', $active),
            self::RECENT => $query->where($column, '<', $active)->where($column, '>=', $recent),
            self::QUIET => $query->where($column, '<', $recent),
            self::NOT_REPORTED => $query->whereNull($column),
            default => $query,
        };
    }
}
