<?php

namespace App\Events\Concerns;

use App\Models\Site;

/**
 * Stops an event reaching a widget whose site has been archived.
 *
 * Gating the HTTP entry points is not enough on its own. A visitor who already
 * had the widget open holds an authorized realtime subscription, and nothing
 * re-checks the site once that subscription exists - so an agent reply would
 * still arrive in a widget belonging to a site the dashboard says is retired.
 *
 * Fail-closed: an event whose site cannot be resolved is not broadcast.
 */
trait NotBroadcastForArchivedSites
{
    public function broadcastWhen(): bool
    {
        $site = $this->broadcastSite();

        return $site !== null && ! $site->isArchived();
    }

    abstract protected function broadcastSite(): ?Site;
}
