<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Support\Carbon;

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
        $visitor = Visitor::query()->firstOrNew([
            'site_id' => $site->id,
            'anonymous_id' => $anonymousId,
        ]);

        $now = now();

        $visitor->forceFill([
            'metadata' => $this->metadata($visitor, $pageUrl),
            'current_visit_started_at' => $this->visitStartedAt($visitor, $now),
            'last_seen_at' => $now,
        ])->save();

        return $visitor;
    }

    /**
     * When the visit this report belongs to began.
     *
     * A gap long enough to read as `quiet` is long enough to call the next
     * report a new visit, so this reuses `VisitorPresence`'s existing recent
     * window rather than inventing a session length.
     *
     * The first clause is load-bearing rather than defensive: a visitor's
     * OPENING heartbeat has no previous one to be older than, so a rule written
     * only around the gap would never start a visit at all and every new
     * visitor would be left without the field the board exists to show.
     */
    private function visitStartedAt(Visitor $visitor, Carbon $now): Carbon
    {
        $lastSeen = $visitor->last_seen_at;
        $current = $visitor->current_visit_started_at;

        if ($lastSeen === null || $current === null) {
            return $now;
        }

        return $lastSeen->lt($now->copy()->subMinutes(VisitorPresence::RECENT_MINUTES))
            ? $now
            : $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Visitor $visitor, ?string $pageUrl): array
    {
        $metadata = is_array($visitor->metadata) ? $visitor->metadata : [];

        // Sanitised here as well as by the model's saving hook. Not redundant:
        // this is the entry point and should reject at the door, while the hook
        // is what makes it true of the database no matter who writes.
        $metadata['last_page_url'] = VisitorPageUrl::sanitise($pageUrl);

        return $metadata;
    }
}
