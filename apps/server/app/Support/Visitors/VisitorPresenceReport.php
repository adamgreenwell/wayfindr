<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;
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
            return $this->stamp($this->resolve($site, $anonymousId), $pageUrl, $site);
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
        $perMinute = max(1, (int) config('wayfindr.widget_rate_limits.presence_creations_per_ip_per_minute', 30));

        $key = 'presence-create|'.hash('sha256', (string) $site->getKey().'|'.(string) request()->ip());

        return RateLimiter::attempt($key, $perMinute, static fn (): bool => true) !== false;
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
