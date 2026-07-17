<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\OperatorReadiness;
use App\Support\UnattendedConversationAlertCollector;
use Carbon\CarbonImmutable;
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
            'digestDeliveryStatus' => $agent->alertDigestDeliveryStatus(),
            'mailReadiness' => $mailReadiness,
            'alertReadiness' => $this->alertReadiness($agent, $mailReadiness),
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

    /**
     * @param  array{status: string, summary: string, action: string}|null  $mailReadiness
     * @return array<int, array{label: string, status: string, tone: string, detail: string}>
     */
    private function alertReadiness(User $agent, ?array $mailReadiness): array
    {
        $alertCadence = $agent->alertCadence();
        $emailEnabled = $agent->alertEmailEnabled();
        $digestDeliveryStatus = $agent->alertDigestDeliveryStatus();

        return [
            $this->dashboardAlertReadiness($agent),
            $this->alertScopeReadiness($agent),
            $this->emailAlertReadiness($agent, $mailReadiness),
            $this->alertCadenceReadiness($alertCadence, $emailEnabled, $digestDeliveryStatus),
        ];
    }

    /**
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function dashboardAlertReadiness(User $agent): array
    {
        if ($agent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'label' => 'Dashboard alerts',
                'status' => 'Paused',
                'tone' => 'manual',
                'detail' => 'Quiet mode suppresses new dashboard and email alerts.',
            ];
        }

        return [
            'label' => 'Dashboard alerts',
            'status' => 'Listening',
            'tone' => 'ready',
            'detail' => 'You will receive dashboard alerts for eligible support work.',
        ];
    }

    /**
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function alertScopeReadiness(User $agent): array
    {
        return match ($agent->alertMode()) {
            User::ALERT_MODE_ASSIGNED => [
                'label' => 'Alert scope',
                'status' => 'Assigned to me',
                'tone' => 'ready',
                'detail' => 'Only conversations and tickets assigned to you create new alerts.',
            ],
            User::ALERT_MODE_QUIET => [
                'label' => 'Alert scope',
                'status' => 'Quiet mode',
                'tone' => 'manual',
                'detail' => 'Your scope is paused until quiet mode is turned off.',
            ],
            default => [
                'label' => 'Alert scope',
                'status' => 'All support work',
                'tone' => 'ready',
                'detail' => 'Conversations and tickets you can support can create new alerts.',
            ],
        };
    }

    /**
     * @param  array{status: string, summary: string, action: string}|null  $mailReadiness
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function emailAlertReadiness(User $agent, ?array $mailReadiness): array
    {
        if (! $agent->alertEmailEnabled()) {
            return [
                'label' => 'Email delivery',
                'status' => 'Dashboard only',
                'tone' => 'manual',
                'detail' => 'Email alerts are off for your profile.',
            ];
        }

        if (($mailReadiness['status'] ?? null) === 'ready') {
            return [
                'label' => 'Email delivery',
                'status' => 'Ready',
                'tone' => 'ready',
                'detail' => 'Email alerts are enabled and outbound mail looks configured.',
            ];
        }

        return [
            'label' => 'Email delivery',
            'status' => 'Needs setup',
            'tone' => 'attention',
            'detail' => trim(($mailReadiness['summary'] ?? 'Outbound mail is not ready.').' '.($mailReadiness['action'] ?? '')),
        ];
    }

    /**
     * @param  array{status: string, label: string, last_attempted_at: CarbonImmutable|null}  $digestDeliveryStatus
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function alertCadenceReadiness(string $alertCadence, bool $emailEnabled, array $digestDeliveryStatus): array
    {
        if ($alertCadence === User::ALERT_CADENCE_UNATTENDED) {
            return [
                'label' => 'Cadence',
                'status' => 'Unattended only',
                'tone' => $emailEnabled ? 'ready' : 'manual',
                'detail' => $emailEnabled
                    ? sprintf('Email goes out only when a visitor message stays unseen for %d minutes.', UnattendedConversationAlertCollector::THRESHOLD_MINUTES)
                    : 'Unattended preference is saved, but email alerts are off.',
            ];
        }

        if ($alertCadence !== User::ALERT_CADENCE_DIGEST) {
            return [
                'label' => 'Cadence',
                'status' => 'Immediate',
                'tone' => 'ready',
                'detail' => 'New eligible alerts can notify immediately when email alerts are enabled.',
            ];
        }

        if (! $emailEnabled) {
            return [
                'label' => 'Cadence',
                'status' => 'Digest',
                'tone' => 'manual',
                'detail' => 'Digest preference is saved, but email alerts are off.',
            ];
        }

        $latestDigest = $digestDeliveryStatus['label'];

        if ($digestDeliveryStatus['last_attempted_at']) {
            $latestDigest .= ' '.$digestDeliveryStatus['last_attempted_at']->diffForHumans();
        }

        return [
            'label' => 'Cadence',
            'status' => 'Digest',
            'tone' => match ($digestDeliveryStatus['status']) {
                User::ALERT_DIGEST_DELIVERY_FAILED => 'attention',
                User::ALERT_DIGEST_DELIVERY_QUEUED,
                User::ALERT_DIGEST_DELIVERY_NO_ALERTS => 'ready',
                default => 'manual',
            },
            'detail' => 'Digest delivery is preferred. Latest digest: '.$latestDigest.'.',
        ];
    }
}
