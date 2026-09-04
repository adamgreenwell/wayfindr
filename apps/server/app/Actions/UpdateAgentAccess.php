<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AgentRealtimeSessions;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateAgentAccess
{
    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly AgentRealtimeSessions $agentRealtimeSessions,
    ) {}

    public function deactivate(User $actor, User $target): User
    {
        [$target, $changed] = DB::transaction(function () use ($actor, $target): array {
            $this->siteManagerCoverage->lockAccount((int) $target->account_id);
            [$actor, $target] = $this->lockedUsers($actor, $target);

            Gate::forUser($actor)->authorize('deactivate', $target);

            if ($target->isDeactivated()) {
                return [$target, false];
            }

            $this->preventLastActiveOwnerDeactivation($target);
            $target->loadMissing('customRole');
            $this->siteManagerCoverage->ensureAgentCanDeactivate($target);

            $changedAt = now();
            $wasOnlineForRouting = $target->routing_status === User::ROUTING_STATUS_ONLINE;
            $target->forceFill([
                'deactivated_at' => $changedAt,
                // Reactivation must not silently put somebody who has not
                // signed back in into an automatic assignment rotation.
                'routing_status' => User::ROUTING_STATUS_AWAY,
                'routing_status_changed_at' => $wasOnlineForRouting
                    ? $changedAt
                    : $target->routing_status_changed_at,
            ])->save();

            $this->recordAuditEvent($actor, $target, 'agent.deactivated');

            if ($wasOnlineForRouting) {
                $this->recordAuditEvent($actor, $target, 'agent.routing_status_updated', [
                    'old_status' => User::ROUTING_STATUS_ONLINE,
                    'new_status' => User::ROUTING_STATUS_AWAY,
                    'reason' => 'deactivated',
                ]);
            }
            $this->agentRealtimeSessions->requestMany([$target->id]);

            return [$target->refresh(), true];
        });

        if ($changed) {
            $this->agentRealtimeSessions->disconnectMany([$target->id]);
        }

        return $target;
    }

    public function reactivate(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            $this->siteManagerCoverage->lockAccount((int) $target->account_id);
            [$actor, $target] = $this->lockedUsers($actor, $target);

            Gate::forUser($actor)->authorize('reactivate', $target);

            if (! $target->isDeactivated()) {
                return $target;
            }

            $target->forceFill(['deactivated_at' => null])->save();

            $this->recordAuditEvent($actor, $target, 'agent.reactivated');

            return $target->refresh();
        });
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function lockedUsers(User $actor, User $target): array
    {
        $userIds = collect([$actor->id, $target->id])
            ->unique()
            ->sort()
            ->values()
            ->all();

        $users = User::query()
            ->whereKey($userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [
            $this->lockedUser($users, $actor),
            $this->lockedUser($users, $target),
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function lockedUser(Collection $users, User $user): User
    {
        $lockedUser = $users->get($user->id);

        if (! $lockedUser instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$user->id]);
        }

        return $lockedUser;
    }

    private function preventLastActiveOwnerDeactivation(User $target): void
    {
        if (! $target->isOwner()) {
            return;
        }

        $activeOwnerCount = User::query()
            ->where('account_id', $target->account_id)
            ->where('account_role', AccountRole::Owner->value)
            ->whereNull('deactivated_at')
            ->count();

        if ($activeOwnerCount <= 1) {
            throw ValidationException::withMessages([
                'agent' => 'Keep at least one active account owner.',
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordAuditEvent(User $actor, User $target, string $action, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'account_id' => $target->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'action' => $action,
            'metadata' => $metadata + [
                'role' => $target->account_role?->value,
            ],
            'occurred_at' => now(),
        ]);
    }
}
