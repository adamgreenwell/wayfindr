<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AccountAlertReadiness;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReaderNumber;
use App\Support\TicketExternalIssueState;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AgentAccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);
        $agent->loadMissing('customRole');

        $account = $agent->account()->firstOrFail();
        $visibleSiteIds = $account->sites()
            ->visibleToAgent($agent)
            ->pluck('sites.id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->all();
        $canViewConversations = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);
        $workloadCounts = [];

        if ($canViewConversations) {
            $workloadCounts['assignedConversations as visible_open_conversations_count'] = fn ($query) => $query
                ->where('status', 'open')
                ->whereIn('site_id', $visibleSiteIds);
        }

        if ($canManageTickets) {
            $workloadCounts['assignedTickets as visible_open_tickets_count'] = fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('status', 'open')
                ->whereIn('site_id', $visibleSiteIds);
        }

        $agentsQuery = $account->agents()->with('customRole');

        if ($workloadCounts !== []) {
            $agentsQuery->withCount($workloadCounts);
        }

        $agents = $agentsQuery
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
                ->with('customRole')
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
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'account' => $account,
            'accountActivity' => $this->accountActivityItems($account, $visibleSiteIds),
            'agent' => $agent,
            'agentAlertReadinessSummary' => $agent->hasAccountPermission(AccountPermission::ManageAgents)
                ? app(AccountAlertReadiness::class)->summarize($agents)
                : null,
            'agentAlertDeliverySummaries' => $agents->mapWithKeys(fn (User $accountAgent): array => [
                $accountAgent->id => $this->agentAlertDeliverySummary($accountAgent),
            ]),
            'agents' => $agents,
            'agentSupportScopes' => $agentSupportScopes,
            'activeAgentCount' => $agents->reject->isDeactivated()->count(),
            'canCreateAgents' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'newAgentRoleLabel' => $agent->custom_role_id !== null
                ? ($agent->customRole?->name ?? __('profile.roles.agent'))
                : __('profile.roles.agent'),
            'canViewExternalIssueReadiness' => $agent->hasAccountPermission(AccountPermission::ManageIntegrations),
            'canViewAlertDelivery' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'canManageAgentAccess' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'canManageIntegrations' => $agent->hasAccountPermission(AccountPermission::ManageIntegrations),
            'canManageKnowledge' => $agent->hasAccountPermission(AccountPermission::ManageKnowledge),
            'canManageOperatorAccess' => $agent->hasAccountPermission(AccountPermission::ManageOperatorAccess),
            'canManageRoles' => $agent->hasAccountPermission(AccountPermission::ManageRoles),
            'canManageSecurity' => $agent->hasAccountPermission(AccountPermission::ManageSecurity),
            'canManageTickets' => $canManageTickets,
            'canViewConversations' => $canViewConversations,
            'canViewSites' => $agent->hasAnyAccountPermission(
                AccountPermission::ManageSites,
                AccountPermission::ManageSiteAccess,
                AccountPermission::ManagePrivacySettings,
                AccountPermission::ManageIntegrations,
                AccountPermission::ViewAudit,
                AccountPermission::ViewConversations,
                AccountPermission::ManageTickets,
            ),
            'canViewAudit' => $agent->hasAccountPermission(AccountPermission::ViewAudit),
            'externalIssueReadiness' => $agent->hasAccountPermission(AccountPermission::ManageIntegrations)
                ? $this->externalIssueReadiness($account, $visibleSiteIds, $canManageTickets)
                : null,
            'roleLabels' => $this->roleLabels(),
            'roleOptions' => [
                ...$this->roleLabels(),
                ...$account->customRoles()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn ($role): array => ['custom:'.$role->id => $role->name])
                    ->all(),
            ],
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
     *         site_language: string|null,
     *         provider: string,
     *         provider_language: string|null,
     *         connection: string,
     *         connection_language: string|null,
     *         project_key: string,
     *         project_name: string|null,
     *         capabilities: list<string>,
     *         handoff: array{label: string, detail: string, tone: string},
     *         href: string,
     *         enabled: bool
     *     }>,
     *     recent_failures: Collection<int, array{
     *         provider: string,
     *         provider_language: string|null,
     *         project_key: string,
     *         project_language: string|null,
     *         status: mixed,
     *         occurred_at: Carbon|null
     *     }>
     * }
     */
    private function externalIssueReadiness(Account $account, array $visibleSiteIds, bool $canManageTickets): array
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
        $disabledCount = $connections
            ->where('is_enabled', false)
            ->count();
        $failedCount = 0;
        $pendingCount = 0;
        $failedQueueCount = 0;
        $pendingQueueCount = 0;
        $recentFailures = collect();

        if ($canManageTickets) {
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

            $failedCount = max(
                (int) ($statusCounts[ExternalIssueSyncStatus::FAILED] ?? 0),
                $visibleFailureEvents()->count(),
            );
            $pendingCount = (int) ($statusCounts[ExternalIssueSyncStatus::PENDING] ?? 0);
            $failedQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::FAILED] ?? 0);
            $pendingQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::PENDING] ?? 0);
            $recentFailures = $visibleFailureEvents()
                ->latest('occurred_at')
                ->latest('id')
                ->limit(3)
                ->get()
                ->map(function (AuditEvent $event): array {
                    $provider = $this->providerParts(data_get($event->metadata, 'provider'));

                    return [
                        'provider' => $provider['label'],
                        'provider_language' => $provider['language'],
                        'project_key' => (string) (data_get($event->metadata, 'project_key') ?? __('account.external.failures.unknown_project')),
                        'project_language' => data_get($event->metadata, 'project_key') !== null ? '' : null,
                        'status' => data_get($event->metadata, 'status'),
                        'occurred_at' => $event->occurred_at,
                    ];
                });
        }

        $metrics = [
            [
                'label' => __('account.external.metrics.connections'),
                'value' => trans_choice('account.external.metrics.connection_count', $connections->count(), [
                    'count' => ReaderNumber::count($connections->count()),
                ]),
                'tone' => $connections->isEmpty() ? 'manual' : 'ready',
            ],
            [
                'label' => __('account.external.metrics.projects'),
                'value' => trans_choice('account.external.metrics.project_count', $projects->count(), [
                    'count' => ReaderNumber::count($projects->count()),
                ]),
                'tone' => $projects->isEmpty() ? 'manual' : 'ready',
            ],
            [
                'label' => __('account.external.metrics.disabled'),
                'value' => __('account.external.metrics.disabled_count', ['count' => ReaderNumber::count($disabledCount)]),
                'tone' => $disabledCount > 0 ? 'attention' : 'ready',
            ],
        ];

        if ($canManageTickets) {
            $metrics[] = [
                'label' => __('account.external.metrics.failed'),
                'value' => __('account.external.metrics.failed_count', ['count' => ReaderNumber::count($failedCount)]),
                'tone' => $failedCount > 0 ? 'attention' : 'ready',
                'href' => $failedQueueCount > 0
                    ? route('dashboard.tickets.index', [
                        'ticket_status' => 'all',
                        'ticket_external' => 'failed',
                    ])
                    : null,
                'action' => __('account.external.metrics.review_failed'),
            ];
            $metrics[] = [
                'label' => __('account.external.metrics.pending'),
                'value' => __('account.external.metrics.pending_count', ['count' => ReaderNumber::count($pendingCount)]),
                'tone' => $pendingCount > 0 ? 'manual' : 'ready',
                'href' => $pendingQueueCount > 0
                    ? route('dashboard.tickets.index', [
                        'ticket_status' => 'all',
                        'ticket_external' => 'pending',
                    ])
                    : null,
                'action' => __('account.external.metrics.review_pending'),
            ];
        }

        [$state, $tone, $detail] = match (true) {
            $connections->isEmpty() => [
                'not_configured',
                'manual',
                'no_connections',
            ],
            $projects->isEmpty() => [
                'not_configured',
                'manual',
                'no_projects',
            ],
            $disabledCount > 0 || $failedCount > 0 => [
                'needs_attention',
                'attention',
                $canManageTickets ? 'attention' : 'disabled_connections',
            ],
            $pendingCount > 0 => [
                'sync_pending',
                'manual',
                'pending',
            ],
            default => [
                'ready',
                'ready',
                $canManageTickets ? 'ready' : 'configured',
            ],
        };

        return [
            'label' => __('account.external.states.'.$state),
            'tone' => $tone,
            'detail' => __('account.external.details.'.$detail),
            'metrics' => $metrics,
            'projects' => $projects->map(function (SiteExternalIssueProject $project): array {
                $provider = $this->providerParts($project->providerConnection?->provider);

                return [
                    'site' => $project->site?->name ?? __('account.external.projects.unknown_site'),
                    'site_language' => $project->site ? '' : null,
                    'provider' => $provider['label'],
                    'provider_language' => $provider['language'],
                    'connection' => $project->providerConnection?->name ?? $provider['label'],
                    'connection_language' => $project->providerConnection ? '' : $provider['language'],
                    'project_key' => $project->project_key,
                    'project_name' => $project->project_name,
                    'capabilities' => collect(ExternalIssueCapability::values())
                        ->filter(fn (string $capability): bool => $project->hasCapability($capability))
                        ->map(fn (string $capability): string => __('integrations.capabilities.labels.'.$capability))
                        ->values()
                        ->all(),
                    'handoff' => $this->issueCreationHandoffParts($project),
                    'href' => $project->site
                        ? route('dashboard.sites.show', $project->site).'#external-issue-routing-heading'
                        : route('dashboard.sites.index'),
                    'enabled' => (bool) $project->providerConnection?->is_enabled,
                ];
            }),
            'recent_failures' => $recentFailures,
        ];
    }

    /** @return array{label: string, language: string|null} */
    private function providerParts(mixed $provider): array
    {
        if (is_string($provider) && in_array($provider, ['github', 'gitlab', 'bitbucket', 'jira'], true)) {
            return [
                'label' => ExternalIssueProvider::label($provider),
                'language' => '',
            ];
        }

        return [
            'label' => $provider === 'other'
                ? __('integrations.providers.other')
                : __('integrations.providers.external_tracker'),
            'language' => null,
        ];
    }

    /** @return array{label: string, detail: string, tone: string} */
    private function issueCreationHandoffParts(SiteExternalIssueProject $project): array
    {
        [$state, $tone] = match (true) {
            ! $project->providerConnection?->is_enabled => ['blocked', 'attention'],
            ! $project->hasSupportedIssueCreationProvider() => ['unsupported', 'manual'],
            $project->supportsIssueCreationHandoff() => ['ready', 'ready'],
            default => ['disabled', 'manual'],
        };

        return [
            'label' => __('account.external.handoff.'.$state.'.label'),
            'detail' => __('account.external.handoff.'.$state.'.detail'),
            'tone' => $tone,
        ];
    }

    /**
     * @return array{primary: string, lines: array<int, array{text: string, tone?: string}>}
     */
    private function agentAlertDeliverySummary(User $accountAgent): array
    {
        if ($accountAgent->isDeactivated()) {
            return [
                'primary' => __('account.agents.alert_delivery.deactivated'),
                'lines' => [
                    ['text' => __('account.agents.alert_delivery.deactivated_detail')],
                ],
            ];
        }

        if ($accountAgent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'primary' => __('account.agents.alert_delivery.quiet_mode'),
                'lines' => [
                    ['text' => __('account.agents.alert_delivery.quiet_detail')],
                ],
            ];
        }

        [$scopeLabel, $scopeDetail] = $this->agentAlertScopeSummary($accountAgent);

        if (! $accountAgent->alertEmailEnabled()) {
            return [
                'primary' => __('account.agents.alert_delivery.email_off'),
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
                $lines[] = ['text' => __('account.agents.alert_delivery.last_attempt', [
                    'elapsed' => $digestDeliveryStatus['last_attempted_at']->diffForHumans(),
                ])];
            }

            return [
                'primary' => __('account.agents.alert_delivery.digest_delivery'),
                'lines' => $lines,
            ];
        }

        if ($accountAgent->alertCadence() === User::ALERT_CADENCE_UNATTENDED) {
            return [
                'primary' => __('account.agents.alert_delivery.unattended'),
                'lines' => [
                    ['text' => $scopeLabel, 'tone' => 'ready'],
                    ['text' => __('account.agents.alert_delivery.unattended_detail', [
                        'minutes' => ReaderNumber::count(UnattendedConversationAlertCollector::THRESHOLD_MINUTES),
                    ])],
                    ['text' => $scopeDetail],
                ],
            ];
        }

        return [
            'primary' => __('account.agents.alert_delivery.immediate'),
            'lines' => [
                ['text' => $scopeLabel, 'tone' => 'ready'],
                ['text' => __('account.agents.alert_delivery.immediate_detail')],
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
                __('account.agents.alert_delivery.assigned_only'),
                __('account.agents.alert_delivery.assigned_detail'),
            ];
        }

        return [
            __('account.agents.alert_delivery.all'),
            __('account.agents.alert_delivery.all_detail'),
        ];
    }

    /**
     * @return Collection<int, array{label: string, actor: string, actor_language: string|null, subject: string, subject_language: string|null, body: string, occurred_at: Carbon|null}>
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
                'actor_language' => $event->actor instanceof User ? '' : null,
                'subject' => $this->accountActivitySubject($event),
                'subject_language' => $event->subject instanceof User || $event->subject instanceof Site ? '' : null,
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
            'agent.created' => __('account.activity.labels.agent_created'),
            'agent.deactivated' => __('account.activity.labels.agent_deactivated'),
            'agent.password_updated' => __('account.activity.labels.password_changed'),
            'agent.reactivated' => __('account.activity.labels.agent_reactivated'),
            'agent.role_changed' => __('account.activity.labels.role_changed'),
            'site_access.updated' => __('account.activity.labels.site_access'),
            default => __('account.activity.labels.default'),
        };
    }

    private function accountActivityActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        return __('account.activity.system');
    }

    private function accountActivitySubject(AuditEvent $event): string
    {
        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
        }

        return __('account.activity.account');
    }

    private function accountActivityBody(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => __('account.activity.bodies.agent_created'),
            'agent.deactivated' => __('account.activity.bodies.agent_deactivated'),
            'agent.password_updated' => __('account.activity.bodies.password_changed'),
            'agent.reactivated' => __('account.activity.bodies.agent_reactivated'),
            'agent.role_changed' => $this->accountRoleChangeBody($event),
            'site_access.updated' => __('account.activity.bodies.site_access'),
            default => __('account.activity.bodies.default'),
        };
    }

    private function accountRoleChangeBody(AuditEvent $event): string
    {
        $oldRole = data_get($event->metadata, 'old_role');
        $newRole = data_get($event->metadata, 'new_role');
        $oldRoleName = data_get($event->metadata, 'old_role_name');
        $newRoleName = data_get($event->metadata, 'new_role_name');
        $roleLabels = $this->roleLabels();

        if (is_string($oldRoleName) && is_string($newRoleName)) {
            return __('account.activity.bodies.role_changed', [
                'old' => $roleLabels[$oldRoleName] ?? $oldRoleName,
                'new' => $roleLabels[$newRoleName] ?? $newRoleName,
            ]);
        }

        if (is_string($oldRole) && is_string($newRole)
            && isset($roleLabels[$oldRole], $roleLabels[$newRole])) {
            return __('account.activity.bodies.role_changed', [
                'old' => $roleLabels[$oldRole],
                'new' => $roleLabels[$newRole],
            ]);
        }

        return __('account.activity.bodies.role_changed_unknown');
    }

    /**
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        return [
            AccountRole::Owner->value => __('profile.roles.owner'),
            AccountRole::Admin->value => __('profile.roles.admin'),
            AccountRole::Agent->value => __('profile.roles.agent'),
        ];
    }
}
