<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Catch the tokens whose issuer was already gone when this shipped.
 *
 * Deactivation now revokes the tokens the departing agent issued, but only for
 * departures that go through it from here on. Every agent deactivated before
 * this release left their credentials live, and nothing else in the product
 * will ever notice: an API token has no session to expire and nobody to report
 * it, so the failure is open and silent rather than closed and loud.
 *
 * A COMMAND rather than a migration, and the difference matters on the
 * supported zero-downtime path. `migrate` runs before the new release is
 * activated, so a migration would finish while the previous release is still
 * serving -- and an administrator deactivating a token-holding agent in that
 * window would be served by the old code, which does not revoke. Running after
 * activation closes that, and `standard-deploy.sh` needs it for the same reason
 * it needs the session sweep: `artisan down` blocks NEW requests and cannot cut
 * off one already executing.
 *
 * Idempotent and self-limiting. Once the backlog is cleared it finds nothing on
 * every subsequent deploy, because the deactivation path revokes synchronously
 * under the same row locks. A run that DOES find something afterwards is worth
 * reading as a signal that a deactivation reached the database without going
 * through `UpdateAgentAccess`.
 */
final class DeactivatedIssuerApiTokenSweep
{
    public function __construct(private readonly IssuedApiTokenRevocation $revocation) {}

    /**
     * Revoke every live token whose issuer is deactivated.
     *
     * @return array{issuers: int, tokens: int}
     */
    public function sweep(): array
    {
        // The issuers worth loading are only those who still have something
        // live, so the set is taken from the tokens rather than from the users.
        // An install with thousands of deactivated agents and no API tokens
        // does no work here at all.
        //
        // `whereNotNull('created_by_id')` here is housekeeping, NOT the thing
        // that protects a token with no issuer: dropping it changes no
        // behaviour, because a null cannot match a user and so never becomes an
        // `$issuer` below. What actually keeps those tokens is the issuer
        // filter inside `IssuedApiTokenRevocation::revokeFor()`. Recorded
        // because a mutation of this line looks like it should break a test and
        // does not, which is worth knowing before somebody reads that as a gap.
        $issuerIds = ApiToken::query()
            ->whereNull('revoked_at')
            ->whereNotNull('created_by_id')
            ->distinct()
            ->pluck('created_by_id');

        if ($issuerIds->isEmpty()) {
            return ['issuers' => 0, 'tokens' => 0];
        }

        $issuers = User::query()
            ->whereKey($issuerIds->all())
            ->whereNotNull('deactivated_at')
            ->get();

        $tokens = 0;

        foreach ($issuers as $issuer) {
            // One transaction per issuer rather than one around the sweep. A
            // deactivated agent's credentials are independent of any other
            // agent's, so a failure part way through leaves the accounts it
            // already reached correctly revoked instead of rolling every one of
            // them back -- and the next run picks up where this stopped.
            $tokens += DB::transaction(
                // No actor: nobody is doing this, and attributing it to an
                // administrator who was not involved would put a name against a
                // decision they did not make.
                fn (): int => $this->revocation->revokeFor($issuer, null, now()),
            );
        }

        return ['issuers' => $issuers->count(), 'tokens' => $tokens];
    }
}
