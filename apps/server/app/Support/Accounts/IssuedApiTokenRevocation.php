<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Stop the programmatic access an agent issued, when that agent is offboarded.
 *
 * Deactivation already tears down the sessions an agent holds. A token they
 * created has neither a session nor a person at one end, so before this it kept
 * authenticating until somebody revoked it by hand -- a departing agent's
 * programmatic access outliving their access to the dashboard.
 *
 * One class rather than the same query written twice, because there are two
 * callers that must not drift: the deactivation itself, and the sweep that
 * catches agents deactivated before this shipped. The interesting part is not
 * the UPDATE, it is the set it is taken over and the audit row beside it, and
 * that is exactly the part a second copy would get subtly wrong.
 */
final class IssuedApiTokenRevocation
{
    /**
     * Revoke every unrevoked token this agent issued.
     *
     * `$actor` is whoever deactivated them, or null when no person is doing
     * this -- the deploy-time sweep, which the audit trail renders as a system
     * action rather than attributing to an administrator who was not involved.
     *
     * @return int how many tokens this revoked
     */
    public function revokeFor(User $issuer, ?User $actor, CarbonInterface $at): int
    {
        // Keyed on the issuer alone rather than issuer-and-account. A user
        // belongs to exactly one account and nothing in the product moves them
        // between accounts, so the two name the same set today. If that ever
        // stops being true, the safe direction is revoking a token this person
        // issued elsewhere -- not leaving one live because it was filed under
        // an account nobody was looking at.
        //
        // Note this deliberately does NOT reach tokens whose `created_by_id` is
        // null. A null issuer is a token whose creator's row is gone entirely,
        // which is nobody's departure: sweeping those in would disable an
        // account's integrations because an unrelated agent left.
        $tokens = ApiToken::query()
            ->where('created_by_id', $issuer->getKey())
            // Already-revoked rows are left alone. Re-stamping one would move
            // the record of when the credential actually stopped working, which
            // is the single question the timestamp exists to answer.
            ->whereNull('revoked_at')
            // `lockForUpdate` is the contract, not a precaution. ADR 0018
            // settles concurrent writes by serializing them on the token's own
            // row and says revocation takes that same lock, "which leaves a
            // clean ordering: either the write commits before revocation, or
            // the revoked token is refused before it changes anything". Without
            // it, an in-flight API write could commit against a credential this
            // has already decided is dead.
            ->lockForUpdate()
            ->get();

        foreach ($tokens as $token) {
            $token->forceFill(['revoked_at' => $at])->save();

            AuditEvent::query()->create([
                'account_id' => $token->account_id,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                // The TOKEN is the subject, so this lands in that credential's
                // own history beside the row that issued it.
                'subject_type' => $token->getMorphClass(),
                'subject_id' => $token->getKey(),
                // Its own action rather than `api_token.revoked` carrying a
                // reason in metadata. Somebody asking why a credential stopped
                // working needs to tell an administrator's deliberate click
                // from an automatic consequence of offboarding -- and audit
                // metadata renders nowhere today, so a distinction that lives
                // only there is one nobody investigating can see.
                'action' => 'api_token.revoked_with_issuer',
                // Never the token or its hash: the audit log is exportable, and
                // a record that a credential existed must not be a copy of it.
                'metadata' => [
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toJSON(),
                    'issuer_id' => $issuer->getKey(),
                    'issuer_name' => $issuer->name,
                ],
                'occurred_at' => $at,
            ]);
        }

        return $tokens->count();
    }
}
