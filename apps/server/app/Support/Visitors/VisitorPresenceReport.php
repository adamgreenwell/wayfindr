<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Events\VisitorPresenceUpdated;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SitePresenceReporting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Record that somebody is on a site right now (ADR 0019).
 *
 * Everything here is stamped by the SERVER. The widget reports that it is
 * present and where; it does not get to say when, because a browser clock is a
 * value this endpoint cannot verify and the endpoint is public. A forged
 * timestamp parked in the future would read `active` indefinitely and outrun
 * the retention window at the same time.
 */
final class VisitorPresenceReport
{
    public function record(Site $site, string $anonymousId, ?string $pageUrl): Visitor
    {
        try {
            // Wrapped, because on PostgreSQL a failed statement poisons the
            // transaction it is in: every statement after a constraint
            // violation errors with "current transaction is aborted" until a
            // rollback. Catching the exception is not enough on its own -- the
            // retry has to run on a connection that is still usable.
            //
            // DB::transaction() gives that either way. Standing alone it is a
            // real transaction; inside a caller's transaction it is a
            // SAVEPOINT, so the failed insert rolls back to the savepoint and
            // leaves the outer work intact.
            //
            // SQLite does not behave this way, which is why the suite was green
            // and the PostgreSQL run was not.
            return DB::transaction(fn (): Visitor => $this->stamp($this->resolve($site, $anonymousId), $pageUrl, $site));
        } catch (UniqueConstraintViolationException) {
            // Two first reports for the same visitor overlapped -- a page-load
            // heartbeat racing bootstrap, or two tabs opened together. Both saw
            // no row, both inserted, and `(site_id, anonymous_id)` let exactly
            // one win. The loser is not an error: the row it wanted now exists,
            // so re-read and stamp it.
            //
            // Retried once and no more. A second failure is not this race --
            // the row is there by then -- and a loop would turn some other
            // constraint problem into a hot one on a public endpoint.
            // Wrapped as well. Outside a transaction every statement
            // autocommits, so the site and visitor locks taken inside stamp()
            // would be released the moment each select finished -- and the
            // whole point of taking them is that they are still held when the
            // write happens.
            return DB::transaction(fn (): Visitor => $this->stamp($this->resolve($site, $anonymousId), $pageUrl, $site));
        }
    }

    private function resolve(Site $site, string $anonymousId): Visitor
    {
        return Visitor::query()->firstOrNew([
            'site_id' => $site->id,
            'anonymous_id' => $anonymousId,
        ]);
    }

