<?php

namespace App\Support\Sites;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Whether a site's support desk is open, and if not, when it opens next.
 *
 * A desk is a place, not a person: this answers "is anyone expected to be
 * here", not "is a particular agent online". Agent presence is a separate
 * question and belongs with routing.
 *
 * Schedules are per site rather than per account because one account can carry
 * sites in different regions, and a shared schedule would be wrong for at least
 * one of them.
 */
final class SiteAvailability
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** How long "close the desk early" can mean. Every one of them expires. */
    public const CLOSURES = ['hour', 'today', 'tomorrow'];

    private function __construct(
        public readonly bool $scheduled,
        public readonly bool $open,
        public readonly ?CarbonImmutable $opensAt,
        public readonly string $timezone,
        public readonly ?string $awayMessage,
        // Set only while a manual close is holding the desk shut, so the
        // dashboard can offer to undo the thing somebody did rather than
        // explain the schedule back to them.
        public readonly ?CarbonImmutable $closedUntil = null,
    ) {}

    public static function for(Site $site, ?CarbonInterface $now = null): self
    {
        $config = self::config($site);
        $timezone = self::timezone($config['timezone'] ?? null);

        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $awayMessage = self::text($config['away_message'] ?? null);

        $closedUntil = self::closedUntil($config['closed_until'] ?? null, $timezone);
        $overridden = $closedUntil !== null && $closedUntil->greaterThan($at);

        // A site with no schedule behaves exactly as it did before this existed:
        // always open. Absence of configuration must never read as "closed", or
        // enabling the feature would silently shut every existing desk.
        //
        // A manual close is the PRESENCE of configuration rather than its
        // absence, so it still holds here -- and an unscheduled desk is where
        // "I am stepping out" has nowhere else to be said. With no hours to
        // hand back to, it reopens exactly when it expires.
        if (($config['enabled'] ?? false) !== true) {
            return $overridden
                ? new self(false, false, $closedUntil, $timezone, $awayMessage, $closedUntil)
                : new self(false, true, null, $timezone, null);
        }

        $weekdays = self::weekdays($config['weekdays'] ?? []);
        $open = ! $overridden && self::withinHours($weekdays, $at);

        return new self(
            true,
            $open,
            $open ? null : self::reopensAt($weekdays, $at, $overridden ? $closedUntil : null),
            $timezone,
            $open ? null : $awayMessage,
            $overridden ? $closedUntil : null,
        );
    }

    /**
     * When a "close the desk early" choice ends, in the site's own zone.
     *
     * The presets exist so the common case is one click at the moment somebody
     * is already leaving. Every one of them ends on its own, because a manual
     * close with no expiry is the flag nobody remembers on Monday and the desk
     * stays dark until a visitor complains.
     */
    public static function closureEndsAt(Site $site, string $preset, ?CarbonInterface $now = null): ?CarbonImmutable
    {
        if (! in_array($preset, self::CLOSURES, true)) {
            return null;
        }

        $config = self::config($site);
        $timezone = self::timezone($config['timezone'] ?? null);
        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($timezone);

        // An unscheduled desk has no weekdays to consult, and both day-based
        // presets fall back to plain midnight for it.
        $weekdays = ($config['enabled'] ?? false) === true
            ? self::weekdays($config['weekdays'] ?? [])
            : self::weekdays([]);

        $tomorrow = $at->addDay()->startOfDay();

        return match ($preset) {
            'hour' => $at->addHour(),
            'today' => self::endOfWorkingDay($weekdays, $at),
            // Reuses the schedule rule rather than restating it, so "back
            // tomorrow" cannot disagree with what the payload promises.
            'tomorrow' => self::nextOpening($weekdays, $tomorrow) ?? $tomorrow,
        };
    }

    /**
     * The rest of today: today's closing time where there is one, midnight
     * otherwise. A desk already past closing gets midnight too, because the
     * rest of today cannot reach backwards.
     *
     * @param  array<string, array{0: string, 1: string}|null>  $weekdays
     */
    private static function endOfWorkingDay(array $weekdays, CarbonImmutable $at): CarbonImmutable
    {
        $hours = $weekdays[self::DAYS[$at->dayOfWeekIso - 1]] ?? null;
        $midnight = $at->addDay()->startOfDay();

        if ($hours === null) {
            return $midnight;
        }

        $closes = $at->setTime(0, 0)->addMinutes(self::minutes($hours[1]));

        return $closes->greaterThan($at) ? $closes : $midnight;
    }

    /** @return array<string, mixed> */
    private static function config(Site $site): array
    {
        return is_array($site->settings['availability'] ?? null)
            ? $site->settings['availability']
            : [];
    }

    /**
     * @return array{away: bool, message: string|null, opens_at: string|null, timezone: string}
     */
    public function toPayload(): array
    {
        return [
            'away' => ! $this->open,
            'message' => $this->awayMessage,
            'opens_at' => $this->opensAt?->toIso8601String(),
            'timezone' => $this->timezone,
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string}|null>  $weekdays
     */
    private static function withinHours(array $weekdays, CarbonImmutable $at): bool
    {
        $hours = $weekdays[self::DAYS[$at->dayOfWeekIso - 1]] ?? null;

        if ($hours === null) {
            return false;
        }

        [$opens, $closes] = $hours;

        // Compared as minutes-from-midnight in the site's own zone, so a schedule
        // does not drift across a daylight-saving boundary the way stored UTC
        // instants would.
        $minutes = ($at->hour * 60) + $at->minute;

        return $minutes >= self::minutes($opens) && $minutes < self::minutes($closes);
    }

    /**
     * When the desk is next reachable.
     *
     * A manual close ending inside open hours reopens at that moment, not at
     * the next scheduled start -- otherwise the payload promises tomorrow while
     * the desk is answering in ten minutes.
     *
     * @param  array<string, array{0: string, 1: string}|null>  $weekdays
     */
    private static function reopensAt(array $weekdays, CarbonImmutable $at, ?CarbonImmutable $closedUntil): ?CarbonImmutable
    {
        if ($closedUntil !== null && self::withinHours($weekdays, $closedUntil)) {
            return $closedUntil;
        }

        return self::nextOpening($weekdays, $closedUntil ?? $at);
    }

    /**
     * @param  array<string, array{0: string, 1: string}|null>  $weekdays
     */
    private static function nextOpening(array $weekdays, CarbonImmutable $from): ?CarbonImmutable
    {
        // Seven days covers every schedule that has any open day at all; a desk
        // closed all week has no next opening and says so.
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $from->addDays($offset);
            $hours = $weekdays[self::DAYS[$day->dayOfWeekIso - 1]] ?? null;

            if ($hours === null) {
                continue;
            }

            $opening = $day->setTime(0, 0)->addMinutes(self::minutes($hours[0]));

            if ($opening->greaterThanOrEqualTo($from)) {
                return $opening;
            }
        }

        return null;
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return ($hour * 60) + $minute;
    }

    /**
     * @return array<string, array{0: string, 1: string}|null>
     */
    private static function weekdays(mixed $weekdays): array
    {
        $parsed = [];

        foreach (self::DAYS as $day) {
            $hours = is_array($weekdays) ? ($weekdays[$day] ?? null) : null;

            $parsed[$day] = is_array($hours)
                && self::isTime($hours[0] ?? null)
                && self::isTime($hours[1] ?? null)
                && self::minutes($hours[0]) < self::minutes($hours[1])
                    ? [$hours[0], $hours[1]]
                    : null;
        }

        return $parsed;
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function closedUntil(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function timezone(mixed $value): string
    {
        return is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true)
            ? $value
            : (string) config('app.timezone', 'UTC');
    }

    private static function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
