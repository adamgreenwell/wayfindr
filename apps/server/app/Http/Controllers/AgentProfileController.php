<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\AuditEvent;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\AgentWebPushConfig;
use App\Support\Auth\TwoFactorAuthentication;
use App\Support\DashboardLanguage;
use App\Support\DashboardTimezone;
use App\Support\OperatorReadiness;
use App\Support\Settings\OperatorSettings;
use App\Support\UnattendedConversationAlertCollector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AgentProfileController extends Controller
{
    public function show(
        Request $request,
        OperatorReadiness $readiness,
        TwoFactorAuthentication $twoFactor,
        AgentWebPushConfig $webPush,
        OperatorSettings $settings,
    ): Response {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);
        DB::transaction(function () use ($agent, $settings): void {
            // Profile cleanup is destructive, so coordinate with VAPID
            // rotation and browser enrollment before deciding which
            // subscription generation is stale.
            OperatorSetting::query()->insertOrIgnore([
                'key' => 'webpush.public_key',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            OperatorSetting::query()
                ->where('key', 'webpush.public_key')
                ->sharedLock()
                ->firstOrFail();
            $settings->refreshFromDatabase();
            AgentPushSubscription::purgeStaleFor($agent);
        });
        $agent->loadMissing('customRole');
        $agent->loadCount('pushSubscriptions');

        $account = $agent->account()->firstOrFail();
        $mailReadiness = collect($readiness->summary()['checks'])
            ->firstWhere('key', 'mail_transport');
        $pendingSecret = $this->pendingTwoFactorSecret($request);

        return response()->view('agent.profile.show', [
            'agent' => $agent,
            'account' => $account,
            'roleLabels' => [
                ...($agent->customRole ? ['custom:'.$agent->customRole->id => $agent->customRole->name] : []),
                AccountRole::Owner->value => __('profile.roles.owner'),
                AccountRole::Admin->value => __('profile.roles.admin'),
                AccountRole::Agent->value => __('profile.roles.agent'),
            ],
            'alertMode' => $agent->alertMode(),
            'alertModeOptions' => $agent::alertModeOptions(),
            'alertCadence' => $agent->alertCadence(),
            'alertCadenceOptions' => $agent::alertCadenceOptions(),
            'digestDeliveryStatus' => $agent->alertDigestDeliveryStatus(),
            'mailReadiness' => $mailReadiness,
            'alertReadiness' => $this->alertReadiness($agent, $mailReadiness, $webPush->assessment()),
            'pushAvailable' => $webPush->isReady(),
            'pushPublicKey' => $webPush->publicKeyForBrowser(),
            'twoFactorPendingSecret' => $pendingSecret,
            'twoFactorQrCode' => $pendingSecret ? $twoFactor->qrCodeDataUri($agent, $pendingSecret) : null,
            'twoFactorRecoveryCodes' => $this->pullRecoveryCodes($request),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return list<string> */
    private function pullRecoveryCodes(Request $request): array
    {
        $encrypted = $request->session()->pull(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY);

        if (! is_string($encrypted)) {
            return [];
        }

        try {
            $codes = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

            return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
        } catch (DecryptException|\JsonException) {
            return [];
        }
    }

    private function pendingTwoFactorSecret(Request $request): ?string
    {
        $encrypted = $request->session()->get(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            $request->session()->forget(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY);

            return null;
        }
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(array_keys(DashboardLanguage::SUPPORTED))],
            // Validated against the platform's zone database rather than a
            // list kept in the codebase, which would be wrong the first time
            // tzdata added or renamed one. `acceptable()` and not the canonical
            // list, so an agent already on `US/Eastern` can re-submit it.
            'timezone' => ['nullable', 'string', Rule::in(DashboardTimezone::acceptable())],
        ]);

        $request->user()->update([
            'name' => trim($validated['name']),
            // Null means "follow the install", which is what every agent had
            // before this existed and stays the safe answer.
            'locale' => DashboardLanguage::normalise($validated['locale'] ?? null),
            'timezone' => DashboardTimezone::normalise($validated['timezone'] ?? null),
        ]);

        // The KEY travels, not the sentence. This action can change the agent's
        // own language, and the flash is built here while the request is still
        // running under the language they are leaving -- so translating now
        // lands a German page carrying an English confirmation, or the reverse.
        // Translating where it is displayed makes the ordering irrelevant
        // rather than making this one ordering correct.
        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'profile.flash.profile_updated');
    }

    public function updateAlertPreferences(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'alert_mode' => ['required', Rule::in(array_keys($request->user()::alertModeOptions()))],
            'alert_cadence' => ['sometimes', Rule::in(array_keys($request->user()::alertCadenceOptions()))],
            'push_subscription_endpoint' => ['nullable', 'string', 'max:1024', 'url', 'starts_with:https://'],
        ]);

        $accountId = (int) $request->user()->account_id;
        $userId = (int) $request->user()->id;
        $email = $request->boolean('email_alerts');
        $sound = $request->boolean('sound_alerts');
        $pushRequested = $request->boolean('push_alerts');

        DB::transaction(function () use ($accountId, $email, $pushRequested, $sound, $userId, $validated): void {
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()
                ->whereKey($userId)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();
            $removedEndpoint = trim((string) ($validated['push_subscription_endpoint'] ?? ''));
            $ownedSubscriptions = fn () => AgentPushSubscription::withoutGlobalScope(
                AgentPushSubscription::CURRENT_VAPID_SCOPE,
            )
                ->where('subscribable_type', $agent->getMorphClass())
                ->where('subscribable_id', $agent->getKey());

            if ($removedEndpoint !== '') {
                $ownedSubscriptions()->where('endpoint', $removedEndpoint)->delete();
            }

            // The UI control represents this browser, while `push` remains the
            // per-agent channel preference required by the alert pipeline. One
            // browser opting out must not silence the agent's other subscribed
            // browsers; the channel turns off only after the last one leaves.
            // An environment-key generation can be transitional during a
            // rolling deploy. Preserve the agent-level channel preference on
            // unrelated saves while any owned browser generation remains.
            $push = $pushRequested || $ownedSubscriptions()->exists();
            $alertPreferences = $agent->alert_preferences ?? [];

            $agent->forceFill([
                'alert_preferences' => array_merge($alertPreferences, [
                    'mode' => $validated['alert_mode'],
                    'email' => $email,
                    'push' => $push,
                    'sound' => $sound,
                    'cadence' => $validated['alert_cadence'] ?? $agent->alertCadence(),
                ]),
            ])->save();
        });

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'profile.flash.alerts_updated');
    }

    public function updateRoutingStatus(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->account_id, 403);

        $validated = $request->validate([
            'routing_status' => ['required', Rule::in(User::routingStatuses())],
        ]);
        $accountId = (int) $request->user()->account_id;
        $userId = (int) $request->user()->id;

        DB::transaction(function () use ($accountId, $userId, $validated): void {
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()
                ->whereKey($userId)
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->firstOrFail();
            $status = $validated['routing_status'];

            if ($agent->routing_status === $status) {
                return;
            }

            $previous = $agent->routing_status ?? User::ROUTING_STATUS_AWAY;
            $changedAt = now();
            $agent->forceFill([
                'routing_status' => $status,
                'routing_status_changed_at' => $changedAt,
            ])->save();

            AuditEvent::query()->create([
                'account_id' => $accountId,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'subject_type' => $agent->getMorphClass(),
                'subject_id' => $agent->id,
                'action' => 'agent.routing_status_updated',
                'metadata' => [
                    'old_status' => $previous,
                    'new_status' => $status,
                ],
                'occurred_at' => $changedAt,
            ]);
        });

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'profile.flash.routing_status_updated');
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
            ->with('status', 'profile.flash.password_updated');
    }

    /**
     * @param  array{status: string, summary: string, action: string}|null  $mailReadiness
     * @param  array{status: string}  $pushAssessment
     * @return array<int, array{label: string, status: string, tone: string, detail: string}>
     */
    private function alertReadiness(User $agent, ?array $mailReadiness, array $pushAssessment): array
    {
        $alertCadence = $agent->alertCadence();
        $emailEnabled = $agent->alertEmailEnabled();
        $digestDeliveryStatus = $agent->alertDigestDeliveryStatus();

        return [
            $this->dashboardAlertReadiness($agent),
            $this->alertScopeReadiness($agent),
            $this->emailAlertReadiness($agent, $mailReadiness),
            $this->pushAlertReadiness($agent, $pushAssessment),
            $this->alertCadenceReadiness($alertCadence, $emailEnabled, $digestDeliveryStatus),
        ];
    }

    /**
     * @param  array{status: string}  $assessment
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function pushAlertReadiness(User $agent, array $assessment): array
    {
        if (! $agent->alertPushEnabled()) {
            return [
                'label' => __('profile.readiness_cards.push_label'),
                'status' => __('profile.readiness_cards.push_off'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.push_off_detail'),
            ];
        }

        if ($agent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'label' => __('profile.readiness_cards.push_label'),
                'status' => __('profile.readiness_cards.push_paused'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.push_paused_detail'),
            ];
        }

        if (($assessment['status'] ?? null) !== 'ready') {
            return [
                'label' => __('profile.readiness_cards.push_label'),
                'status' => __('profile.readiness_cards.push_setup'),
                'tone' => 'attention',
                'detail' => __('profile.readiness_cards.push_setup_detail'),
            ];
        }

        if ((int) $agent->push_subscriptions_count === 0) {
            return [
                'label' => __('profile.readiness_cards.push_label'),
                'status' => __('profile.readiness_cards.push_browser'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.push_browser_detail'),
            ];
        }

        return [
            'label' => __('profile.readiness_cards.push_label'),
            'status' => __('profile.readiness_cards.push_ready'),
            'tone' => 'ready',
            'detail' => trans_choice(
                'profile.readiness_cards.push_ready_detail',
                (int) $agent->push_subscriptions_count,
                ['count' => (int) $agent->push_subscriptions_count],
            ),
        ];
    }

    /**
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function dashboardAlertReadiness(User $agent): array
    {
        if ($agent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'label' => __('profile.readiness_cards.dashboard_label'),
                'status' => __('profile.readiness_cards.paused'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.quiet_detail'),
            ];
        }

        return [
            'label' => __('profile.readiness_cards.dashboard_label'),
            'status' => __('profile.readiness_cards.listening'),
            'tone' => 'ready',
            'detail' => __('profile.readiness_cards.listening_detail'),
        ];
    }

    /**
     * @return array{label: string, status: string, tone: string, detail: string}
     */
    private function alertScopeReadiness(User $agent): array
    {
        return match ($agent->alertMode()) {
            User::ALERT_MODE_ASSIGNED => [
                'label' => __('profile.readiness_cards.scope_label'),
                'status' => __('profile.readiness_cards.scope_assigned'),
                'tone' => 'ready',
                'detail' => __('profile.readiness_cards.scope_assigned_detail'),
            ],
            User::ALERT_MODE_QUIET => [
                'label' => __('profile.readiness_cards.scope_label'),
                'status' => __('profile.readiness_cards.scope_quiet'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.scope_quiet_detail'),
            ],
            default => [
                'label' => __('profile.readiness_cards.scope_label'),
                'status' => __('profile.readiness_cards.scope_all'),
                'tone' => 'ready',
                'detail' => __('profile.readiness_cards.scope_all_detail'),
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
                'label' => __('profile.readiness_cards.email_label'),
                'status' => __('profile.readiness_cards.email_off'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.email_off_detail'),
            ];
        }

        if (($mailReadiness['status'] ?? null) === 'ready') {
            return [
                'label' => __('profile.readiness_cards.email_label'),
                'status' => __('profile.readiness_cards.email_ready'),
                'tone' => 'ready',
                'detail' => __('profile.readiness_cards.email_ready_detail'),
            ];
        }

        return [
            'label' => __('profile.readiness_cards.email_label'),
            'status' => __('profile.readiness_cards.email_setup'),
            'tone' => 'attention',
            // `OperatorReadiness` supplies this sentence and its vocabulary is
            // the operator console's, which extracts with that surface -- the
            // recorded exception in docs/product/dashboard-language.md.
            //
            // It has to SAY it is English. It sits inside a page region marked
            // with the agent's language, so left unmarked a screen reader
            // pronounces the one deliberately untranslated sentence on the page
            // with German phonetics. An exception that is invisible to
            // assistive technology is not an exception, it is a defect.
            'detail_locale' => DashboardLanguage::FALLBACK,
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
                'label' => __('profile.readiness_cards.cadence_label'),
                'status' => __('profile.readiness_cards.cadence_unattended'),
                'tone' => $emailEnabled ? 'ready' : 'manual',
                'detail' => $emailEnabled
                    ? __('profile.readiness_cards.cadence_unattended_detail', ['minutes' => UnattendedConversationAlertCollector::THRESHOLD_MINUTES])
                    : __('profile.readiness_cards.cadence_unattended_off_detail'),
            ];
        }

        if ($alertCadence !== User::ALERT_CADENCE_DIGEST) {
            return [
                'label' => __('profile.readiness_cards.cadence_label'),
                'status' => __('profile.readiness_cards.cadence_immediate'),
                'tone' => 'ready',
                'detail' => __('profile.readiness_cards.cadence_immediate_detail'),
            ];
        }

        if (! $emailEnabled) {
            return [
                'label' => __('profile.readiness_cards.cadence_label'),
                'status' => __('profile.readiness_cards.cadence_digest'),
                'tone' => 'manual',
                'detail' => __('profile.readiness_cards.cadence_digest_off_detail'),
            ];
        }

        $latestDigest = $digestDeliveryStatus['label'];

        if ($digestDeliveryStatus['last_attempted_at']) {
            $latestDigest .= ' '.$digestDeliveryStatus['last_attempted_at']->diffForHumans();
        }

        return [
            'label' => __('profile.readiness_cards.cadence_label'),
            'status' => __('profile.readiness_cards.cadence_digest'),
            'tone' => match ($digestDeliveryStatus['status']) {
                User::ALERT_DIGEST_DELIVERY_FAILED => 'attention',
                User::ALERT_DIGEST_DELIVERY_QUEUED,
                User::ALERT_DIGEST_DELIVERY_NO_ALERTS => 'ready',
                default => 'manual',
            },
            'detail' => __('profile.readiness_cards.cadence_digest_detail', ['latest' => $latestDigest]),
        ];
    }
}
