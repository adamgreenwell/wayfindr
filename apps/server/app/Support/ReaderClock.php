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
     * A stored moment, moved onto the reader's clock.
     *
     * Formatting stays at the call site because the formats genuinely differ
     * -- an expiry wants `H:i T`, an audit row wants the date too -- and
     * collapsing them here would replace a real distinction with a lookup
     * table. The zone is the part that must be decided once, and it is.
     */
    public static function moment(DateTimeInterface $at, ?User $reader = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(self::zone($reader));
    }
}
