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

        // Open grants (pending + active) are never capped — an approval queue
        // or revoke button must not scroll out of existence behind newer
        // history rows. Only the terminal history takes a display limit.
        $openGrants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_REQUESTED, BreakGlassGrant::STATUS_ACTIVE])
            ->with(['requester', 'approver', 'conversation', 'site'])
            ->latest('id')
            ->get();

        $terminalGrants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_DENIED, BreakGlassGrant::STATUS_CLOSED, BreakGlassGrant::STATUS_EXPIRED])
            ->with(['requester', 'approver', 'conversation', 'site'])
            ->latest('id')
            ->limit(15)
            ->get();

        // A status-active row past its expiry (the sweep gap) is already
        // history — it must not vanish between the buckets.
        $overdueGrants = $openGrants->filter(
            fn (BreakGlassGrant $grant): bool => $grant->status === BreakGlassGrant::STATUS_ACTIVE && ! $grant->isActive(),
        );

        return view('agent.account.break-glass', [
            'account' => $account,
            'agent' => $agent,
            'pendingGrants' => $openGrants->where('status', BreakGlassGrant::STATUS_REQUESTED)->values(),
            'activeGrants' => $openGrants->filter(fn (BreakGlassGrant $grant): bool => $grant->isActive())->values(),
            'pastGrants' => $overdueGrants
                ->concat($terminalGrants)
                ->sortByDesc('id')
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
