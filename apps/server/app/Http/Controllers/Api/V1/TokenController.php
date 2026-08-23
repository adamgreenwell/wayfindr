<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this token is and what it can reach.
 *
 * The first call anyone makes, and the one that turns "403, but why" into an
 * answer. It reports the token's own reach rather than the account's, so a
 * restricted token can see that it is restricted -- which is the thing you need
 * when an integration is returning fewer rows than you expected.
 */
class TokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);
        $token = $scope->token;

        return response()->json([
            'data' => [
                'name' => (string) $token->name,
                'account_id' => $scope->accountId(),
                'abilities' => array_values((array) $token->abilities),
                // The sites this token can actually reach, already intersected
                // with the account -- not the restriction as configured.
                'site_ids' => $scope->siteIds(),
                'expires_at' => $token->expires_at?->toJSON(),
                'created_at' => $token->created_at?->toJSON(),
            ],
        ]);
    }
}
