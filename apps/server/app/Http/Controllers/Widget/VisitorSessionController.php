<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Support\VisitorSessionToken;
use App\Support\WidgetSiteResolver;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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
    public function __invoke(Request $request, VisitorSessionToken $visitorSessionToken, RateLimiter $limiter): JsonResponse
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

        $this->chargeVisitorBudget($limiter, $site, $validated['anonymous_id']);

        return response()->json([
            'data' => [
                'visitor' => [
                    'anonymous_id' => $validated['anonymous_id'],
                    'token' => $token,
                    'token_expires_in' => $visitorSessionToken->expiresInSeconds($token),
                ],
            ],
        ]);
    }

    /**
     * Spend one of this visitor's refreshes -- after proving the caller is them.
     *
     * Every other widget bucket is charged by route middleware. This one cannot
     * be, and the reason is the defect this endpoint was built to close.
     * Middleware runs before any token is verified, so the only visitor
     * identifier it can key on is the caller-supplied `anonymous_id` -- a value
     * Wayfindr prints in its own dashboard and writes into access logs. Keying
     * a budget on it hands that budget to anyone who can read it: thirty junk
     * tokens a minute exhausts a stranger's allowance, and the real widget
     * starts taking 429s.
     *
     * The damage is quiet, which is what makes it worth the inversion.
     * `refreshSession()` reduces every refusal to the same failed refresh, so
     * a targeted 429 is indistinguishable from a flaky network -- and once a
     * lifetime is enforced, a visitor held at the limit simply stops being able
     * to renew, with nothing anywhere saying why.
     *
     * Charging here means `refresh()` has already rejected anyone who cannot
     * act as this visitor, so only the visitor can spend the visitor's budget.
     * The cost of that ordering is a token minted and then discarded on the
     * over-limit path, which is pure computation -- nothing is persisted, and
     * nothing is handed to the caller.
     */
    private function chargeVisitorBudget(RateLimiter $limiter, Site $site, string $anonymousId): void
    {
        $perMinute = max(1, (int) config('wayfindr.widget_rate_limits.session_refresh_per_minute', 30));

        $key = implode('|', [
            'session-visitor',
            hash('sha256', (string) $site->public_key),
            hash('sha256', $anonymousId),
        ]);

        if ($limiter->tooManyAttempts($key, $perMinute)) {
            throw new ThrottleRequestsException(
                'Too many session refreshes.',
                null,
                ['Retry-After' => (string) $limiter->availableIn($key)],
            );
        }

        $limiter->hit($key);
    }
}
