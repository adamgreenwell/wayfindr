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

    private function __construct(
        public readonly bool $scheduled,
        public readonly bool $open,
        public readonly ?CarbonImmutable $opensAt,
        public readonly string $timezone,
        public readonly ?string $awayMessage,
    ) {}

    public static function for(Site $site, ?CarbonInterface $now = null): self
    {
        $config = is_array($site->settings['availability'] ?? null)
            ? $site->settings['availability']
            : [];

        $timezone = self::timezone($config['timezone'] ?? null);

        // A site with no schedule behaves exactly as it did before this existed:
        // always open. Absence of configuration must never read as "closed", or
        // enabling the feature would silently shut every existing desk.
        if (($config['enabled'] ?? false) !== true) {
            return new self(false, true, null, $timezone, null);
        }

        $at = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $weekdays = self::weekdays($config['weekdays'] ?? []);
        $awayMessage = self::text($config['away_message'] ?? null);

        $closedUntil = self::closedUntil($config['closed_until'] ?? null, $timezone);
        $overridden = $closedUntil !== null && $closedUntil->greaterThan($at);

        $open = ! $overridden && self::withinHours($weekdays, $at);

        return new self(
            true,
            $open,
            $open ? null : self::reopensAt($weekdays, $at, $overridden ? $closedUntil : null),
            $timezone,
            $open ? null : $awayMessage,
        );
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
