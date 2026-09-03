<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

class AccountAlertReadiness
{
    /**
     * @param  Collection<int, User>  $agents
     * @return array{
     *     status: string,
     *     label: string,
     *     detail: string,
     *     metrics: array<int, array{label: string, value: string, tone: string}>
     * }
     */
    public function summarize(Collection $agents): array
    {
        $activeAgents = $agents->reject->isDeactivated();
        $deactivatedCount = $agents->count() - $activeAgents->count();

        $immediateEmailCount = $activeAgents
            ->filter(fn (User $agent): bool => $this->receivesImmediateEmail($agent))
            ->count();

        $digestAgents = $activeAgents
            ->filter(fn (User $agent): bool => $this->receivesDigestEmail($agent));

        $digestReadyCount = $digestAgents
            ->filter(fn (User $agent): bool => in_array($agent->alertDigestDeliveryStatus()['status'], [
                User::ALERT_DIGEST_DELIVERY_QUEUED,
                User::ALERT_DIGEST_DELIVERY_NO_ALERTS,
            ], true))
            ->count();

        $digestManualCount = $digestAgents
            ->filter(fn (User $agent): bool => $agent->alertDigestDeliveryStatus()['status'] === User::ALERT_DIGEST_DELIVERY_NOT_RUN)
            ->count();

        $attentionCount = $digestAgents
            ->filter(fn (User $agent): bool => $agent->alertDigestDeliveryStatus()['status'] === User::ALERT_DIGEST_DELIVERY_FAILED)
            ->count();

        $dashboardOnlyOrQuietCount = $activeAgents
            ->filter(fn (User $agent): bool => $agent->alertMode() === User::ALERT_MODE_QUIET || ! $agent->alertEmailEnabled())
            ->count();

        return [
            'status' => $this->status($attentionCount, $digestManualCount),
            'label' => $this->label($attentionCount, $digestManualCount),
            'detail' => $this->detail($attentionCount, $digestManualCount, $activeAgents->count()),
            'metrics' => [
                [
                    'label' => __('account.team_alert.metrics.active_agents'),
                    'value' => trans_choice('account.team_alert.metrics.active_count', $activeAgents->count(), [
                        'count' => ReaderNumber::count($activeAgents->count()),
                    ]),
                    'tone' => 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.immediate'),
                    'value' => trans_choice('account.team_alert.metrics.immediate_count', $immediateEmailCount, [
                        'count' => ReaderNumber::count($immediateEmailCount),
                    ]),
                    'tone' => 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.digest_ready'),
                    'value' => __('account.team_alert.metrics.digest_ready_count', ['count' => ReaderNumber::count($digestReadyCount)]),
                    'tone' => 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.baseline'),
                    'value' => __('account.team_alert.metrics.baseline_count', ['count' => ReaderNumber::count($digestManualCount)]),
                    'tone' => $digestManualCount > 0 ? 'manual' : 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.attention'),
                    'value' => __('account.team_alert.metrics.attention_count', ['count' => ReaderNumber::count($attentionCount)]),
                    'tone' => $attentionCount > 0 ? 'attention' : 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.dashboard_only'),
                    'value' => __('account.team_alert.metrics.dashboard_only_count', ['count' => ReaderNumber::count($dashboardOnlyOrQuietCount)]),
                    'tone' => $dashboardOnlyOrQuietCount > 0 ? 'manual' : 'ready',
                ],
                [
                    'label' => __('account.team_alert.metrics.deactivated'),
                    'value' => __('account.team_alert.metrics.deactivated_count', ['count' => ReaderNumber::count($deactivatedCount)]),
                    'tone' => $deactivatedCount > 0 ? 'manual' : 'ready',
                ],
            ],
        ];
    }

    private function receivesImmediateEmail(User $agent): bool
    {
        return $agent->alertMode() !== User::ALERT_MODE_QUIET
            && $agent->alertEmailEnabled()
            && $agent->alertCadence() === User::ALERT_CADENCE_IMMEDIATE;
    }

    private function receivesDigestEmail(User $agent): bool
    {
        return $agent->alertMode() !== User::ALERT_MODE_QUIET
            && $agent->alertEmailEnabled()
            && $agent->alertCadence() === User::ALERT_CADENCE_DIGEST;
    }

    private function status(int $attentionCount, int $digestManualCount): string
    {
        if ($attentionCount > 0) {
            return 'attention';
        }

        if ($digestManualCount > 0) {
            return 'manual';
        }

        return 'ready';
    }

    private function label(int $attentionCount, int $digestManualCount): string
    {
        if ($attentionCount > 0) {
            return trans_choice('account.team_alert.labels.attention', $attentionCount, [
                'count' => ReaderNumber::count($attentionCount),
            ]);
        }

        if ($digestManualCount > 0) {
            return trans_choice('account.team_alert.labels.baseline', $digestManualCount, [
                'count' => ReaderNumber::count($digestManualCount),
            ]);
        }

        return __('account.team_alert.labels.ready');
    }

    private function detail(int $attentionCount, int $digestManualCount, int $activeCount): string
    {
        if ($attentionCount > 0) {
            return __('account.team_alert.details.attention');
        }

        if ($digestManualCount > 0) {
            return __('account.team_alert.details.baseline');
        }

        if ($activeCount === 0) {
            return __('account.team_alert.details.none_active');
        }

        return __('account.team_alert.details.ready');
    }
}
