<?php

namespace App\Http\Controllers;

use App\Actions\UpdateAgentAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AgentAccountAgentAccessController extends Controller
{
    public function deactivate(Request $request, User $agent, UpdateAgentAccess $updateAgentAccess): RedirectResponse
    {
        $updateAgentAccess->deactivate($request->user(), $agent);

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', 'account.flash.deactivated');
    }

    public function reactivate(Request $request, User $agent, UpdateAgentAccess $updateAgentAccess): RedirectResponse
    {
        $updateAgentAccess->reactivate($request->user(), $agent);

        return redirect()
            ->route('dashboard.account.show')
            ->with('status', 'account.flash.reactivated');
    }
}
