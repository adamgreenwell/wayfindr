<?php

namespace App\Http\Controllers;

use App\Actions\UpdateAgentRole;
use App\Enums\AccountRole;
use App\Models\CustomRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentAccountAgentRoleController extends Controller
{
    public function __invoke(Request $request, User $agent, UpdateAgentRole $updateAgentRole): RedirectResponse
    {
        $actor = $request->user();
        $customRoles = CustomRole::query()
            ->where('account_id', $actor->account_id)
            ->get()
            ->keyBy(fn (CustomRole $role): string => 'custom:'.$role->id);
        $allowedRoles = [
            ...array_column(AccountRole::cases(), 'value'),
            ...$customRoles->keys()->all(),
        ];
        $validated = $request->validate([
            'account_role' => ['required', Rule::in($allowedRoles)],
        ]);
        $role = AccountRole::tryFrom($validated['account_role'])
            ?? $customRoles->get($validated['account_role']);

        abort_unless($role instanceof AccountRole || $role instanceof CustomRole, 404);

        $updateAgentRole->handle($actor, $agent, $role);

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', 'account.flash.role_updated');
    }
}
