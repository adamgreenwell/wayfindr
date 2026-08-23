<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer token into an account, or refuses (ADR 0018).
 *
 * The whole public surface hangs off this, so it is deliberately dull: look up
 * by hash, check it is usable, attach it to the request. No fallbacks, no
 * "try the query string too", no partial matches.
 *
 * **`AuthenticatesRequests` is load-bearing, not decoration.** It is an empty
 * marker interface, and its only job is Laravel's middleware priority list,
 * where it sits immediately before `ThrottleRequests`. Without it this
 * middleware is unprioritised, the route's throttles sort ahead of it, and the
 * per-token rate limiter runs before there is a token to key on -- so every
 * token behind one address shares one bucket, which is the opposite of what
 * per-token limiting is for. Nothing about the code reads as broken when that
 * happens; only a test with two tokens shows it.
 */
class AuthenticateApiToken implements AuthenticatesRequests
{
    /**
     * The attribute the rest of the API reads its caller from. Never
     * `$request->user()`: a token is not a user, and code that reads it as one
     * would attribute a machine read to whichever agent happened to issue the
     * credential.
     */
    public const ATTRIBUTE = 'api_token';

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        // Failed attempts are bounded here rather than by a route throttle, and
        // that is forced rather than chosen: this middleware is prioritised
        // ahead of `ThrottleRequests` (see above), so a route throttle placed
        // before it does not stay before it. The middleware that refuses is
        // the one that can count refusals.
        //
        // Checked BEFORE the lookup, so an address that has burned its budget
        // costs nothing further. Only failures spend it, so a working
        // integration never touches this no matter how much traffic it sends.
        $probeKey = 'api-failed-auth:'.(string) $request->ip();
        $maxFailures = max(1, (int) config('wayfindr.api_failed_auth_per_minute', 60));

        if (RateLimiter::tooManyAttempts($probeKey, $maxFailures)) {
            return $this->refuse('Too many failed authentication attempts.', 429);
        }

        $presented = $request->bearerToken();

        if (! is_string($presented) || $presented === '') {
            return $this->fail($probeKey, 'An API token is required.');
        }

        // Looked up by hash rather than compared row by row. The hash is the
        // unique index, so this is one indexed read regardless of how many
        // tokens the install has.
        $token = ApiToken::query()
            ->where('token_hash', ApiToken::hash($presented))
            ->first();

        // Revoked and expired are refused with the same words as unknown. A
        // caller learning that their token *used to* work learns something
        // about the account; a caller who mistyped learns nothing either way.
        if ($token === null || ! $token->isUsable()) {
            return $this->fail($probeKey, 'That API token is not valid.');
        }

        // Written before the response, not after, so a request refused for any
        // reason below still records that the credential was used. `last_used_at`
        // exists to tell a live token from a forgotten one, and a token being
        // used unsuccessfully is exactly as interesting as one being used well.
        //
        // Only touched at minute granularity: a busy integration would
        // otherwise write to this row on every request to move a timestamp
        // nobody reads that precisely.
        if ($token->last_used_at === null || $token->last_used_at->lessThan(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        if ($ability !== null && ! $token->hasAbility($ability)) {
            return $this->refuse('That API token does not have the '.$ability.' ability.', 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }

    /**
     * Refuse, and spend one of this address's failure budget.
     *
     * A 403 for a missing ability is deliberately NOT counted: the credential
     * was genuine, so it is a misconfigured integration rather than somebody
     * looking for a token that works.
     */
    private function fail(string $probeKey, string $message): JsonResponse
    {
        RateLimiter::hit($probeKey);

        return $this->refuse($message, 401);
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
