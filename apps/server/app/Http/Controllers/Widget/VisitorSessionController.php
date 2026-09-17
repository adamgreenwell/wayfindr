<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exchange a current visitor token for a fresh one.
 *
 * Every other way to obtain a visitor token goes through widget bootstrap,
 * which asks only for a site's public key and an anonymous id -- both values
 * the product displays or publishes by design. That left no way to shorten a
 * token's life, because nothing could re-mint one without repeating the same
 * weak proof, and a widget whose token expired mid-conversation would have no
 * recovery but to bootstrap again.
 *
 * This endpoint requires the one thing bootstrap does not: possession of a
 * token that is already valid. `VisitorSessionToken::refresh()` runs the same
 * verification every conversation endpoint runs, so a caller who cannot
 * already act as this visitor cannot obtain a token here either.
 *
 * It rotates; it does not revoke. Until a TTL exists the previous token stays
 * valid, so this is plumbing for that change rather than a control on its own.
 */
class VisitorSessionController extends Controller
{
    public function __invoke(Request $request, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        // `visitor_token` is nullable here for the same reason it is on every
        // conversation endpoint: the 401 for a missing token belongs to the
        // verifier, which produces one message for absent, wrong-site and
        // wrong-visitor alike. Rejecting it in the validator first would answer
        // a caller with 422 and tell them which of the three they got wrong.
        $token = $visitorSessionToken->refresh($request, $site, $validated['anonymous_id']);

        return response()->json([
            'data' => [
                'visitor' => [
                    'anonymous_id' => $validated['anonymous_id'],
                    'token' => $token,
                    'token_expires_at' => $visitorSessionToken->expiresAt($token)?->toJSON(),
                ],
            ],
        ]);
    }
}
