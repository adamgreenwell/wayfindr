<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Withdraw the standing access a departing agent created.
 *
 * Deactivation already tears down the sessions an agent holds. It did not reach
 * the two things they can leave behind that keep working without them, and
 * neither has a person or a session at one end:
 *
 * - an **API token**, which keeps authenticating until somebody revokes it;
 * - an **outbound webhook endpoint**, which keeps POSTing to a destination that
 *   agent chose, signed with the same secret, and which has no expiry column to
 *   eventually close it the way a token at least may.
 *
 * Both are handled here rather than in two places, because they are one
 * question -- "what did this person leave behind that still works?" -- and a
 * second copy of the query would be the part that silently drifted.
 *
 * They are NOT the same risk, and the difference is worth keeping in view. A
 * token is a credential the agent could carry out of the building. A webhook
 * endpoint is not: its secret lives in the subscriber system, so the exposure
 * is the destination, not the departing person. Per ADR 0020 the payload is
 * thin -- a delivery id, event name, sequence, time, site id, resource type and
 * identifier, and explicitly no transcript -- so this is an event feed reaching
 * an address nobody re-approved, not a transcript leak.
 */
final class DepartingAgentCredentials
{
    /**
     * Withdraw everything this agent left behind that still works.
     *
     * `$actor` is whoever deactivated them, or null when no person is doing
     * this -- the deploy-time sweep, which the audit trail renders as a system
     * action rather than attributing it to an administrator who was not
     * involved.
     *
     * @return array{tokens: int, endpoints: int}
     */
    public function withdrawFor(User $agent, ?User $actor, CarbonInterface $at): array
    {
        return [
            'tokens' => $this->revokeApiTokens($agent, $actor, $at),
            'endpoints' => $this->disableWebhookEndpoints($agent, $actor, $at),
        ];
    }

    private function revokeApiTokens(User $agent, ?User $actor, CarbonInterface $at): int
    {
        $tokens = ApiToken::query()
            ->where('created_by_id', $agent->getKey())
            // Already-revoked rows are left alone. Re-stamping one would move
            // the record of when the credential actually stopped working, which
            // is the single question the timestamp exists to answer.
            ->whereNull('revoked_at')
            // Locked in id order, because this takes a lock on a SET of rows
            // and two transactions locking the same set in different orders
            // deadlock. `OutboundWebhookPublisher` orders its multi-row endpoint
            // lock for this reason, and `lockedUsers()` above sorts its ids for
            // the same one. Called from `deactivate()` the exclusive account
            // lock already serialises us; the SWEEP takes no account lock, so
            // there it is the only thing imposing an order.
            ->orderBy('id')
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

            $this->audit($token, $agent, $actor, $at, 'api_token.revoked_with_issuer', [
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toJSON(),
            ]);
        }

        return $tokens->count();
    }

    private function disableWebhookEndpoints(User $agent, ?User $actor, CarbonInterface $at): int
    {
        $endpoints = OutboundWebhookEndpoint::query()
            ->where('created_by_id', $agent->getKey())
            ->whereNull('disabled_at')
            // Same reason, and this is the table it matters most on: the
            // publisher locks these rows too, in id order.
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($endpoints as $endpoint) {
            // Cancel what is still queued BEFORE stamping the endpoint, which
            // is the order the administrator's manual disable uses and the
            // order `DeliverOutboundWebhook` documents as the contract: disable
            // "locks the endpoint only long enough to stop publishers, then
            // cancels this row".
            //
            // Not a security step -- the job re-reads the endpoint and refuses
            // a disabled one, so nothing escapes either way. It is a
            // housekeeping one: without it those rows sit in a non-terminal
            // state, the retry backoff keeps waking for work that can never
            // succeed, and the operator's delivery log reports them as still
            // pending rather than as stopped when the agent left.
            $endpoint->deliveries()
                ->whereNull('delivered_at')
                ->whereNull('failed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => $at]);

            $endpoint->forceFill(['disabled_at' => $at])->save();

            // Name only, which is what `outbound_webhook.created` and the
            // manual `outbound_webhook.disabled` both record.
            //
            // NOT the destination, though an earlier version of this recorded
            // it as "what an administrator needs in order to decide whether to
            // re-enable". `OutboundWebhookEndpoint` casts `url` as `encrypted`,
            // so the product treats a destination as sensitive at rest -- and
            // `audit_events.metadata` is a plain array cast. Copying it here
            // would take a value the model encrypts and store it beside the
            // ciphertext in clear.
            //
            // Not via the CSV export, which an earlier version of this comment
            // claimed: that writes six named columns and metadata is not among
            // them. The exposure is at rest -- every database dump from then
            // on, including ADR 0010's offsite push -- which is precisely what
            // the `encrypted` cast exists to prevent. The administrator reads
            // the destination from the integrations page, where it is shown.
            $this->audit($endpoint, $agent, $actor, $at, 'outbound_webhook.disabled_with_creator', [
                'name' => $endpoint->name,
            ]);
        }

        return $endpoints->count();
    }

    /**
     * Record one withdrawal against the credential itself.
     *
     * The CREDENTIAL is the subject, not the agent, so this lands in that row's
     * own history beside the entry that created it. ADR 0018 already settles
     * this direction for tokens: actions are "audited against the token rather
     * than its issuer".
     *
     * Each gets its own action rather than the manual one carrying a reason in
     * metadata. Somebody asking why a credential stopped working needs to tell
     * an administrator's deliberate click from an automatic consequence of
     * offboarding -- and audit metadata renders nowhere today, so a distinction
     * that lives only there is one nobody investigating can see.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        Model $credential,
        User $agent,
        ?User $actor,
        CarbonInterface $at,
        string $action,
        array $metadata,
    ): void {
        AuditEvent::query()->create([
            'account_id' => $credential->account_id,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $credential->getMorphClass(),
            'subject_id' => $credential->getKey(),
            'action' => $action,
            // Never the credential itself -- not a token hash, not a signing
            // secret. The audit log is exportable, and a record that a
            // credential existed must not be a copy of it.
            'metadata' => $metadata + [
                'issuer_id' => $agent->getKey(),
                'issuer_name' => $agent->name,
            ],
            'occurred_at' => $at,
        ]);
    }
}
