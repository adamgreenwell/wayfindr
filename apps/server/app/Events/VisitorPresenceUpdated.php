<?php

declare(strict_types=1);

namespace App\Events;

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
    use Dispatchable, SerializesModels;

    /**
     * The site as the DATABASE has it, read once and reused.
     *
     * Both the archived check and the page-address policy were answered from
     * `$this->site`, which is the model the request resolved on its way in. A
     * revocation or an archive committing between the heartbeat's own commit
     * and this dispatch left that model describing a world that no longer
     * exists -- and the board was handed the result.
     *
     * One read serves both questions, and it is a primary-key select on a path
     * that is already making a network call to Reverb.
     */
    private ?Site $currentSite = null;

    private bool $currentSiteRead = false;

    public function __construct(
        public Site $site,
        public Visitor $visitor,
    ) {}

    private function currentSite(): ?Site
    {
        if (! $this->currentSiteRead) {
            $this->currentSiteRead = true;
            $this->currentSite = Site::query()->whereKey($this->site->getKey())->first();
        }

        return $this->currentSite;
    }

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
        // address whose collection has just been revoked. A site that has gone
        // entirely withholds, which is the same direction every other unknown
        // fails in here.
        $current = $this->currentSite();

        return ['visitor' => LiveVisitorBoard::row(
            $this->visitor,
            $current !== null && SitePresenceReporting::for($current)->pageUrls,
            // This channel is shared by conversation and ticket roles. A
            // broadcast cannot tailor a payload per subscriber, so support
            // history totals stay in permission-scoped page snapshots only.
            false,
        )];
    }

    /**
     * Three ways this event stops being anybody's business.
     *
     * Written out rather than taken from `NotBroadcastForArchivedSites`,
     * because that trait answers only the first of them and a class method
     * silently overriding a trait's is how a guard quietly stops checking.
     *
     * The third is the one that was missing. A heartbeat committing just
     * before `updatePresence()` takes the site lock is a legitimate write --
     * `stamp()` saw reporting on under its own lock and wrote the row. The
     * revocation then commits, deletes the presence-only visitors it
     * collected, and `announce()` one statement later broadcast a row that no
     * longer exists. An open board put that visitor back on screen, page
     * address and all, seconds after the operator revoked the collection that
     * produced them.
     */
    public function broadcastWhen(): bool
    {
        $site = $this->currentSite();

        return $site !== null
            && ! $site->isArchived()
            && SitePresenceReporting::for($site)->enabled;
    }
}
