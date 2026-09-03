<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\User;

class UserPolicy
{
    public function createAccountAgent(User $user): bool
    {
        return ! $user->isDeactivated()
            && $user->account_id !== null
            && $user->hasAccountPermission(AccountPermission::ManageAgents);
    }

    public function updateRole(User $user, User $target): bool
    {
        return ! $user->isDeactivated()
            && $user->isOwner()
            && $this->sameAccount($user, $target);
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->manageAccess($user, $target);
    }

    public function reactivate(User $user, User $target): bool
    {
        return $this->manageAccess($user, $target);
    }

    private function manageAccess(User $user, User $target): bool
    {
        if (! $user->hasAccountPermission(AccountPermission::ManageAgents) || ! $this->sameAccount($user, $target)) {
            return false;
        }

        if ($user->is($target)) {
            return false;
        }

        return $user->isOwner()
            || ($target->account_role === AccountRole::Agent && $target->custom_role_id === null)
            || ($user->custom_role_id !== null
                && $target->custom_role_id !== null
                && (int) $user->custom_role_id === (int) $target->custom_role_id);
    }

    private function sameAccount(User $user, User $target): bool
    {
        return $user->account_id !== null
            && $target->account_id !== null
            && (int) $user->account_id === (int) $target->account_id;
    }
}
