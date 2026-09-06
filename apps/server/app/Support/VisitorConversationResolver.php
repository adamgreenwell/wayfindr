<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the one conversation a widget request is allowed to touch.
 *
 * This is the visitor-side access boundary, shared by every widget endpoint
 * that acts on a conversation so the scoping lives in exactly one audited
 * place. A request must present a site public key, an anonymous id, and a
 * signed visitor token; the token is verified against the site and anonymous
 * id, and the conversation is then matched by support code AND site AND the
 * resolved visitor. A visitor can therefore only ever reach a conversation that
 * is their own — never another session's, never another visitor's.
 */
class VisitorConversationResolver
{
    public function __construct(private VisitorSessionToken $visitorSessionToken) {}

    public function resolve(
        Request $request,
        string $supportCode,
        string $sitePublicKey,
        string $anonymousId,
        int $missingStatus = 404,
        string $missingMessage = 'Conversation not found.',
    ): Conversation {
        $site = WidgetSiteResolver::resolveOrFail($sitePublicKey);

        return DB::transaction(function () use ($request, $supportCode, $site, $anonymousId, $missingStatus, $missingMessage): Conversation {
            // Identity merge takes the exclusive partner of this lock. Keep
            // token/alias resolution and conversation matching in one stable
            // identity view so a merge cannot move the conversation between
            // those two reads and turn an authorized request into a false 404.
            $site = Site::query()
                ->servable()
                ->whereKey($site->id)
                ->sharedLock()
                ->first();
            abort_unless($site instanceof Site, 404, 'Site not found.');

            $visitor = $this->visitorSessionToken->visitorFromRequest($request, $site, $anonymousId);
            $conversation = $this->conversation($supportCode, $site, $visitor->id);

            if (! $conversation instanceof Conversation) {
                // A test double can deliberately commit a merge after token
                // resolution to prove this fallback. In production the shared
                // site lock prevents that ordering; retrying through the alias
                // is still cheap and makes the boundary robust if a caller is
                // already inside a transaction whose snapshot predates it.
                $visitor = $this->visitorSessionToken->visitorFromRequest($request, $site, $anonymousId);
                $conversation = $this->conversation($supportCode, $site, $visitor->id);
            }

            abort_unless($conversation instanceof Conversation, $missingStatus, $missingMessage);

            // Both principals were loaded and proved together under the same
            // site lock. Hand them to callers that need a later, exclusive
            // write-boundary reauthorization after payload validation.
            $conversation->setRelation('site', $site);
            $conversation->setRelation('visitor', $visitor);

            return $conversation;
        });
    }

    private function conversation(string $supportCode, Site $site, int $visitorId): ?Conversation
    {
        return Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitorId)
            ->first();
    }
}
