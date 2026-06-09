<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Support\OperatorReadiness;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AgentProfileController extends Controller
{
    public function show(Request $request, OperatorReadiness $readiness): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $mailReadiness = collect($readiness->summary()['checks'])
            ->firstWhere('key', 'mail_transport');

        return view('agent.profile.show', [
            'agent' => $agent,
            'account' => $account,
            'roleLabels' => [
                AccountRole::Owner->value => 'Owner',
                AccountRole::Admin->value => 'Admin',
                AccountRole::Agent->value => 'Agent',
            ],
            'alertMode' => $agent->alertMode(),
            'alertModeOptions' => $agent::alertModeOptions(),
            'alertCadence' => $agent->alertCadence(),
            'alertCadenceOptions' => $agent::alertCadenceOptions(),
            'mailReadiness' => $mailReadiness,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update([
            'name' => trim($validated['name']),
        ]);

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'Profile updated.');
    }

    public function updateAlertPreferences(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'alert_mode' => ['required', Rule::in(array_keys($request->user()::alertModeOptions()))],
            'alert_cadence' => ['sometimes', Rule::in(array_keys($request->user()::alertCadenceOptions()))],
        ]);

        $alertPreferences = $request->user()->alert_preferences ?? [];

        $request->user()->update([
            'alert_preferences' => array_merge($alertPreferences, [
                'mode' => $validated['alert_mode'],
                'email' => $request->boolean('email_alerts'),
                'cadence' => $validated['alert_cadence'] ?? $request->user()->alertCadence(),
            ]),
        ]);

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'Alert preferences updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $agent = $request->user();

        $agent->update([
            'password' => Hash::make($validated['password']),
        ]);

        AuditEvent::query()->create([
            'account_id' => $agent->account_id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $agent->getMorphClass(),
            'subject_id' => $agent->id,
            'action' => 'agent.password_updated',
            'metadata' => [],
            'occurred_at' => now(),
        ]);

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'Password updated.');
    }
}
