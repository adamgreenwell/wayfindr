<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\Visitor;

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

        // `current_visit_started_at` is NOT set here. The model maintains it
        // from whatever replaces `last_seen_at`, so bootstrap, conversation
        // start, message fetch and typing all get the same transition -- and a
        // returning visitor who opens the panel before their first heartbeat
        // still starts a new visit rather than resuming one from days ago.
        $visitor->forceFill([
            'metadata' => $this->metadata($visitor, $pageUrl, $site),
            'last_seen_at' => $now,
        ])->save();

        return $visitor;
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
