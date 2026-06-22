<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AccountAlertReadiness;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\TicketExternalIssueState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AgentAccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $visibleSiteIds = $account->sites()
            ->visibleToAgent($agent)
            ->pluck('sites.id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->all();

        $agents = $account->agents()
            ->withCount([
                'assignedConversations as visible_open_conversations_count' => fn ($query) => $query
                    ->where('status', 'open')
                    ->whereIn('site_id', $visibleSiteIds),
                'assignedTickets as visible_open_tickets_count' => fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('status', 'open')
                    ->whereIn('site_id', $visibleSiteIds),
            ])
            ->orderByRaw(
                'case account_role when ? then 0 when ? then 1 else 2 end',
                [AccountRole::Owner->value, AccountRole::Admin->value],
            )
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        $visibleSites = $account->sites()
            ->visibleToAgent($agent)
            ->with(['supportAgents' => fn ($query) => $query
                ->where('users.account_id', $account->id)
                ->whereNull('users.deactivated_at')
                ->orderByRaw(
                    'case account_role when ? then 0 when ? then 1 else 2 end',
                    [AccountRole::Owner->value, AccountRole::Admin->value],
                )
                ->orderBy('name')
                ->orderBy('email')])
            ->orderBy('name')
            ->get();

        $fallbackSites = $visibleSites
            ->filter(fn ($site): bool => $site->supportAgents->isEmpty())
            ->values();

        $agentSupportScopes = $agents->mapWithKeys(fn ($accountAgent): array => [
            $accountAgent->id => [
                'explicitSites' => $visibleSites
                    ->filter(fn ($site): bool => $site->supportAgents->contains('id', $accountAgent->id))
                    ->values(),
                'fallbackSites' => $accountAgent->isDeactivated() ? collect() : $fallbackSites,
            ],
        ]);

        return view('agent.account.show', [
            'account' => $account,
            'accountActivity' => $this->accountActivityItems($account, $visibleSiteIds),
            'agent' => $agent,
            'agentAlertReadinessSummary' => $agent->isAdmin()
                ? app(AccountAlertReadiness::class)->summarize($agents)
                : null,
            'agentAlertDeliverySummaries' => $agents->mapWithKeys(fn (User $accountAgent): array => [
                $accountAgent->id => $this->agentAlertDeliverySummary($accountAgent),
            ]),
            'agents' => $agents,
            'agentSupportScopes' => $agentSupportScopes,
            'activeAgentCount' => $agents->reject->isDeactivated()->count(),
            'canCreateAgents' => $agent->isAdmin(),
            'canViewExternalIssueReadiness' => $agent->isAdmin(),
            'canViewAlertDelivery' => $agent->isAdmin(),
            'canManageAgentAccess' => $agent->isAdmin(),
            'canManageRoles' => $agent->isOwner(),
            'canViewAudit' => $agent->isAdmin(),
            'externalIssueReadiness' => $agent->isAdmin()
                ? $this->externalIssueReadiness($account, $visibleSiteIds)
                : null,
            'roleLabels' => $this->roleLabels(),
            'roleOptions' => $this->roleLabels(),
            'siteCount' => $account->sites()->count(),
            'supportAssignmentCount' => $agentSupportScopes
                ->sum(fn (array $scope): int => $scope['explicitSites']->count()),
            'visibleSites' => $visibleSites,
            'visibleSiteCount' => count($visibleSiteIds),
        ]);
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     detail: string,
     *     metrics: array<int, array{label: string, value: string, tone: string, href?: string|null, action?: string}>,
     *     projects: Collection<int, array{
     *         site: string,
     *         provider: string,
     *         connection: string,
     *         project_key: string,
     *         project_name: string|null,
     *         capabilities: list<string>,
     *         handoff: array{label: string, detail: string, tone: string},
     *         href: string,
     *         enabled: bool
     *     }>,
     *     recent_failures: Collection<int, array{provider: string, project_key: string, status: string|null, occurred_at: Carbon|null}>
     * }
     */
    private function externalIssueReadiness(Account $account, array $visibleSiteIds): array
    {
        $connections = $account->externalIssueProviderConnections()
            ->where(function ($query) use ($visibleSiteIds): void {
                $query
                    ->whereDoesntHave('siteProjects')
                    ->orWhereHas('siteProjects', fn ($projectQuery) => $projectQuery->whereIn('site_id', $visibleSiteIds));
            })
            ->orderBy('name')
            ->get();
        $projects = $account->siteExternalIssueProjects()
            ->whereIn('site_id', $visibleSiteIds)
            ->with(['providerConnection', 'site'])
            ->get()
            ->sortBy(fn (SiteExternalIssueProject $project): string => ($project->site?->name ?? '').' '.$project->project_key)
            ->values();
        $statusCounts = $account->ticketExternalLinks()
            ->whereIn('site_id', $visibleSiteIds)
            ->selectRaw('sync_status, count(*) as aggregate')
            ->groupBy('sync_status')
            ->pluck('aggregate', 'sync_status');
        $queueStateCounts = TicketExternalIssueState::countsForQuery(
            Ticket::query()
                ->where('account_id', $account->id)
                ->whereIn('site_id', $visibleSiteIds)
        );
        $visibleFailureEvents = fn () => $account->auditEvents()
            ->where('action', 'ticket.external_sync_failed')
            ->whereIn('site_id', $visibleSiteIds);

        $disabledCount = $connections
            ->where('is_enabled', false)
            ->count();
        $failedCount = max(
            (int) ($statusCounts[ExternalIssueSyncStatus::FAILED] ?? 0),
            $visibleFailureEvents()->count(),
        );
        $pendingCount = (int) ($statusCounts[ExternalIssueSyncStatus::PENDING] ?? 0);
        $failedQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::FAILED] ?? 0);
        $pendingQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::PENDING] ?? 0);

        [$label, $tone, $detail] = match (true) {
            $connections->isEmpty() => [
                'Not configured',
                'manual',
                'Add a provider connection when tickets need to leave Wayfindr.',
            ],
            $projects->isEmpty() => [
                'Not configured',
                'manual',
                'Map at least one site project before tickets can leave Wayfindr.',
            ],
            $disabledCount > 0 || $failedCount > 0 => [
                'Needs attention',
                'attention',
                'Review disabled connections or failed syncs before relying on external handoff.',
            ],
            $pendingCount > 0 => [
                'Sync pending',
                'manual',
                'Some external links are still waiting for confirmation.',
            ],
            default => [
                'Ready',
                'ready',
                'External issue routing has mapped projects and no failed syncs.',
            ],
        };

        return [
            'label' => $label,
            'tone' => $tone,
            'detail' => $detail,
            'metrics' => [
                [
                    'label' => 'Provider connections',
                    'value' => $this->countLabel($connections->count(), 'provider connection'),
                    'tone' => $connections->isEmpty() ? 'manual' : 'ready',
                ],
                [
                    'label' => 'Mapped projects',
                    'value' => $this->countLabel($projects->count(), 'mapped project'),
                    'tone' => $projects->isEmpty() ? 'manual' : 'ready',
                ],
                [
                    'label' => 'Disabled',
                    'value' => $this->countLabel($disabledCount, 'disabled', 'disabled'),
                    'tone' => $disabledCount > 0 ? 'attention' : 'ready',
                ],
                [
                    'label' => 'Sync failed',
                    'value' => $this->countLabel($failedCount, 'sync failed', 'sync failed'),
                    'tone' => $failedCount > 0 ? 'attention' : 'ready',
                    'href' => $failedQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_external' => 'failed',
                        ])
                        : null,
                    'action' => 'Review failed tickets',
                ],
                [
                    'label' => 'Sync pending',
                    'value' => $this->countLabel($pendingCount, 'sync pending', 'sync pending'),
                    'tone' => $pendingCount > 0 ? 'manual' : 'ready',
                    'href' => $pendingQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_external' => 'pending',
                        ])
                        : null,
                    'action' => 'Review pending tickets',
                ],
            ],
            'projects' => $projects->map(fn (SiteExternalIssueProject $project): array => [
                'site' => $project->site?->name ?? 'Unknown site',
                'provider' => $project->providerLabel(),
                'connection' => $project->providerConnection?->name ?? $project->providerLabel(),
                'project_key' => $project->project_key,
                'project_name' => $project->project_name,
                'capabilities' => $project->capabilityLabels(),
                'handoff' => $project->issueCreationHandoffState(),
                'href' => $project->site
                    ? route('dashboard.sites.show', $project->site).'#external-issue-routing-heading'
                    : route('dashboard.sites.index'),
                'enabled' => (bool) $project->providerConnection?->is_enabled,
            ]),
            'recent_failures' => $visibleFailureEvents()
                ->latest('occurred_at')
                ->latest('id')
                ->limit(3)
                ->get()
                ->map(fn (AuditEvent $event): array => [
                    'provider' => ExternalIssueProvider::label(data_get($event->metadata, 'provider')),
                    'project_key' => (string) (data_get($event->metadata, 'project_key') ?? 'Unknown project'),
                    'status' => data_get($event->metadata, 'status')
                        ? 'Status '.data_get($event->metadata, 'status')
                        : null,
                    'occurred_at' => $event->occurred_at,
                ]),
        ];
    }

    private function countLabel(int $count, string $singular, ?string $plural = null): string
    {
        if ($plural !== null) {
            return $count.' '.($count === 1 ? $singular : $plural);
        }

        return $count.' '.Str::plural($singular, $count);
    }

    /**
     * @return array{primary: string, lines: array<int, array{text: string, tone?: string}>}
     */
    private function agentAlertDeliverySummary(User $accountAgent): array
    {
        if ($accountAgent->isDeactivated()) {
            return [
                'primary' => 'Deactivated',
                'lines' => [
                    ['text' => 'Alert delivery is paused while access is suspended.'],
                ],
            ];
        }

        if ($accountAgent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'primary' => 'Quiet mode',
                'lines' => [
                    ['text' => 'New dashboard and email alerts are paused.'],
                ],
            ];
        }

        [$scopeLabel, $scopeDetail] = $this->agentAlertScopeSummary($accountAgent);

        if (! $accountAgent->alertEmailEnabled()) {
            return [
                'primary' => 'Email off',
                'lines' => [
                    ['text' => $scopeLabel, 'tone' => 'manual'],
                    ['text' => $scopeDetail],
                ],
            ];
        }

        if ($accountAgent->alertCadence() === User::ALERT_CADENCE_DIGEST) {
            $digestDeliveryStatus = $accountAgent->alertDigestDeliveryStatus();
            $digestDeliveryTone = match ($digestDeliveryStatus['status']) {
                User::ALERT_DIGEST_DELIVERY_FAILED => 'attention',
                User::ALERT_DIGEST_DELIVERY_NOT_RUN => 'manual',
                default => 'ready',
            };
            $lines = [
                ['text' => $scopeLabel, 'tone' => 'ready'],
                ['text' => $digestDeliveryStatus['label'], 'tone' => $digestDeliveryTone],
                ['text' => $digestDeliveryStatus['message']],
            ];

            if ($digestDeliveryStatus['last_attempted_at']) {
                $lines[] = ['text' => 'Last attempt '.$digestDeliveryStatus['last_attempted_at']->diffForHumans()];
            }

            return [
                'primary' => 'Digest',
                'lines' => $lines,
            ];
        }

        return [
            'primary' => 'Immediate',
            'lines' => [
                ['text' => $scopeLabel, 'tone' => 'ready'],
                ['text' => 'Email alerts as they happen.'],
                ['text' => $scopeDetail],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function agentAlertScopeSummary(User $accountAgent): array
    {
        if ($accountAgent->alertMode() === User::ALERT_MODE_ASSIGNED) {
            return [
                'Assigned-only',
                'Dashboard alerts only for assigned conversations and tickets.',
            ];
        }

        return [
            'All support work',
            'Dashboard alerts can come from any site work this agent can support.',
        ];
    }

    /**
     * @return Collection<int, array{label: string, actor: string, subject: string, body: string, occurred_at: Carbon|null}>
     */
    private function accountActivityItems(Account $account, array $visibleSiteIds): Collection
    {
        return $account->auditEvents()
            ->with(['actor', 'subject'])
            ->whereIn('action', $this->accountActivityActions())
            ->where(function ($query) use ($visibleSiteIds): void {
                $query->where('action', '!=', 'site_access.updated');

                if ($visibleSiteIds !== []) {
                    $query->orWhere(function ($siteAccessQuery) use ($visibleSiteIds): void {
                        $siteAccessQuery
                            ->where('action', 'site_access.updated')
                            ->whereIn('site_id', $visibleSiteIds);
                    });
                }
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'label' => $this->accountActivityLabel($event),
                'actor' => $this->accountActivityActor($event),
                'subject' => $this->accountActivitySubject($event),
                'body' => $this->accountActivityBody($event),
                'occurred_at' => $event->occurred_at,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function accountActivityActions(): array
    {
        return [
            'agent.created',
            'agent.deactivated',
            'agent.password_updated',
            'agent.reactivated',
            'agent.role_changed',
            'site_access.updated',
        ];
    }

    private function accountActivityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => 'Agent created',
            'agent.deactivated' => 'Agent deactivated',
            'agent.password_updated' => 'Password changed',
            'agent.reactivated' => 'Agent reactivated',
            'agent.role_changed' => 'Agent role changed',
            'site_access.updated' => 'Site access updated',
            default => 'Account activity',
        };
    }

    private function accountActivityActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        return 'System';
    }

    private function accountActivitySubject(AuditEvent $event): string
    {
        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
        }

        return 'Account';
    }

    private function accountActivityBody(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => 'Created agent account',
            'agent.deactivated' => 'Suspended agent access',
            'agent.password_updated' => 'Changed password',
            'agent.reactivated' => 'Restored agent access',
            'agent.role_changed' => $this->accountRoleChangeBody($event),
            'site_access.updated' => 'Updated support access',
            default => 'Recorded account activity',
        };
    }

    private function accountRoleChangeBody(AuditEvent $event): string
    {
        $oldRole = data_get($event->metadata, 'old_role');
        $newRole = data_get($event->metadata, 'new_role');
        $roleLabels = $this->roleLabels();

        if (is_string($oldRole) && is_string($newRole)) {
            return sprintf(
                'Changed role from %s to %s',
                $roleLabels[$oldRole] ?? str($oldRole)->headline()->toString(),
                $roleLabels[$newRole] ?? str($newRole)->headline()->toString(),
            );
        }

        return 'Changed account role';
    }

    /**
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        return [
            AccountRole::Owner->value => 'Owner',
            AccountRole::Admin->value => 'Admin',
            AccountRole::Agent->value => 'Agent',
        ];
    }
}
