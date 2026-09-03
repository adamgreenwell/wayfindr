<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgentAccountSecurityController extends Controller
{
    public function show(Request $request): View
    {
        $agent = $request->user();
        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();
        $activeAgents = $account->agents()->whereNull('deactivated_at');
        $enabledCount = (clone $activeAgents)->whereNotNull('two_factor_confirmed_at')->count();

        return view('agent.account.security', [
            'agent' => $agent,
            'account' => $account,
            'activeAgentCount' => (clone $activeAgents)->count(),
            'enabledCount' => $enabledCount,
            'missingCount' => (clone $activeAgents)->whereNull('two_factor_confirmed_at')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $agent = $request->user();
        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        $request->validate([
            'requires_two_factor' => ['nullable', 'boolean'],
        ]);
        $required = $request->boolean('requires_two_factor');
        $credentialFingerprint = PendingTwoFactorChallenge::credentialFingerprint($agent);

        DB::transaction(function () use ($agent, $required, $credentialFingerprint): void {
            $lockedAgent = User::query()->lockForUpdate()->findOrFail($agent->id);

            abort_unless(
                ! $lockedAgent->isDeactivated()
                && $lockedAgent->isAdmin()
                && (int) $lockedAgent->account_id === (int) $agent->account_id
                && hash_equals(
                    $credentialFingerprint,
                    PendingTwoFactorChallenge::credentialFingerprint($lockedAgent),
                ),
                403,
            );

            $account = Account::query()->lockForUpdate()->findOrFail($lockedAgent->account_id);

            if ($required && ! $lockedAgent->hasTwoFactorAuthentication()) {
                throw ValidationException::withMessages([
                    'requires_two_factor' => __('two_factor.policy.admin_must_enrol'),
                ]);
            }

            $before = $account->requires_two_factor;
            $account->update(['requires_two_factor' => $required]);

            if ($before === $required) {
                return;
            }

            AuditEvent::query()->create([
                'account_id' => $account->id,
                'actor_type' => $lockedAgent->getMorphClass(),
                'actor_id' => $lockedAgent->id,
                'subject_type' => $account->getMorphClass(),
                'subject_id' => $account->id,
                'action' => 'account.two_factor_policy_updated',
                'metadata' => ['required' => $required],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('dashboard.account.security.show')
            ->with('status', 'two_factor.flash.policy_updated');
    }
}
