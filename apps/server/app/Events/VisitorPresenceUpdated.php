<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Concerns\NotBroadcastForArchivedSites;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Visitors\LiveVisitorBoard;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody's presence on a site changed.
 *
 * `ShouldBroadcastNow` like the rest: a board that is a minute behind is a
 * list, and the product already has one of those.
 */
class VisitorPresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, NotBroadcastForArchivedSites, SerializesModels;

    public function __construct(
        public Site $site,
        public Visitor $visitor,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('sites.'.$this->site->id.'.presence')];
    }

    public function broadcastAs(): string
    {
        return 'visitor.presence.updated';
    }

    /**
     * @return array{visitor: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        // The site's CURRENT policy, so a board already open is not handed an
        // address whose collection has just been revoked.
        return ['visitor' => LiveVisitorBoard::row(
            $this->visitor,
            SitePresenceReporting::for($this->site)->pageUrls,
        )];
    }

    protected function broadcastSite(): ?Site
    {
        return $this->site;
    }
}
