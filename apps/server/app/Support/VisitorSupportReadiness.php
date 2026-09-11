<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\OperatorReadinessConfirmation;
use App\Models\Site;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class VisitorSupportReadiness
{
    /**
     * Two audiences, and until now one verdict.
     *
     * Four of these checks are account configuration -- sites, the widget,
     * masking, a first conversation -- and the account owner fixes them. Three
     * are instance operations: Reverb, the queue driver, cron. On a self-hosted
     * install the bootstrap makes the first user both account owner AND platform
     * operator, so nobody noticed that the second group was being counted into
     * the first group's verdict.
     *
     * Anywhere the two roles are separate -- an agency running this for a
     * client, and EVERY customer of a hosted Wayfindr, permanently -- that put
     * three items with no link and no route to resolution on the first screen,
     * under a headline the reader could not move.
     *
     * So the headline now describes the account, which is the only thing its
     * reader owns, and the instance group carries its own status alongside it
     * rather than inside it. Not hidden: a queue silently degrading an
     * operator's alerts is exactly what an owner needs to see, they just cannot
     * be the one to fix it.
     *
     * The split is by responsibility, not by what this particular reader can
     * act on. Those are different lines and only the first one is stable:
     * `privacy_masking` is already unactionable for an agent without
     * ManagePrivacySettings, and it stays an account check regardless, because
     * the person who resolves it is in the account.
     *
     * @param  Collection<int, Site>  $sites
     * @param  array{status: string}  $realtimeHealth
     * @return array{
     *     account: array{attention_count: int, checks: array<int, array<string, mixed>>, manual_count: int, ready_count: int},
     *     instance: array{attention_count: int, checks: array<int, array<string, mixed>>, label: string, manual_count: int, owned: bool, ready_count: int, status: string},
     *     label: string,
     *     status: string
     * }
     */
    public function summary(Collection $sites, array $realtimeHealth, bool $canViewReadiness = false, bool $canManagePrivacy = false): array
    {
        $account = [
            $this->siteConnected($sites),
            $this->widgetCheckIn($sites),
            $this->privacyMasking($sites, $canManagePrivacy),
            $this->testConversation($sites),
        ];

        $instance = [
            $this->realtimeDelivery($realtimeHealth, $canViewReadiness),
            $this->queueWorker($canViewReadiness),
            $this->scheduler($canViewReadiness),
        ];

        return [
            'account' => $this->tally($account),
            'instance' => [
                ...$this->tally($instance),
                'label' => match ($this->verdict($instance)) {
                    'ready' => $canViewReadiness ? 'Instance is ready' : 'Nothing to ask your operator for',
                    'manual' => $canViewReadiness ? 'Instance needs confirming' : 'Ask your operator to confirm one thing',
                    default => $canViewReadiness ? 'Instance needs attention' : 'Ask your operator to look at this',
                },
                'owned' => $canViewReadiness,
                'status' => $this->verdict($instance),
            ],
            'label' => match ($this->verdict($account)) {
                'ready' => 'Ready for visitors',
                'manual' => 'Nearly ready',
                default => 'Needs attention',
            },
            'status' => $this->verdict($account),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array{attention_count: int, checks: array<int, array<string, mixed>>, manual_count: int, ready_count: int}
     */
    private function tally(array $checks): array
    {
        return [
            'attention_count' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'attention')),
            'checks' => $checks,
            'manual_count' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'manual')),
            'ready_count' => count(array_filter($checks, fn (array $check): bool => $check['status'] === 'ready')),
        ];
    }

    /**
     * Manual work is its own state, as it is in OperatorReadiness::dogfoodSummary().
     * A two-way label would claim readiness while the list below it still says
     * something needs confirming.
     *
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function verdict(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'attention') {
                return 'attention';
            }
        }

        foreach ($checks as $check) {
            if ($check['status'] === 'manual') {
                return 'manual';
            }
        }

        return 'ready';
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
                action: 'Add more sites when you are ready to support more properties.',
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
                action: $canViewReadiness
                    ? 'Reverb should restart on deploy so long-running workers pick up new code.'
                    : 'Nothing to do. Your operator keeps this running.',
                href: $canViewReadiness ? route('operator.dashboard') : null
            );
        }

        return $this->check(
            key: 'realtime_delivery',
            label: 'Set up realtime delivery',
            status: 'attention',
            summary: 'Realtime delivery needs setup.',
            detail: 'Live chat can fall back to manual refresh, but Reverb should be configured before real visitor traffic.',
            action: $canViewReadiness
                ? 'Reverb credentials and the public host setting must be complete before live updates work.'
                : 'Ask your operator to finish the realtime configuration. Live chat falls back to manual refresh until then.',
            href: $canViewReadiness ? route('operator.dashboard') : null
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
                action: $canViewReadiness
                    ? 'Queues must run on database or redis with a worker process, or alerts and background work are not durable.'
                    : 'Ask your operator to move queues onto a durable worker. Support alerts are not reliable until they do.',
                href: $canViewReadiness ? route('operator.dashboard') : null
            );
        }

        return $this->check(
            key: 'queue_worker',
            label: 'Move queues out of sync mode',
            status: 'ready',
            summary: "Queue driver is {$connection}.",
            detail: 'Background work can leave the request lifecycle.',
            action: $canViewReadiness
                ? 'A queue worker must be running for background work to leave the request.'
                : 'Nothing to do. Your operator keeps the worker running.',
            href: $canViewReadiness ? route('operator.dashboard') : null
        );
    }

    /**
     * Wayfindr cannot see cron from inside a request, so this one is settled by
     * a person -- but it IS settled. /operator records the attestation, and
     * until now this panel ignored it and hard-returned `manual`, which is why
     * the old single verdict could never reach "Ready for visitors" no matter
     * what anyone did. A check nobody can ever clear teaches its reader to
     * ignore the panel.
     *
     * The confirming operator's NAME is deliberately not surfaced here. In a
     * hosted Wayfindr that person works for the platform, not for the account
     * reading this screen; the account needs to know the scheduler is confirmed
     * and roughly how recently, not who to email.
     *
     * @return array{action: string, detail: string, href: string|null, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function scheduler(bool $canViewReadiness): array
    {
        $confirmedAt = $this->schedulerConfirmedAt();

        if ($confirmedAt !== null) {
            return $this->check(
                key: 'scheduler',
                label: 'Confirm scheduler job',
                status: 'ready',
                summary: 'Scheduler was confirmed '.$confirmedAt->diffForHumans().'.',
                detail: 'An operator attested that cron runs the Laravel scheduler every minute.',
                action: $canViewReadiness
                    ? 'Re-confirm after changing hosts, cron, or the process manager.'
                    : 'Nothing to do. Your operator re-confirms this periodically.',
                href: $canViewReadiness ? route('operator.dashboard') : null
            );
        }

        return $this->check(
            key: 'scheduler',
            label: 'Confirm scheduler job',
            status: 'manual',
            summary: 'Scheduler has not been confirmed.',
            detail: 'Wayfindr cannot see cron from inside the app, so this one has to be confirmed by hand.',
            action: $canViewReadiness
                ? 'The Laravel scheduler must run every minute: * * * * * php artisan schedule:run'
                : 'Ask your operator to confirm the Laravel scheduler runs every minute.',
            href: $canViewReadiness ? route('operator.dashboard') : null
        );
    }

    /**
     * Null when absent, unreadable, or stale. Wrapped because this runs on the
     * agent home: the table is missing before the first migration, and a
     * readiness panel must not be the thing that 500s a fresh install.
     */
    private function schedulerConfirmedAt(): ?CarbonInterface
    {
        try {
            if (! Schema::hasTable('operator_readiness_confirmations')) {
                return null;
            }

            $confirmedAt = OperatorReadinessConfirmation::query()
                ->where('key', 'scheduler')
                ->value('confirmed_at');
        } catch (Throwable) {
            return null;
        }

        if (! $confirmedAt instanceof CarbonInterface) {
            return null;
        }

        $staleAfterDays = OperatorReadiness::CONFIRMATION_STALE_AFTER_DAYS['scheduler'] ?? null;

        if ($staleAfterDays !== null && $confirmedAt->lt(now()->subDays($staleAfterDays))) {
            return null;
        }

        return $confirmedAt;
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
                href: route('dashboard.conversations.index')
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
                'manual' => 'Confirm this',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }
}
