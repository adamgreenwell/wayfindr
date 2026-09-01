<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may watch a site's live board.
 *
 * Agents only. `ConversationChannel` admits a visitor as well, because a
 * conversation has two ends; this channel has one. A visitor has no business
 * knowing who else is on the site, and the payload here is precisely the thing
 * ADR 0019 promises is for the desk.
 */
class SitePresenceChannel
{
    public function join(?User $agent, int|string $siteId): bool
    {
        if (! $agent instanceof User || $agent->isDeactivated()) {
            return false;
        }

        // Numeric, checked rather than assumed. The channel name is client
        // supplied, and `sites.4abc.presence` reaching a query as `4` is the
        // shape that turns a typo into somebody else's site.
        if (! is_int($siteId) && ! ctype_digit((string) $siteId)) {
            return false;
        }

        // `servable()`, so an archived site's board cannot be subscribed to at
        // all. NotBroadcastForArchivedSites stops events being sent; this stops
        // the subscription existing, which matters because a subscription
        // outlives the check that created it.
        $site = Site::query()->servable()->whereKey((int) $siteId)->first();

        if ($site === null) {
            return false;
        }

        return Gate::forUser($agent)->allows('view', $site);
    }
}
