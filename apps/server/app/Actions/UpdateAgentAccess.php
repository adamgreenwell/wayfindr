<?php

namespace App\Actions;

use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateAgentAccess
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function deactivate(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target): User {
            $this->siteManagerCoverage->lockAccount((int) $target->account_id);
            [$actor, $target] = $this->lockedUsers($actor, $target);

            Gate::forUser($actor)->authorize('deactivate', $target);

            if ($target->isDeactivated()) {
                return $target;
            }

            $this->preventLastActiveOwnerDeactivation($target);
            $target->loadMissing('customRole');
            $this->siteManagerCoverage->ensureAgentCanDeactivate($target);

            $target->forceFill(['deactivated_at' => now()])->save();

            $this->recordAuditEvent($actor, $target, 'agent.deactivated');

            return $target->refresh();
        });
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

    private function recordAuditEvent(User $actor, User $target, string $action): void
    {
        AuditEvent::query()->create([
            'account_id' => $target->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'action' => $action,
            'metadata' => [
                'role' => $target->account_role?->value,
            ],
            'occurred_at' => now(),
        ]);
    }
}
