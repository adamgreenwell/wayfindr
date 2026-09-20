<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Models\ApiToken;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Catch the credentials whose creator was already gone when this shipped.
 *
 * Deactivation now withdraws what a departing agent left behind, but only for
 * departures that go through it from here on. Every agent deactivated before
 * this release left their API tokens authenticating and their webhook endpoints
 * delivering, and nothing else in the product will ever notice: neither has a
 * session to expire or anybody to report it, so the failure is open and silent
 * rather than closed and loud.
 *
 * A COMMAND rather than a migration, and the difference matters on the
 * supported zero-downtime path. `migrate` runs before the new release is
 * activated, so a migration would finish while the previous release is still
 * serving -- and an administrator deactivating an agent in that window would be
 * served by the old code, which withdraws nothing. Running after activation
 * closes that, and `standard-deploy.sh` needs it for the same reason it needs
 * the session sweep: `artisan down` blocks NEW requests and cannot cut off one
 * already executing.
 *
 * Idempotent and self-limiting. Once the backlog is cleared it finds nothing on
 * every subsequent deploy, because the deactivation path withdraws
 * synchronously under the same row locks. A run that DOES find something
 * afterwards is worth reading as a signal that a deactivation reached the
 * database without going through `UpdateAgentAccess`.
 */
final class DeactivatedAgentCredentialSweep
{
    public function __construct(private readonly DepartingAgentCredentials $credentials) {}

    /**
     * Withdraw every live credential whose creator is deactivated.
     *
     * @return array{agents: int, tokens: int, endpoints: int}
     */
    public function sweep(): array
    {
        // The agents worth loading are only those who left something live, so
        // the set is taken from the credentials rather than from the users. An
        // install with thousands of deactivated agents and no integrations does
        // no work here at all.
        //
        // `whereNotNull('created_by_id')` in each half is housekeeping, NOT the
        // thing that protects a credential with no creator: dropping it changes
        // no behaviour, because a null cannot match a user and so never becomes
        // an `$agent` below. What actually keeps those rows is the creator
        // filter inside `DepartingAgentCredentials`. Recorded because a mutation
        // of those lines looks like it should break a test and does not, which
        // is worth knowing before somebody reads it as a gap.
        $creatorIds = ApiToken::query()
            ->whereNull('revoked_at')
            ->whereNotNull('created_by_id')
            ->distinct()
            ->pluck('created_by_id')
            ->merge(
                OutboundWebhookEndpoint::query()
                    ->whereNull('disabled_at')
                    ->whereNotNull('created_by_id')
                    ->distinct()
                    ->pluck('created_by_id')
            )
            ->unique()
            ->values();

        if ($creatorIds->isEmpty()) {
            return ['agents' => 0, 'tokens' => 0, 'endpoints' => 0];
        }

        $agents = User::query()
            ->whereKey($creatorIds->all())
            ->whereNotNull('deactivated_at')
            ->get();

        $tokens = 0;
        $endpoints = 0;

        foreach ($agents as $agent) {
            // One transaction per agent rather than one around the sweep. A
            // deactivated agent's credentials are independent of any other
            // agent's, so a failure part way through leaves the accounts it
            // already reached correctly withdrawn instead of rolling every one
            // of them back -- and the next run picks up where this stopped.
            $withdrawn = DB::transaction(
                // No actor: nobody is doing this, and attributing it to an
                // administrator who was not involved would put a name against a
                // decision they did not make.
                fn (): array => $this->credentials->withdrawFor($agent, null, now()),
            );

            $tokens += $withdrawn['tokens'];
            $endpoints += $withdrawn['endpoints'];
        }

        return ['agents' => $agents->count(), 'tokens' => $tokens, 'endpoints' => $endpoints];
    }
}
