<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SiteManagerCoverage
{
    /**
     * Serialize every operation that can reduce explicit site-manager coverage.
     *
     * Call this first inside the surrounding transaction, before locking users
     * or roles, so concurrent account changes use one stable lock order.
     */
    public function lockAccount(int $accountId): void
    {
        Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    }

    /** @param list<string> $permissions */
    public function ensureRolePermissionsCanChange(CustomRole $role, array $permissions): void
    {
        if (! $role->hasPermission(AccountPermission::ManageSiteAccess)
            || in_array(AccountPermission::ManageSiteAccess->value, $permissions, true)) {
            return;
        }

        $managerIds = $role->users()
            ->whereNull('deactivated_at')
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $this->ensureSitesHaveAnotherManager((int) $role->account_id, $managerIds, 'permissions');
    }

    public function ensureAgentRoleCanChange(User $agent, AccountRole|CustomRole $newRole): void
    {
        if (! $agent->hasAccountPermission(AccountPermission::ManageSiteAccess)
            || $this->roleGrantsSiteManagement($newRole)) {
            return;
        }

        $this->ensureSitesHaveAnotherManager((int) $agent->account_id, [(int) $agent->id], 'account_role');
    }

    public function ensureAgentCanDeactivate(User $agent): void
    {
        if (! $agent->hasAccountPermission(AccountPermission::ManageSiteAccess)) {
            return;
        }

        $this->ensureSitesHaveAnotherManager((int) $agent->account_id, [(int) $agent->id], 'agent');
    }

    private function roleGrantsSiteManagement(AccountRole|CustomRole $role): bool
    {
        return $role->hasPermission(AccountPermission::ManageSiteAccess);
    }

    /**
     * @param  list<int>  $departingManagerIds
     */
    private function ensureSitesHaveAnotherManager(int $accountId, array $departingManagerIds, string $field): void
    {
        if ($departingManagerIds === []) {
            return;
        }

        $strandedSite = Site::query()
            ->where('account_id', $accountId)
            ->whereHas('supportAgents', fn ($query) => $query
                ->where('users.account_id', $accountId)
                ->whereNull('users.deactivated_at')
                ->whereKey($departingManagerIds))
            ->with(['supportAgents' => fn ($query) => $query
                ->where('users.account_id', $accountId)
                ->whereNull('users.deactivated_at')
                ->with('customRole')])
            ->get()
            ->first(fn (Site $site): bool => ! $site->supportAgents
                ->contains(fn (User $user): bool => ! in_array((int) $user->id, $departingManagerIds, true)
                    && $user->hasAccountPermission(AccountPermission::ManageSiteAccess)));

        if ($strandedSite instanceof Site) {
            throw ValidationException::withMessages([
                $field => __('account_roles.errors.site_manager_required', ['site' => $strandedSite->name]),
            ]);
        }
    }
}
