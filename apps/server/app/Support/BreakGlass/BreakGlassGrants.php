<?php

namespace App\Support\BreakGlass;

use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The break-glass grant lifecycle (ADR 0008): request -> approve/self-approve
 * or deny -> close/expire. Every transition happens under a row lock (a grant
 * must never be approved twice or closed-and-approved concurrently) and leaves
 * a break_glass.* audit event whose metadata names scope and reason — never
 * content.
 */
class BreakGlassGrants
{
    public function request(User $requester, Conversation|Site|Account $scope, string $reason, int $minutes = BreakGlassGrant::DEFAULT_MINUTES): BreakGlassGrant
    {
        abort_unless($requester->isPlatformOperator(), 403);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A break-glass request needs a written reason.',
            ]);
        }

        if ($minutes < 1 || $minutes > BreakGlassGrant::MAX_MINUTES) {
            throw ValidationException::withMessages([
                'requested_minutes' => sprintf('Grant duration must be between 1 minute and %d minutes.', BreakGlassGrant::MAX_MINUTES),
            ]);
        }

        [$accountId, $scopeType, $conversationId, $siteId] = match (true) {
            $scope instanceof Conversation => [
                $scope->loadMissing('site')->site?->account_id,
                BreakGlassGrant::SCOPE_CONVERSATION,
                $scope->id,
                $scope->site_id,
            ],
            $scope instanceof Site => [$scope->account_id, BreakGlassGrant::SCOPE_SITE, null, $scope->id],
            $scope instanceof Account => [$scope->id, BreakGlassGrant::SCOPE_ACCOUNT, null, null],
        };

        if ($accountId === null) {
            throw new InvalidArgumentException('The requested scope does not resolve to an account.');
        }

        // Grant and audit event commit together — a request that fails to
        // leave a trail must not leave a grant either.
        return DB::transaction(function () use ($accountId, $scopeType, $conversationId, $siteId, $requester, $reason, $minutes): BreakGlassGrant {
            $grant = BreakGlassGrant::query()->create([
                'account_id' => $accountId,
                'scope_type' => $scopeType,
                'conversation_id' => $conversationId,
                'site_id' => $siteId,
                'requester_id' => $requester->id,
                'reason' => $reason,
                'status' => BreakGlassGrant::STATUS_REQUESTED,
                'requested_minutes' => $minutes,
            ]);

            $this->audit($grant, $requester, 'break_glass.requested');

            return $grant;
        });
    }

    /**
     * Approve a requested grant. When the target account has an owner/admin
     * other than the requester, only such a person may approve (two-party
     * control). Otherwise the requester self-approves: the grant is marked
     * self-approved and the account-visible record carries it (ADR 0008 —
     * "ceremony is not security when both hats are one head; visibility is").
     */
    public function approve(BreakGlassGrant $grant, User $approver): BreakGlassGrant
    {
        return DB::transaction(function () use ($grant, $approver): BreakGlassGrant {
            $locked = BreakGlassGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === BreakGlassGrant::STATUS_REQUESTED, 409, 'This grant is not awaiting approval.');

            $selfApproval = (int) $approver->id === (int) $locked->requester_id;

            if ($selfApproval) {
                // Self-approval exists for the single-human install where the
                // operator IS the account's owner/admin. An operator with no
                // admin standing on the account has no consent to give — even
                // (especially) when the account has no active approver left.
                abort_unless(
                    (int) $approver->account_id === (int) $locked->account_id
                        && ! $approver->isDeactivated()
                        && $approver->isAdmin(),
                    403,
                    'Self-approval requires owner or admin standing on the target account.',
                );

                abort_if(
                    $this->eligibleApprovers($locked)->isNotEmpty(),
                    403,
                    'This account has an owner or admin who must approve break-glass access.',
                );
            } else {
                abort_unless($this->isEligibleApprover($locked, $approver), 403);
            }

            $approvedAt = now();

            $locked->forceFill([
                'status' => BreakGlassGrant::STATUS_ACTIVE,
                'approver_id' => $approver->id,
                'self_approved' => $selfApproval,
                'approved_at' => $approvedAt,
                'expires_at' => $approvedAt->copy()->addMinutes($locked->requested_minutes),
            ])->save();

            $this->audit($locked, $approver, $selfApproval ? 'break_glass.self_approved' : 'break_glass.approved');

            return $locked;
        });
    }

    public function deny(BreakGlassGrant $grant, User $approver): BreakGlassGrant
    {
        return DB::transaction(function () use ($grant, $approver): BreakGlassGrant {
            $locked = BreakGlassGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === BreakGlassGrant::STATUS_REQUESTED, 409, 'This grant is not awaiting approval.');
            abort_unless($this->isEligibleApprover($locked, $approver), 403);

            $locked->forceFill([
                'status' => BreakGlassGrant::STATUS_DENIED,
                'approver_id' => $approver->id,
                'closed_at' => now(),
            ])->save();

            $this->audit($locked, $approver, 'break_glass.denied');

            return $locked;
        });
    }

    /**
     * Close an active grant early. The requester or any eligible approver may
     * close; closing is always allowed (revoking access can never be the wrong
     * move) but only ever transitions an active grant.
     */
    public function close(BreakGlassGrant $grant, User $actor): BreakGlassGrant
    {
        return DB::transaction(function () use ($grant, $actor): BreakGlassGrant {
            $locked = BreakGlassGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === BreakGlassGrant::STATUS_ACTIVE, 409, 'Only an active grant can be closed.');
            abort_unless(
                (int) $actor->id === (int) $locked->requester_id || $this->isEligibleApprover($locked, $actor),
                403,
            );

            // In the gap between expires_at and the scheduled sweep the row
            // still says active, but the grant already expired — record THAT
            // terminal state; the trail must never say "closed early" about a
            // grant that ran out.
            if (! $locked->expires_at->isFuture()) {
                $locked->forceFill(['status' => BreakGlassGrant::STATUS_EXPIRED])->save();

                $this->audit($locked, null, 'break_glass.expired');

                return $locked;
            }

            $locked->forceFill([
                'status' => BreakGlassGrant::STATUS_CLOSED,
                'closed_at' => now(),
            ])->save();

            $this->audit($locked, $actor, 'break_glass.closed');

            return $locked;
        });
    }

    /**
     * Stamp overdue active grants as expired (the scheduled sweep). isActive()
     * already refuses past-expiry grants live; this records the terminal state
     * and its audit event.
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        BreakGlassGrant::query()
            ->where('status', BreakGlassGrant::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($grants) use (&$expired): void {
                foreach ($grants as $grant) {
                    DB::transaction(function () use ($grant, &$expired): void {
                        $locked = BreakGlassGrant::query()->whereKey($grant->getKey())->lockForUpdate()->first();

                        if (! $locked || $locked->status !== BreakGlassGrant::STATUS_ACTIVE || $locked->expires_at->isFuture()) {
                            return;
                        }

                        $locked->forceFill(['status' => BreakGlassGrant::STATUS_EXPIRED])->save();
                        $this->audit($locked, null, 'break_glass.expired');
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    /**
     * Owners/admins of the grant's account, excluding the requester and the
     * deactivated — the people who can approve, deny, or close it.
     *
     * @return Collection<int, User>
     */
    public function eligibleApprovers(BreakGlassGrant $grant): Collection
    {
        return User::query()
            ->where('account_id', $grant->account_id)
            ->whereNull('deactivated_at')
            ->when($grant->requester_id !== null, fn ($query) => $query->whereKeyNot($grant->requester_id))
            ->get()
            ->filter(fn (User $user): bool => $user->isAdmin())
            ->values();
    }

    private function isEligibleApprover(BreakGlassGrant $grant, User $user): bool
    {
        return (int) $user->account_id === (int) $grant->account_id
            && ! $user->isDeactivated()
            && $user->isAdmin()
            && (int) $user->id !== (int) $grant->requester_id;
    }

    private function audit(BreakGlassGrant $grant, ?User $actor, string $action): void
    {
        $grant->auditEvents()->create([
            'account_id' => $grant->account_id,
            // Deliberately not site-homed: audit_events.site_id cascades on
            // site deletion, and the break-glass trail must outlive the
            // content it records. The scope is named in metadata instead.
            'site_id' => null,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => [
                'scope_type' => $grant->scope_type,
                'scope_label' => $grant->scopeLabel(),
                'reason' => $grant->reason,
                'requested_minutes' => $grant->requested_minutes,
                'requester' => $grant->requester()->value('name'),
                'self_approved' => $grant->self_approved,
                'expires_at' => $grant->expires_at?->toJSON(),
            ],
            'occurred_at' => now(),
        ]);
    }
}
