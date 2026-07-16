<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassGrant;
use App\Models\User;
use App\Support\BreakGlass\BreakGlassGrants;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The account side of break-glass (ADR 0008, slice 2): owners/admins review,
 * approve, deny, and revoke platform-operator access requests that touch
 * their account. Lifecycle rules live in the BreakGlassGrants service.
 */
class AgentAccountBreakGlassController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();

        $grants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->with(['requester', 'approver', 'conversation', 'site'])
            ->latest('id')
            ->limit(50)
            ->get();

        return view('agent.account.break-glass', [
            'account' => $account,
            'agent' => $agent,
            'pendingGrants' => $grants->where('status', BreakGlassGrant::STATUS_REQUESTED)->values(),
            'activeGrants' => $grants->filter(fn (BreakGlassGrant $grant): bool => $grant->isActive())->values(),
            'pastGrants' => $grants
                ->reject(fn (BreakGlassGrant $grant): bool => $grant->status === BreakGlassGrant::STATUS_REQUESTED || $grant->isActive())
                ->take(15)
                ->values(),
        ]);
    }

    public function approve(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grant = $grants->approve($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            ->with('status', sprintf('Access to %s approved until %s.', $grant->scopeLabel(), $grant->expires_at->format('H:i T')));
    }

    public function deny(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grants->deny($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            ->with('status', 'Request denied. No access was granted.');
    }

    public function close(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grant = $grants->close($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            ->with('status', $grant->status === BreakGlassGrant::STATUS_EXPIRED
                ? 'That grant had already expired; it is recorded as expired.'
                : 'Grant closed. Access is revoked.');
    }

    private function accountAdmin(Request $request): User
    {
        $agent = $request->user();

        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        return $agent;
    }

    private function accountGrant(Request $request, BreakGlassGrant $grant): User
    {
        $agent = $this->accountAdmin($request);

        abort_unless((int) $grant->account_id === (int) $agent->account_id, 404);

        return $agent;
    }
}
