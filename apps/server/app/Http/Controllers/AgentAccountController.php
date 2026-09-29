<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\ReaderNumber;
use App\Support\TicketExternalIssueState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The account at a glance. The people -- roster, site access, team alerts and
 * their history -- are AgentAccountTeamController's.
 */
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
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);

        return view('agent.account.show', [
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'account' => $account,
            'agent' => $agent,
            'agentCount' => $account->agents()->count(),
            'canViewExternalIssueReadiness' => $agent->hasAccountPermission(AccountPermission::ManageIntegrations),
            'canManageTickets' => $canManageTickets,
            'externalIssueReadiness' => $agent->hasAccountPermission(AccountPermission::ManageIntegrations)
                ? $this->externalIssueReadiness($account, $visibleSiteIds, $canManageTickets)
                : null,
            'roleLabels' => AccountRole::labels(),
            'siteCount' => $account->sites()->count(),
            // Every active agent named on every site this viewer can see: the
            // Team page's access matrix, counted rather than drawn.
            'supportAssignmentCount' => $account->sites()
                ->visibleToAgent($agent)
                ->withCount(['supportAgents' => fn ($query) => $query
                    ->where('users.account_id', $account->id)
                    ->whereNull('users.deactivated_at')])
                ->get()
                ->sum('support_agents_count'),
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
}
