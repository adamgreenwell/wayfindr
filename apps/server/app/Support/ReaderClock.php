<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a stored moment becomes a moment on someone's clock.
 *
 * Everything in the database is UTC and stays UTC. This is the seam it crosses
 * on the way to a screen, and it is the only one: a moment that reaches a view
 * without passing through here is being rendered in storage's clock rather
 * than the reader's, which is the defect this exists to remove.
 *
 * ## Why a seam rather than a request-scoped `app.timezone`
 *
 * Moving `app.timezone` per request is the smaller-looking change and it is
 * wrong, because `app.timezone` is not a display setting. Laravel writes
 * `created_at` with it, into columns that carry no offset. Point it at Berlin
 * for the duration of a request and every row written during that request
 * stores Berlin wall-clock time in a column every other reader -- and every
 * later query -- treats as UTC. The drift is permanent, invisible, and depends
 * on who happened to be signed in.
 *
 * Converting at the edge cannot do that. Storage has one clock, screens have
 * as many as there are readers, and this function is the boundary between
 * them.
 *
 * ## Whose clock, and when it is nobody's
 *
 * The reader defaults to whoever is signed in, which is what a view wants and
 * saves every call site an argument. It is an argument rather than a lookup so
 * that code running outside a request can still be right: a queued mail job
 * has no `Auth::user()`, and its reader is the recipient. Anything rendering
 * for a named person should pass them.
 *
 * ## Why formatting moved in here
 *
 * This class used to convert and stop, on the argument that the formats
 * genuinely differ -- an expiry wants a time, an audit row wants the date too
 * -- and that collapsing them would replace a real distinction with a lookup
 * table. That objection was right about a table of arbitrary `d.m.Y` patterns
 * and wrong about what was needed.
 *
 * A zone is only half of what a moment needs. `format('M j, Y')` writes an
 * English month in a US order to a German reader whose clock is now correct,
 * which is a converted timestamp that still reads foreign. The three methods
 * below are ICU skeletons, not patterns: they name the three questions a
 * reader actually asks -- which day, which day and what time, what time -- and
 * the twenty hardcoded formats in the tree collapsed into them without loss.
 * The 12- versus 24-hour switch comes free, and no hand-written pattern can
 * express it.
 *
 * `isoFormat` and not `translatedFormat`: the latter translates the month name
 * and keeps the US order, so Italian gets `ago 24, 2026` -- a worse answer than
 * leaving it in English, because it looks translated.
 *
 * Relative times need nothing. Carbon's own service provider listens for
 * Laravel's `LocaleUpdated` event, so every `diffForHumans()` in the codebase
 * already follows the page.
 *
 * Not every absolute time is the reader's, and those must NOT come through
 * here. A site's support hours belong to the site -- "visitors are told
 * support is back at 09:00" is a statement in the site's own zone, and moving
 * it to the agent's clock would make the sentence untrue. {@see
 * \App\Support\Sites\SiteAvailability} carries its own zone for exactly that
 * reason. The test is whose question the time answers: "when will you be
 * back?" is the site's, "when does my access expire?" is the reader's.
 */
final class ReaderClock
{
    /**
     * The zone this reader reads in.
     *
     * @param  User|null  $reader  Defaults to the signed-in user; pass one
     *                             explicitly outside a request.
     */
    public static function zone(?User $reader = null): string
    {
        return DashboardTimezone::forUser($reader ?? Auth::user());
    }

    /**
     * A stored moment, moved onto the reader's clock but not yet written down.
     *
     * For anything a person reads, prefer {@see self::date()},
     * {@see self::dateTime()} or {@see self::time()} below. This stays public
     * for the cases that genuinely need the Carbon instance -- a comparison, a
     * derived value, a format no reader sees.
     */
    public static function moment(DateTimeInterface $at, ?User $reader = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::zone($reader));
    }

    /**
     * The moment on the reader's clock AND in the reader's language.
     *
     * Passing a reader names the WHOLE reader, not only their zone. That
     * matters because the two callers are different in kind:
     *
     * - A request passes nobody. `SetDashboardLocale` has already set the
     *   page's language, with the route's extraction status folded in, so
     *   ambient is not just convenient there -- it is the only answer that
     *   respects the extracted-route scoping.
     * - A queued job passes the recipient, because there is no page and
     *   nobody signed in. Its language cannot be ambient; the worker's is the
     *   install default, which is how an agent who reads German got a digest
     *   dated in English.
     */
    private static function readable(DateTimeInterface $at, ?User $reader): CarbonImmutable
    {
        $moment = self::moment($at, $reader);

        return $reader === null ? $moment : $moment->locale(DashboardLanguage::for($reader));
    }

    /**
     * Which day: `Aug 24, 2026`, `24. Aug 2026`, `24 ago 2026`.
     *
     * The medium skeleton rather than the short one. `L` would give
     * `08/24/2026` -- correct for the locale and worse for everyone, since a
     * numeric date is the one shape a reader cannot disambiguate on sight.
     * `ll` also happens to render English exactly as this codebase already
     * did, so adopting the seam changed nothing for existing readers and
     * everything for German and Italian ones.
     */
    public static function date(DateTimeInterface $at, ?User $reader = null): string
    {
        return self::readable($at, $reader)->isoFormat('ll');
    }

    /**
     * Which day and what time: `Aug 24, 2026 3:05 PM`, `24. Aug 2026 15:05`.
     */
    public static function dateTime(DateTimeInterface $at, ?User $reader = null): string
    {
        return self::readable($at, $reader)->isoFormat('ll LT');
    }

    /**
     * What time: `3:05 PM`, `15:05`.
     */
    public static function time(DateTimeInterface $at, ?User $reader = null): string
    {
        return self::readable($at, $reader)->isoFormat('LT');
    }

    /**
     * What time, and on whose clock: `3:05 PM CEST`, `15:05 CEST`.
     *
     * A fourth method rather than a caller gluing a zone on, because the two
     * places that want it are time-bounded ACCESS grants -- "read-only until
     * 16:32" is a promise, and a promise with no clock named is one an agent
     * in another zone reads wrongly and cannot tell they have.
     */
    public static function timeWithZone(DateTimeInterface $at, ?User $reader = null): string
    {
        return self::readable($at, $reader)->isoFormat('LT z');
    }
}
