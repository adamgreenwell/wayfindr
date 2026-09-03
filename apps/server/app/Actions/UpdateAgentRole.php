<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateAgentRole
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function handle(User $actor, User $target, AccountRole|CustomRole $role): User
    {
        return DB::transaction(function () use ($actor, $target, $role): User {
            $this->siteManagerCoverage->lockAccount((int) $target->account_id);
            $users = $this->lockUsers($actor, $target);
            $actor = $this->lockedUser($users, $actor);
            $target = $this->lockedUser($users, $target);
            $role = $this->lockedRole($role, $target);

            Gate::forUser($actor)->authorize('updateRole', $target);
            $this->preventLastOwnerRemoval($target, $role);
            $this->preventSelfChange($actor, $target);

            $target->loadMissing('customRole');
            $oldRole = $target->account_role;
            $oldCustomRole = $target->customRole;
            $newAccountRole = $role instanceof CustomRole ? AccountRole::Agent : $role;
            $newCustomRoleId = $role instanceof CustomRole ? $role->id : null;

            if ($oldRole === $newAccountRole && (int) $target->custom_role_id === (int) $newCustomRoleId) {
                return $target;
            }

            $this->siteManagerCoverage->ensureAgentRoleCanChange($target, $role);

            $target->forceFill([
                'account_role' => $newAccountRole,
                'custom_role_id' => $newCustomRoleId,
            ])->save();

            AuditEvent::query()->create([
                'account_id' => $target->account_id,
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->id,
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->id,
                'action' => 'agent.role_changed',
                'metadata' => [
                    'old_role' => $oldCustomRole ? 'custom:'.$oldCustomRole->id : $oldRole->value,
                    'old_role_name' => $oldCustomRole?->name ?? $oldRole->value,
                    'new_role' => $role instanceof CustomRole ? 'custom:'.$role->id : $role->value,
                    'new_role_name' => $role instanceof CustomRole ? $role->name : $role->value,
                ],
                'occurred_at' => now(),
            ]);

            return $target->refresh();
        });
    }

    private function lockedRole(AccountRole|CustomRole $role, User $target): AccountRole|CustomRole
    {
        if ($role instanceof AccountRole) {
            return $role;
        }

        $lockedRole = CustomRole::query()
            ->whereKey($role->id)
            ->where('account_id', $target->account_id)
            ->lockForUpdate()
            ->first();

        if (! $lockedRole instanceof CustomRole) {
            throw (new ModelNotFoundException)->setModel(CustomRole::class, [$role->id]);
        }

        return $lockedRole;
    }

    /**
     * @return Collection<int, User>
     */
    private function lockUsers(User $actor, User $target): Collection
    {
        $userIds = collect([$actor->id, $target->id])
            ->unique()
            ->sort()
            ->values()
            ->all();

        return User::query()
            ->whereKey($userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
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

    private function preventSelfChange(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new AuthorizationException('Owners cannot change their own role.');
        }
    }

    private function preventLastOwnerRemoval(User $target, AccountRole|CustomRole $role): void
    {
        if ($target->account_role !== AccountRole::Owner || $role === AccountRole::Owner) {
            return;
        }

        $ownerCount = User::query()
            ->where('account_id', $target->account_id)
            ->where('account_role', AccountRole::Owner->value)
            ->count();

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'account_role' => 'Keep at least one account owner.',
            ]);
        }
    }
}
