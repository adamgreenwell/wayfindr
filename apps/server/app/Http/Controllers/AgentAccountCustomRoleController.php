<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AgentAccountCustomRoleController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();
        $this->authorizeRoleManagement($actor);

        return view('agent.account.roles', [
            'account' => $actor->account()->firstOrFail(),
            'agent' => $actor,
            'permissionGroups' => $this->permissionGroups(),
            'roles' => CustomRole::query()
                ->where('account_id', $actor->account_id)
                ->withCount('users')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeRoleManagement($actor);
        $attributes = $this->validatedAttributes($request);

        $role = DB::transaction(function () use ($actor, $attributes): CustomRole {
            $this->ensureUniqueName((int) $actor->account_id, $attributes['name_key']);
            $role = CustomRole::query()->create([
                'account_id' => $actor->account_id,
                ...$attributes,
            ]);
            $this->audit($actor, $role, 'custom_role.created', [
                'role_name' => $role->name,
                'permissions' => $role->permissionValues(),
            ]);

            return $role;
        });

        return redirect()
            ->route('dashboard.account.roles.index', ['role' => $role->id])
            ->with('status', 'account_roles.flash.created');
    }

    public function update(Request $request, string $customRole): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeRoleManagement($actor);
        $attributes = $this->validatedAttributes($request);

        DB::transaction(function () use ($actor, $customRole, $attributes): void {
            $role = $this->roleForActor($actor, $customRole, true);
            $this->ensureUniqueName((int) $actor->account_id, $attributes['name_key'], (int) $role->id);
            $oldName = $role->name;
            $oldPermissions = $role->permissionValues();
            $role->fill($attributes)->save();
            $this->audit($actor, $role, 'custom_role.updated', [
                'old_role_name' => $oldName,
                'role_name' => $role->name,
                'old_permissions' => $oldPermissions,
                'permissions' => $role->permissionValues(),
            ]);
        });

        return redirect()
            ->route('dashboard.account.roles.index', ['role' => $customRole])
            ->with('status', 'account_roles.flash.updated');
    }

    public function destroy(Request $request, string $customRole): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeRoleManagement($actor);

        DB::transaction(function () use ($actor, $customRole): void {
            $role = $this->roleForActor($actor, $customRole, true);

            if ($role->users()->exists()) {
                throw ValidationException::withMessages([
                    'role' => __('account_roles.errors.assigned'),
                ]);
            }

            $this->audit($actor, $role, 'custom_role.deleted', [
                'role_name' => $role->name,
                'permissions' => $role->permissionValues(),
            ]);
            $role->delete();
        });

        return redirect()
            ->route('dashboard.account.roles.index')
            ->with('status', 'account_roles.flash.deleted');
    }

    /** @return array{name: string, name_key: string, permissions: list<string>} */
    private function validatedAttributes(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(AccountPermission::delegableValues())],
        ]);
        $name = Str::of($validated['name'])->squish()->toString();
        $permissions = collect($validated['permissions'] ?? [])
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('validation.required', ['attribute' => __('account_roles.fields.name')])]);
        }

        $this->validatePermissionDependencies($permissions);

        return [
            'name' => $name,
            'name_key' => Str::lower($name),
            'permissions' => $permissions,
        ];
    }

    /** @param list<string> $permissions */
    private function validatePermissionDependencies(array $permissions): void
    {
        $requires = [
            AccountPermission::ReplyToConversations->value => AccountPermission::ViewConversations->value,
            AccountPermission::ManageConversations->value => AccountPermission::ViewConversations->value,
            AccountPermission::RequestCobrowse->value => AccountPermission::ViewConversations->value,
            AccountPermission::AssignTickets->value => AccountPermission::ManageTickets->value,
        ];

        foreach ($requires as $permission => $required) {
            if (in_array($permission, $permissions, true) && ! in_array($required, $permissions, true)) {
                throw ValidationException::withMessages([
                    'permissions' => __('account_roles.errors.requires', [
                        'permission' => __('account_roles.permissions.'.$permission.'.label'),
                        'required' => __('account_roles.permissions.'.$required.'.label'),
                    ]),
                ]);
            }
        }
    }

    private function ensureUniqueName(int $accountId, string $nameKey, ?int $exceptId = null): void
    {
        if (in_array($nameKey, array_column(AccountRole::cases(), 'value'), true)) {
            throw ValidationException::withMessages(['name' => __('account_roles.errors.reserved')]);
        }

        $query = CustomRole::query()
            ->where('account_id', $accountId)
            ->where('name_key', $nameKey);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => __('account_roles.errors.duplicate')]);
        }
    }

    private function roleForActor(User $actor, string $roleId, bool $lock = false): CustomRole
    {
        abort_unless(ctype_digit($roleId), 404);
        $query = CustomRole::query()
            ->whereKey((int) $roleId)
            ->where('account_id', $actor->account_id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function authorizeRoleManagement(User $actor): void
    {
        abort_unless($actor->hasAccountPermission(AccountPermission::ManageRoles), 403);
    }

    /** @return array<string, list<AccountPermission>> */
    private function permissionGroups(): array
    {
        return [
            'team' => [AccountPermission::ManageAgents, AccountPermission::ManageSiteAccess],
            'support' => [AccountPermission::ViewConversations, AccountPermission::ReplyToConversations, AccountPermission::ManageConversations, AccountPermission::RequestCobrowse, AccountPermission::ManageTickets, AccountPermission::AssignTickets, AccountPermission::ViewAlerts],
            'content' => [AccountPermission::ManageKnowledge],
            'account' => [AccountPermission::ManageSites, AccountPermission::ManagePrivacySettings, AccountPermission::ManageIntegrations, AccountPermission::ManageSecurity, AccountPermission::ManageOperatorAccess, AccountPermission::ViewReports, AccountPermission::ViewAudit],
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, CustomRole $role, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $actor->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $role->getMorphClass(),
            'subject_id' => $role->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
