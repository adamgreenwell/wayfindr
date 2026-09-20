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
 * id, the conversation is matched by support code AND site AND the resolved
 * visitor, and the SESSION the token names must be the one that opened it.
 *
 * That last clause is the one doing the work, and this docblock claimed the
 * property without it. Matching the visitor SCOPES a lookup; it does not
 * authorise one. An `anonymous_id` is displayed to agents and travels in the
 * widget's own requests, and bootstrap mints a valid token for anyone presenting
 * one together with a site's public key — by design, because on a
 * presence-enabled site the visitor row exists before bootstrap runs. The
 * visitor predicate was therefore satisfiable by anyone who had seen the id.
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
            $sessionId = $this->visitorSessionToken->sessionIdFromRequest($request);
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

            // Matching the visitor is not enough. A visitor's browser identity is
            // shown to agents, so a token naming that visitor can be obtained
            // without ever having been part of this conversation. What may reach
            // a conversation is the session that opened it.
            //
            // Refused with the SAME status and message as a conversation that
            // does not exist, deliberately: distinguishing them would answer
            // "does this support code belong to this visitor" for a caller who
            // cannot reach it either way.
            abort_unless(
                $this->sessionOwns($request, $conversation, $sessionId),
                $missingStatus,
                $missingMessage,
            );

            // Both principals were loaded and proved together under the same
            // site lock. Hand them to callers that need a later, exclusive
            // write-boundary reauthorization after payload validation.
            $conversation->setRelation('site', $site);
            $conversation->setRelation('visitor', $visitor);

            return $conversation;
        });
    }

    /**
     * Whether this session may act on this conversation.
     *
     * Four states, each meaning one thing.
     *
     * A DIGEST matches exactly, and only a token naming that session passes.
     *
     * The LEGACY sentinel means the conversation predates this control, and is
     * reachable by a session that could plausibly have opened it -- one that
     * began at or before the conversation did. A session cannot start after the
     * conversation it created, so every legitimate owner passes; a caller who
     * bootstrapped after the upgrade has a start later than every sentinel row
     * and cannot forge one earlier, because a session start is only carried
     * forward from a token that already proved it.
     *
     * `NO_OWNER_SESSION` means no widget session opened it -- email intake, the
     * public API -- so no widget session may reach it.
     *
     * NULL means a path that should have recorded a session did not, which is a
     * bug rather than a state to tolerate. It grants nothing, and that is what
     * caught two such bugs while this was built. It is also transient by design:
     * the post-activation sweep claims nulls left by a previous release.
     */
    private function sessionOwns(Request $request, Conversation $conversation, string $sessionId): bool
    {
        $owner = $conversation->owner_session_id;

        if ($owner === null || $owner === '' || $owner === Conversation::NO_OWNER_SESSION) {
            return false;
        }

        if ($owner !== Conversation::LEGACY_OWNER_SESSION) {
            // An empty session id cannot equal a digest, so a token naming no
            // session is refused here without needing its own branch.
            return hash_equals($owner, $sessionId);
        }

        // A token naming NO session reaches this check, deliberately.
        //
        // The only thing that presents one is a browser holding a token minted
        // before sessions were identified -- bootstrap has stamped one ever
        // since -- and during an upgrade that browser's conversation has just
        // been claimed as legacy. Refusing it here would lock an already-open
        // panel out of its own conversation until its refresh timer rotated the
        // token or the page reloaded, and an integration built on
        // `createClient()` has neither: it is handed a token, never re-bootstraps,
        // and would stay locked out until the host page reloaded.
        //
        // It concedes nothing the sentinel does not already concede. What passes
        // below is a session that began before the row, which is exactly the rule
        // for every other caller; the token cannot be forged, and no NEW token
        // lacks a session id, so this population only shrinks.

        $sessionStartedAt = $this->visitorSessionToken->sessionStartedAtFromRequest($request);

        if ($sessionStartedAt === null || $conversation->created_at === null) {
            return false;
        }

        // Floored to the second before comparing. `created_at` has second
        // precision on MySQL while a session start carries milliseconds, so an
        // honest owner whose conversation was created 200ms after their session
        // began would otherwise compare as later and be refused -- the tick
        // boundary this codebase has been bitten by before.
        return $sessionStartedAt->startOfSecond()
            ->lessThanOrEqualTo($conversation->created_at->startOfSecond());
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
