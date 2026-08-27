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
            'metadata' => $this->metadata($visitor, $pageUrl),
            'last_seen_at' => $now,
        ])->save();

        return $visitor;
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