    private function stamp(Visitor $visitor, ?string $pageUrl, Site $site): Visitor
    {
        // The site's setting, re-read under a lock, inside the transaction that
        // writes.
        //
        // The endpoint checks it on the way in, and an operator revoking
        // presence between that check and this write would have their deletion
        // pass over a row this request then created -- leaving one visitor
        // behind who never made contact, on a site that had just said not to
        // watch them, until the retention sweep. The dashboard promises
        // otherwise in as many words.
        //
        // Locking the SITE row is what serialises the two: the disable action
        // takes the same lock, so whichever arrives second sees what the first
        // one did instead of a copy of the world from before it started.
        $current = Site::query()->whereKey($site->getKey())->lockForUpdate()->first();

        // Archived counts as gone, checked on the LOCKED row. Archiving that
        // commits between the resolver reading the site and this lock would
        // otherwise let the write through -- and the event it broadcasts is
        // then suppressed by broadcastWhen(), so the row would exist with
        // nothing on any board ever showing it.
        if ($current === null || $current->isArchived()) {
            return $visitor;
        }

        $reporting = SitePresenceReporting::for($current);

        if (! $reporting->enabled) {
            return $visitor;
        }

        // The page address is decided by the LOCKED reading too, not by what
        // the endpoint saw on the way in. An operator switching addresses off
        // while this request was in flight would otherwise have their purge
        // pass over this visitor and then watch this write put one back.
        if (! $reporting->pageUrls) {
            $pageUrl = null;
        }

        // Re-read under a lock before merging metadata.
        //
        // `metadata` is one JSON column, so writing it replaces the whole
        // value. A heartbeat that resolved the row, then waited while bootstrap
        // committed new host context, would merge into the snapshot it read
        // first and put the old context back -- erasing what the visitor's own
        // page had just told us, and restoring a page address they had already
        // navigated away from.
        //
        // Only for a row that exists; a creation has nothing to conflict with.
        if ($visitor->exists) {
            $locked = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first();

            // Gone means the pruner deleted it between the read and this lock.
            // Keeping the stale model is the trap: `exists` is still true, so
            // save() updates zero rows, Eloquent calls that success, and the
            // endpoint answers 202 having stored nothing -- the visitor
            // vanishes from the board until they happen to be created again.
            $visitor = $locked ?? Visitor::query()->newModelInstance([
                'site_id' => $site->id,
                'anonymous_id' => $visitor->anonymous_id,
            ]);
        }

        $now = now();

        // `current_visit_started_at` is NOT set here, and neither is
        // `last_seen_at`. The model maintains both from `last_web_seen_at`, so
        // bootstrap, conversation start, message fetch, typing and this all get
        // the same transition -- and a returning visitor who opens the panel
        // before their first heartbeat still starts a new visit rather than
        // resuming one from days ago.
        // `presence_only` is set ONLY here and ONLY for a row this endpoint is
        // creating. It is positive evidence that retention needs: an existing
        // row might have been created by somebody opening the widget, which
        // ADR 0016 counts as contact, and no absence of conversations can tell
        // those apart afterwards.
        // A row that does not exist yet is a DURABLE cost, not a request.
        //
        // The per-address limiter bounds traffic, and traffic is the cheap
        // half: a forged client rotating anonymous IDs turns every accepted
        // request into a new row that lives for the full retention window, so
        // a ceiling generous enough for a busy office is millions of rows a
        // day when spent on creation instead. Refreshing an existing visitor
        // costs nothing durable and is not counted here.
        //
        // Over the limit the report is simply not stored. Not an error: the
        // client cannot tell the difference and should not be able to, and a
        // 429 here would leak how much of the quota is left.
        if (! $visitor->exists && ! $this->mayCreate($site)) {
            return $visitor;
        }

        $provenance = $visitor->exists ? [] : ['presence_only' => true];

        $visitor->forceFill([
            'metadata' => $this->metadata($visitor, $pageUrl, $site),
            'last_web_seen_at' => $now,
        ] + $provenance)->save();

        // Announced after the write, so a board that reacts by reading the row
        // sees what was written rather than what is about to be.
        event(new VisitorPresenceUpdated($site, $visitor));

        return $visitor;
    }

    /**
     * Is there room to mint a new visitor for this site from this address?
     *
     * Keyed by site and address rather than by visitor, which is the opposite
     * of the heartbeat's everyday quota and correct for the same reason: an
     * attacker choosing a fresh anonymous ID every time has an unlimited supply
     * of per-visitor buckets, and exactly one address.
     */
    private function mayCreate(Site $site): bool
    {
        $fingerprint = hash('sha256', (string) $site->getKey().'|'.(string) request()->ip());

        // TWO windows, because one cannot say both things. A minute-scale limit
        // has to be generous enough for an office arriving at nine, and thirty
        // a minute sustained is 43,200 rows a day and roughly 1.3 million
        // across the retention window -- so the burst allowance that makes the
        // feature work is also, on its own, a licence to grow the table
        // indefinitely.
        //
        // The daily budget is what bounds that. It is far above any real site's
        // new visitors from ONE address in a day, and far below what an
        // unattended script would reach by lunchtime.
        $perMinute = max(1, (int) config('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 30));
        $perDay = max(1, (int) config('wayfindr.widget_rate_limits.presence_creations_per_ip_per_day', 2000));

        // Checked before it is spent: attempting the minute limit first would
        // consume it even when the day is already exhausted, and the two
        // counters would drift apart for no reason.
        if (RateLimiter::tooManyAttempts('presence-create-day|'.$fingerprint, $perDay)) {
            return false;
        }

        if (RateLimiter::attempt('presence-create|'.$fingerprint, $perMinute, static fn (): bool => true) === false) {
            return false;
        }

        RateLimiter::hit('presence-create-day|'.$fingerprint, 86400);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Visitor $visitor, ?string $pageUrl, Site $site): array
    {
        $metadata = is_array($visitor->metadata) ? $visitor->metadata : [];

        // forSite(), because this is INGRESS and the site is known here. The
        // endpoint is public and so is the site key, so an address from any
        // other host is somebody else's page -- and stored addresses render as
        // clickable links in the agent dashboard.
        //
        // The model's saving hook reduces it again, which is not redundant:
        // this rejects at the door, the hook makes it true of the database no
        // matter who writes.
        $metadata['last_page_url'] = VisitorPageUrl::forSite($pageUrl, $site->domain);

        return $metadata;
    }
}
