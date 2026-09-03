<?php

namespace App\Http\Controllers;

use App\Actions\UpdateAgentRole;
use App\Enums\AccountRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgentAccountAgentRoleController extends Controller
{
    public function __invoke(Request $request, User $agent, UpdateAgentRole $updateAgentRole): RedirectResponse
    {
        $validated = $request->validate([
            'account_role' => ['required', Rule::in(array_column(AccountRole::cases(), 'value'))],
        ]);

        $updateAgentRole->handle($request->user(), $agent, AccountRole::from($validated['account_role']));

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', 'account.flash.role_updated');
    }
}
