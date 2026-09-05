<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Facades\DB;

/** Re-authorize a widget conversation write at its locked commit boundary. */
final class VisitorConversationWriteAuthorization
{
    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly VisitorIdentityResolver $identities,
    ) {}

    public function lock(Conversation $resolved, string $anonymousId): Conversation
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Visitor write authorization requires a transaction.');

        $resolved->loadMissing('site');
        $site = $resolved->site;
        abort_unless($site instanceof Site, 404, 'Site not found.');

        // Match the account -> site -> subject order used by account/site
        // mutations and by identity merge. The shared site lock makes the
        // alias, visitor, and conversation below one stable identity view.
        $this->siteManagerCoverage->lockAccount((int) $site->account_id);
        $site = Site::query()
            ->servable()
            ->whereKey($resolved->site_id)
            ->sharedLock()
            ->first();
        abort_unless($site instanceof Site, 404, 'Site not found.');

        $visitor = $this->identities->forAnonymousId((int) $site->id, $anonymousId);
        abort_unless($visitor instanceof Visitor, 404, 'Conversation not found.');

        $visitor = Visitor::query()
            ->whereKey($visitor->id)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->first();
        abort_unless($visitor instanceof Visitor, 404, 'Conversation not found.');

        $conversation = Conversation::query()
            ->whereKey($resolved->id)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->lockForUpdate()
            ->first();
        abort_unless($conversation instanceof Conversation, 404, 'Conversation not found.');

        $conversation->setRelation('site', $site);
        $conversation->setRelation('visitor', $visitor);

        return $conversation;
    }
}
