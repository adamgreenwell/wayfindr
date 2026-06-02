<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Collection;

class VisitorSupportReadiness
{
    /**
     * @param  Collection<int, Site>  $sites
     * @param  array{status: string}  $realtimeHealth
     * @return array{
     *     attention_count: int,
     *     checks: array<int, array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}>,
     *     label: string,
     *     manual_count: int,
     *     ready_count: int
     * }
     */
    public function summary(Collection $sites, array $realtimeHealth, bool $canViewReadiness = false, bool $canManagePrivacy = false): array
    {
        $checks = [
            $this->siteConnected($sites),
            $this->widgetCheckIn($sites),
            $this->privacyMasking($sites, $canManagePrivacy),
            $this->realtimeDelivery($realtimeHealth, $canViewReadiness),
            $this->queueWorker($canViewReadiness),
            $this->scheduler($canViewReadiness),
            $this->testConversation($sites),
        ];

        $attentionCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'attention'));
        $manualCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'manual'));
        $readyCount = count(array_filter($checks, fn (array $check): bool => $check['status'] === 'ready'));

        return [
            'attention_count' => $attentionCount,
            'checks' => $checks,
            'label' => $attentionCount > 0 ? 'Needs attention' : 'Ready enough to dogfood',
            'manual_count' => $manualCount,
            'ready_count' => $readyCount,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function siteConnected(Collection $sites): array
    {
        if ($sites->isNotEmpty()) {
            return $this->check(
                key: 'site_connected',
                label: 'Connect a site',
                status: 'ready',
                summary: $sites->count().' visible '.str('site')->plural($sites->count()).' connected.',
                detail: 'Wayfindr has at least one support site to serve.',
                action: 'Add additional sites when you are ready to dogfood more properties.',
                href: route('dashboard.sites.index')
            );
        }

        return $this->check(
            key: 'site_connected',
            label: 'Connect a site',
            status: 'attention',
            summary: 'No visible sites yet.',
            detail: 'Agents need a site record before the widget can be installed.',
            action: 'Add the first site and copy its widget snippet.',
            href: route('dashboard.sites.create')
        );
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function widgetCheckIn(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return $this->check(
                key: 'widget_check_in',
                label: 'Confirm widget check-in',
                status: 'attention',
                summary: 'No site can check in yet.',
                detail: 'Install health appears after the widget loads from a connected site.',
                action: 'Create a site first, then load its tester or public page.',
                href: route('dashboard.sites.create')
            );
        }

        $sitesNeedingAttention = $sites
            ->filter(fn (Site $site): bool => SiteInstallHealth::fromVisitor($site->latestVisitor)['needs_attention'])
            ->count();

        if ($sitesNeedingAttention === 0) {
            return $this->check(
                key: 'widget_check_in',
                label: 'Confirm widget check-in',
                status: 'ready',
                summary: 'Widget check-in is fresh.',
                detail: 'Every visible site has checked in recently.',
                action: 'Keep an eye on stale installs before sending real visitors there.',
                href: route('dashboard.sites.index').'#site-install-health'
            );
        }

        return $this->check(
            key: 'widget_check_in',
            label: 'Confirm widget check-in',
            status: 'attention',
            summary: $sitesNeedingAttention.' '.str('site')->plural($sitesNeedingAttention).' need install attention.',
            detail: 'At least one visible site has not checked in recently.',
            action: 'Open the site settings, copy the snippet, then run the tester or public page.',
            href: route('dashboard.sites.index').'#site-install-health'
        );
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function privacyMasking(Collection $sites, bool $canManagePrivacy): array
    {
        if ($sites->isEmpty()) {
            return $this->check(
                key: 'privacy_masking',
                label: 'Configure privacy masking',
                status: 'attention',
                summary: 'No site privacy settings yet.',
                detail: 'Mask selectors are site-level public configuration for cobrowse safety.',
                action: $canManagePrivacy
                    ? 'Create a site before configuring masking.'
                    : 'Ask an account owner or admin to create a site and configure masking.',
                href: $canManagePrivacy ? route('dashboard.sites.create') : null
            );
        }

        $sitesWithoutMasks = $sites
            ->filter(fn (Site $site): bool => $this->maskSelectors($site) === [])
            ->count();

        if ($sitesWithoutMasks === 0) {
            return $this->check(
                key: 'privacy_masking',
                label: 'Configure privacy masking',
                status: 'ready',
                summary: 'Privacy masking has selectors configured.',
                detail: 'Every visible site has at least one mask selector before cobrowse starts.',
                action: 'Use the tester to confirm fake sensitive fields stay masked.',
                href: route('dashboard.sites.index')
            );
        }

        return $this->check(
            key: 'privacy_masking',
            label: 'Configure privacy masking',
            status: 'attention',
            summary: $sitesWithoutMasks.' '.str('site')->plural($sitesWithoutMasks).' need mask selectors.',
            detail: 'Cobrowse should have masking rules before teams rely on it with real visitors.',
            action: $canManagePrivacy
                ? 'Add selectors such as input[type="password"] and [data-wayfindr-mask].'
                : 'Ask an account owner or admin to add mask selectors before cobrowse is used with real visitors.',
            href: $canManagePrivacy ? route('dashboard.sites.index') : null
        );
    }

    /**
     * @param  array{status: string}  $realtimeHealth
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function realtimeDelivery(array $realtimeHealth, bool $canViewReadiness): array
    {
        if ($realtimeHealth['status'] === 'ready') {
            return $this->check(
                key: 'realtime_delivery',
                label: 'Set up realtime delivery',
                status: 'ready',
                summary: 'Realtime delivery is configured.',
                detail: 'Reverb is ready for live chat and cobrowse updates.',
                action: 'Keep reverb:restart in the deploy script so long-running workers refresh.',
                href: $canViewReadiness ? route('dashboard.readiness.show') : null
            );
        }

        return $this->check(
            key: 'realtime_delivery',
            label: 'Set up realtime delivery',
            status: 'attention',
            summary: 'Realtime delivery needs setup.',
            detail: 'Live chat can fall back, but Reverb should be configured before serious dogfooding.',
            action: 'Review Reverb credentials and public host settings.',
            href: $canViewReadiness ? route('dashboard.readiness.show') : null
        );
    }

    /**
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function queueWorker(bool $canViewReadiness): array
    {
        $connection = (string) config('queue.default', 'sync');

        if (in_array($connection, ['null', 'sync'], true)) {
            return $this->check(
                key: 'queue_worker',
                label: 'Move queues out of sync mode',
                status: 'attention',
                summary: "Queue driver is {$connection}.",
                detail: 'Synchronous queues are fine locally, but support alerts and background work need a durable worker in production.',
                action: 'Use database or redis queues and run a queue worker.',
                href: $canViewReadiness ? route('dashboard.readiness.show') : null
            );
        }

        return $this->check(
            key: 'queue_worker',
            label: 'Move queues out of sync mode',
            status: 'ready',
            summary: "Queue driver is {$connection}.",
            detail: 'Background work can leave the request lifecycle.',
            action: 'Confirm a queue worker is running under Forge, Supervisor, systemd, or your host.',
            href: $canViewReadiness ? route('dashboard.readiness.show') : null
        );
    }

    /**
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function scheduler(bool $canViewReadiness): array
    {
        return $this->check(
            key: 'scheduler',
            label: 'Confirm scheduler job',
            status: 'manual',
            summary: 'Scheduler must be confirmed outside the request.',
            detail: 'Wayfindr cannot prove cron from the dashboard, but operators should run the Laravel scheduler once per minute.',
            action: 'Configure * * * * * php artisan schedule:run, then keep this on the operator checklist.',
            href: $canViewReadiness ? route('dashboard.readiness.show') : null
        );
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function testConversation(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return $this->check(
                key: 'test_conversation',
                label: 'Run a first test conversation',
                status: 'attention',
                summary: 'No site can receive a conversation yet.',
                detail: 'A site is required before the tester can send a message.',
                action: 'Create a site, then send a message from its tester.',
                href: route('dashboard.sites.create')
            );
        }

        $siteIds = $sites->pluck('id')->all();
        $hasConversation = Conversation::query()
            ->whereIn('site_id', $siteIds)
            ->exists();

        if ($hasConversation) {
            return $this->check(
                key: 'test_conversation',
                label: 'Run a first test conversation',
                status: 'ready',
                summary: 'A conversation has landed in Wayfindr.',
                detail: 'The widget-to-agent loop has produced at least one support record.',
                action: 'Use support codes and tickets to keep future checks traceable.',
                href: route('dashboard').'#conversations'
            );
        }

        $firstSite = $sites->first();

        return $this->check(
            key: 'test_conversation',
            label: 'Run a first test conversation',
            status: 'attention',
            summary: 'No conversations have landed yet.',
            detail: 'Before real visitors depend on this, send a message from the tester.',
            action: 'Open tester and send a safe test message.',
            href: $firstSite ? route('dashboard.sites.tester', $firstSite) : null
        );
    }

    /**
     * @return array<int, string>
     */
    private function maskSelectors(Site $site): array
    {
        $selectors = $site->settings['mask_selectors'] ?? [];

        return is_array($selectors) ? array_values(array_filter($selectors, 'is_string')) : [];
    }

    /**
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function check(string $key, string $label, string $status, string $summary, string $detail, string $action, ?string $href): array
    {
        return [
            'action' => $action,
            'detail' => $detail,
            'href' => $href,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => 'Ready',
                'manual' => 'Manual check',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }
}
