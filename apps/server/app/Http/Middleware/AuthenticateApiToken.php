<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a bearer token into an account, or refuses (ADR 0018).
 *
 * The whole public surface hangs off this, so it is deliberately dull: look up
 * by hash, check it is usable, attach it to the request. No fallbacks, no
 * "try the query string too", no partial matches.
 */
class AuthenticateApiToken
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
        $presented = $request->bearerToken();

        if (! is_string($presented) || $presented === '') {
            return $this->refuse('An API token is required.', 401);
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
            return $this->refuse('That API token is not valid.', 401);
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

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
