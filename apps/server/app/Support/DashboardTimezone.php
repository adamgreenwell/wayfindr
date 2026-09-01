<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use DateTimeZone;

/**
 * Which clock the dashboard renders in.
 *
 * A translated string in the wrong timezone is not localized. An agent in
 * Berlin closing a ticket at 16:32 read `14:32`, and a report covering
 * "yesterday" covered a day that had ended two hours before theirs did.
 *
 * Per agent rather than per install, because a distributed desk is the normal
 * case and the install has no opinion worth imposing on it. Null means "follow
 * the install", which is what every agent had before this existed.
 *
 * ## Why this reads its own config key
 *
 * The install default is `wayfindr.dashboard_timezone`, NOT `app.timezone`,
 * because those two answer different questions and must not be joined.
 * `app.timezone` is the STORAGE clock: Laravel writes `created_at` with it,
 * into columns that carry no offset, so every row in the database is only
 * readable because that value never moves. It stays UTC forever.
 *
 * This key is the DISPLAY clock, and it is per reader. Nothing here is ever
 * written to the database; {@see ReaderClock} converts on the way to the
 * screen and nowhere else.
 *
 * An earlier draft of this feature did move `app.timezone` per request, which
 * looked like the smallest possible change and silently corrupted data: a row
 * created while a Berlin agent was signed in stored `17:24:50` in a column
 * every other reader interprets as UTC. Two hours of permanent drift, decided
 * by whoever happened to be served at the time.
 */
final class DashboardTimezone
{
    /**
     * What an install renders in when nobody has chosen.
     *
     * UTC, and deliberately: an unconfigured install showing UTC is honest,
     * where guessing from the server's clock would silently pick whichever
     * region the host happens to sit in.
     */
    public const FALLBACK = 'UTC';

    /**
     * The install's own setting, unaffected by whoever is being served.
     */
    public static function installDefault(): string
    {
        return self::normalise(config('wayfindr.dashboard_timezone')) ?? self::FALLBACK;
    }

    /**
     * The clock this reader should see.
     */
    public static function forUser(?User $user): string
    {
        return self::normalise($user?->timezone) ?? self::installDefault();
    }

    /**
     * Every zone this install will accept as a stored preference.
     *
     * `ALL_WITH_BC`, deliberately. The canonical list leaves out roughly 180
     * backward-compatible aliases -- `US/Eastern`, `Asia/Calcutta`,
     * `Europe/Kiev` -- which PHP resolves perfectly well and which real
     * configuration is full of. Refusing them does not produce an error an
     * operator can see; it drops that reader to the fallback and renders their
     * whole dashboard on a clock they did not choose.
     *
     * The test is "can the platform render a time in it", and that is the list
     * that answers it.
     *
     * @return list<string>
     */
    public static function acceptable(): array
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);
    }

    /**
     * Null for anything that is not a zone the platform can resolve.
     *
     * Checked against the platform's own database rather than a list kept
     * here: zones are added and renamed by the tzdata release the host runs,
     * and a list in this file would be wrong the first time that happened.
     */
    public static function normalise(mixed $timezone): ?string
    {
        if (! is_string($timezone)) {
            return null;
        }

        $timezone = trim($timezone);

        if ($timezone === '') {
            return null;
        }

        return in_array($timezone, self::acceptable(), true) ? $timezone : null;
    }

    /**
     * Every zone to OFFER, grouped for a select element.
     *
     * Canonical names only, unlike {@see self::acceptable()}. Listing the
     * aliases as well would put `Asia/Kolkata` and `Asia/Calcutta` in the same
     * menu as if they were a choice between two things.
     *
     * `$include` keeps a value that is already stored but not on the canonical
     * list, which is the difference between a select and a trap: without it an
     * agent whose zone is `US/Eastern` opens their profile, finds nothing
     * selected, and silently saves whichever option happened to be first.
     *
     * @return array<string, list<string>>
     */
    public static function choices(?string $include = null): array
    {
        $identifiers = DateTimeZone::listIdentifiers();
        $include = self::normalise($include);

        if ($include !== null && ! in_array($include, $identifiers, true)) {
            $identifiers[] = $include;
        }

        $grouped = [];

        foreach ($identifiers as $identifier) {
            $region = str_contains($identifier, '/') ? explode('/', $identifier)[0] : 'Other';
            $grouped[$region][] = $identifier;
        }

        foreach ($grouped as &$region) {
            sort($region);
        }

        unset($region);
        ksort($grouped);

        return $grouped;
    }
}
