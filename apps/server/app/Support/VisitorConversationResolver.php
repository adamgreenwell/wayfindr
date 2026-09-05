<?php

namespace App\Support;

use App\Models\Conversation;
use Illuminate\Http\Request;

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
    ): Conversation {
        $site = WidgetSiteResolver::resolveOrFail($sitePublicKey);

        $visitor = $this->visitorSessionToken->visitorFromRequest($request, $site, $anonymousId);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_unless($conversation, 404, 'Conversation not found.');

        // Both principals were already loaded and proved together. Hand them
        // to the caller so downstream validation/scanning does not re-query a
        // visitor row that an identity merge may remove before the locked
        // write boundary re-authorizes the current canonical identity.
        $conversation->setRelation('site', $site);
        $conversation->setRelation('visitor', $visitor);

        return $conversation;
    }
}
