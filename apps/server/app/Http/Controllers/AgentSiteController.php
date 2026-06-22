<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\OperatorReadiness;
use App\Support\SiteInstallHealth;
use App\Support\TicketExternalIssueState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgentSiteController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();
        $account = $this->account($request);
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('latestVisitor')
            ->with([
                'supportAgents' => fn ($query) => $query
                    ->where('users.account_id', $account->id)
                    ->whereNull('users.deactivated_at')
                    ->orderBy('name')
                    ->orderBy('email'),
            ])
            ->withCount([
                'conversations as open_conversations_count' => fn ($query) => $query
                    ->where('status', 'open'),
                'supportAgents as support_agents_count' => fn ($query) => $query
                    ->where('users.account_id', $account->id)
                    ->whereNull('users.deactivated_at'),
                'tickets as open_tickets_count' => fn ($query) => $query
                    ->where('status', 'open'),
                'tickets as pending_tickets_count' => fn ($query) => $query
                    ->where('status', 'pending'),
            ])
            ->orderBy('name')
            ->get();
        [$sites, $siteFilters] = $this->filteredSites($sites, $request);

        return view('agent.sites.index', [
            'account' => $account,
            'agent' => $agent,
            'siteFilters' => $siteFilters,
            'sites' => $sites,
        ]);
    }

    public function create(Request $request): View
    {
        $account = $this->account($request);

        return view('agent.sites.create', [
            'account' => $account,
            'agent' => $request->user(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->account($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
        ]);

        $site = $account->sites()->create([
            'name' => trim($validated['name']),
            'domain' => $this->normalizeDomain($validated['domain'] ?? null),
            'public_key' => $this->publicKey(),
            'settings' => [
                'mask_selectors' => [],
            ],
        ]);

        $site->supportAgents()->syncWithoutDetaching($request->user()->id);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Site created. Copy the install snippet to finish connecting it.');
    }

    public function show(Request $request, Site $site, OperatorReadiness $readiness): View
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);

        $agent = $request->user();
        $site->loadMissing([
            'externalIssueProjects.providerConnection',
            'latestVisitor',
        ]);
        $account = $agent->account()->firstOrFail();
        $accountAgents = $account->agents()
            ->whereNull('deactivated_at')
            ->orderBy('name')
            ->orderBy('email')
            ->get();
        $externalIssueProviderConnections = $account->externalIssueProviderConnections()
            ->where('is_enabled', true)
            ->orderBy('provider')
            ->orderBy('name')
            ->get();
        $supportAgentIds = $this->eligibleSupportAgentIds($site);
        $maskSelectors = $this->maskSelectors($site);
        $externalIssueHealth = $this->externalIssueHealth($site);

        return view('agent.sites.show', [
            'account' => $account,
            'accountAgents' => $accountAgents,
            'agent' => $agent,
            'canViewSiteActivity' => $agent->isAdmin(),
            'canManageIntegrations' => Gate::forUser($agent)->allows('manageIntegrations', $site),
            'canManageSiteAccess' => Gate::forUser($agent)->allows('manageAccess', $site),
            'canUpdatePrivacy' => Gate::forUser($agent)->allows('updatePrivacy', $site),
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'externalIssueCapabilities' => ExternalIssueCapability::options(),
            'externalIssueHealth' => $externalIssueHealth,
            'externalIssueProviderConnections' => $externalIssueProviderConnections,
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'maskSelectors' => $maskSelectors,
            'operatorSmokePath' => $readiness->summary()['smoke_path'],
            'site' => $site,
            'siteActivity' => $this->siteActivityItems($site, $agent),
            'siteActivityAuditUrl' => $agent->isAdmin()
                ? route('dashboard.account.audit.index', [
                    'audit_action' => 'site_access.updated',
                    'audit_site' => $site->id,
                ])
                : null,
            'siteExternalIssueProjects' => $site->externalIssueProjects,
            'siteHasExplicitSupportAgents' => $site->hasExplicitSupportAgents(),
            'siteSupportLoad' => $this->siteSupportLoad($site, $supportAgentIds, $accountAgents->count()),
            'siteSupportReadiness' => $this->siteSupportReadiness($site, $supportAgentIds, $maskSelectors, $externalIssueHealth),
            'supportAgentIds' => $supportAgentIds,
            'supportAgents' => $accountAgents->whereIn('id', $supportAgentIds)->values(),
            'widgetInstallSnippet' => $this->widgetInstallSnippet($site),
        ]);
    }

    public function tester(Request $request, Site $site): View
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);

        return view('agent.sites.tester', [
            'account' => $this->account($request),
            'agent' => $request->user(),
            'site' => $site,
            'testerAnonymousId' => "tester-site-{$site->id}-agent-{$request->user()->id}",
            'widgetBaseUrl' => $this->widgetBaseUrl(),
            'widgetReverbConfig' => $this->publicReverbConfig(),
        ]);
    }

    /**
     * @return Collection<int, array{label: string, actor: string, subject: string, body: string, occurred_at: Carbon|null}>
     */
    private function siteActivityItems(Site $site, User $agent): Collection
    {
        if (! $agent->isAdmin()) {
            return collect();
        }

        return $site->auditEvents()
            ->with(['actor', 'subject'])
            ->where('account_id', $site->account_id)
            ->where('action', 'site_access.updated')
            ->latest('occurred_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'label' => 'Site access updated',
                'actor' => $event->actor instanceof User ? $event->actor->name : 'System',
                'subject' => $event->subject instanceof Site ? $event->subject->name : $site->name,
                'body' => 'Updated support access',
                'occurred_at' => $event->occurred_at,
            ]);
    }

    /**
     * @param  array<int, int>  $supportAgentIds
     * @return Collection<int, array{label: string, value: string, detail: string, href: string, action: string}>
     */
    private function siteSupportLoad(Site $site, array $supportAgentIds, int $accountAgentCount): Collection
    {
        $openConversationCount = $site->conversations()
            ->where('status', 'open')
            ->count();
        $openTicketCount = $site->tickets()
            ->where('status', 'open')
            ->count();
        $pendingTicketCount = $site->tickets()
            ->where('status', 'pending')
            ->count();
        $supportAgentCount = $site->hasExplicitSupportAgents()
            ? count($supportAgentIds)
            : $accountAgentCount;

        return collect([
            [
                'label' => 'Open conversations',
                'value' => $openConversationCount.' '.Str::plural('conversation', $openConversationCount),
                'detail' => 'Currently open for this site.',
                'href' => route('dashboard.conversations.index', ['conversation_site' => $site->id]),
                'action' => 'View conversations',
            ],
            [
                'label' => 'Open tickets',
                'value' => $openTicketCount.' '.Str::plural('ticket', $openTicketCount),
                'detail' => 'Active tickets for this site.',
                'href' => route('dashboard.tickets.index', ['ticket_site' => $site->id]),
                'action' => 'View open tickets',
            ],
            [
                'label' => 'Pending tickets',
                'value' => $pendingTicketCount.' '.Str::plural('ticket', $pendingTicketCount),
                'detail' => 'Waiting on a customer, agent, or next step.',
                'href' => route('dashboard.tickets.index', [
                    'ticket_status' => 'pending',
                    'ticket_site' => $site->id,
                ]),
                'action' => 'View pending tickets',
            ],
            [
                'label' => 'Support coverage',
                'value' => $supportAgentCount.' '.Str::plural('agent', $supportAgentCount),
                'detail' => $site->hasExplicitSupportAgents()
                    ? 'Active agents assigned to this site.'
                    : 'Account-wide fallback is active for this site.',
                'href' => route('dashboard.sites.show', $site).'#support-access-heading',
                'action' => 'Review access',
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $supportAgentIds
     * @param  array<int, string>  $maskSelectors
     * @param  array{label: string, tone: string, detail: string, metrics: Collection<int, array{label: string, value: string, tone: string, href?: string|null, action?: string}>, status_counts: Collection<int, array{key: string, label: string, count: int}>, recent_failures: Collection<int, array{provider: string, project_key: string, status: string|null, occurred_at: Carbon|null}>}  $externalIssueHealth
     * @return Collection<int, array{label: string, value: string, tone: string, detail: string, href: string}>
     */
    private function siteSupportReadiness(Site $site, array $supportAgentIds, array $maskSelectors, array $externalIssueHealth): Collection
    {
        $installHealth = SiteInstallHealth::fromVisitor($site->latestVisitor);
        $explicitSupport = $site->hasExplicitSupportAgents();
        $handoffProjectCount = $this->externalIssueHandoffProjectCount($site);

        return collect([
            [
                'label' => 'Widget install',
                'value' => $installHealth['label'],
                'tone' => $installHealth['tone'],
                'detail' => $installHealth['needs_attention']
                    ? $installHealth['detail']
                    : 'The widget has checked in recently.',
                'href' => route('dashboard.sites.show', $site).'#install-verification',
            ],
            [
                'label' => 'Support coverage',
                'value' => $explicitSupport ? 'Explicit access' : 'Account-wide fallback',
                'tone' => $explicitSupport ? 'ready' : 'manual',
                'detail' => $explicitSupport
                    ? count($supportAgentIds).' assigned'
                    : 'All account agents can support this site until explicit access is configured.',
                'href' => route('dashboard.sites.show', $site).'#support-access-heading',
            ],
            [
                'label' => 'Privacy masking',
                'value' => count($maskSelectors) > 0 ? count($maskSelectors).' selectors configured' : 'No custom selectors',
                'tone' => count($maskSelectors) > 0 ? 'ready' : 'manual',
                'detail' => count($maskSelectors) > 0
                    ? 'Custom selectors are sent as public widget configuration.'
                    : 'Known sensitive fields still use built-in masking patterns.',
                'href' => route('dashboard.sites.show', $site).'#privacy-settings-heading',
            ],
            [
                'label' => 'External routing',
                'value' => $handoffProjectCount > 0 ? $handoffProjectCount.' mapped' : 'Not mapped',
                'tone' => $handoffProjectCount > 0 ? $externalIssueHealth['tone'] : 'manual',
                'detail' => $handoffProjectCount > 0
                    ? 'Ticket handoff can use mapped external issue projects.'
                    : 'Map external issue routing if tickets should leave Wayfindr.',
                'href' => route('dashboard.sites.show', $site).'#external-issue-routing-heading',
            ],
        ]);
    }

    private function externalIssueHandoffProjectCount(Site $site): int
    {
        return $site->externalIssueProjects
            ->filter(fn (SiteExternalIssueProject $project): bool => $project->supportsIssueCreationHandoff())
            ->count();
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     detail: string,
     *     metrics: Collection<int, array{label: string, value: string, tone: string, href?: string|null, action?: string}>,
     *     status_counts: Collection<int, array{key: string, label: string, count: int}>,
     *     recent_failures: Collection<int, array{provider: string, project_key: string, status: string|null, occurred_at: Carbon|null}>
     * }
     */
    private function externalIssueHealth(Site $site): array
    {
        $mappedProjectCount = $site->externalIssueProjects->count();
        $handoffProjectCount = $this->externalIssueHandoffProjectCount($site);
        $disabledProjectCount = $site->externalIssueProjects
            ->filter(fn ($project): bool => $project->providerConnection?->is_enabled === false)
            ->count();
        $statusCounts = $site->ticketExternalLinks()
            ->where('account_id', $site->account_id)
            ->selectRaw('sync_status, count(*) as aggregate')
            ->groupBy('sync_status')
            ->pluck('aggregate', 'sync_status');
        $failureEvents = fn () => $site->auditEvents()
            ->where('account_id', $site->account_id)
            ->where('action', 'ticket.external_sync_failed');
        $auditFailureCount = $failureEvents()->count();
        $queueStateCounts = TicketExternalIssueState::countsForQuery(
            Ticket::query()
                ->where('account_id', $site->account_id)
                ->where('site_id', $site->id)
        );
        $recentFailures = $failureEvents()
            ->latest('occurred_at')
            ->latest('id')
            ->limit(3)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'provider' => ExternalIssueProvider::label(data_get($event->metadata, 'provider')),
                'project_key' => $this->externalIssueFailureProjectKey($event),
                'status' => $this->externalIssueFailureStatus($event),
                'occurred_at' => $event->occurred_at,
            ]);

        $failedCount = max((int) ($statusCounts[ExternalIssueSyncStatus::FAILED] ?? 0), $auditFailureCount);
        $pendingCount = (int) ($statusCounts[ExternalIssueSyncStatus::PENDING] ?? 0);
        $failedQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::FAILED] ?? 0);
        $pendingQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::PENDING] ?? 0);
        $statusItems = collect(ExternalIssueSyncStatus::options())
            ->map(fn (string $label, string $status): array => [
                'key' => $status,
                'label' => $label,
                'count' => $status === ExternalIssueSyncStatus::FAILED
                    ? $failedCount
                    : (int) ($statusCounts[$status] ?? 0),
            ])
            ->values();

        [$label, $tone, $detail] = match (true) {
            $mappedProjectCount === 0 => [
                'Not configured',
                'manual',
                'Map a project before this site can send tickets outside Wayfindr.',
            ],
            $disabledProjectCount > 0 => [
                'Needs attention',
                'attention',
                'Enable or replace disabled provider mappings before ticket handoff depends on them.',
            ],
            $failedCount > 0 => [
                'Needs attention',
                'attention',
                'Review failed syncs before relying on external handoff for this site.',
            ],
            $pendingCount > 0 => [
                'Sync pending',
                'manual',
                'Some ticket handoffs are still waiting for provider confirmation.',
            ],
            $handoffProjectCount === 0 => [
                'Not ready',
                'manual',
                'Mapped projects exist, but none can currently create external issues.',
            ],
            default => [
                'Ready',
                'ready',
                'Tickets can route to an enabled external project for this site.',
            ],
        };

        return [
            'label' => $label,
            'tone' => $tone,
            'detail' => $detail,
            'metrics' => collect([
                [
                    'label' => 'Mapped projects',
                    'value' => $mappedProjectCount.' mapped '.Str::plural('project', $mappedProjectCount),
                    'tone' => $mappedProjectCount > 0 ? 'ready' : 'manual',
                ],
                [
                    'label' => 'Handoff ready',
                    'value' => $handoffProjectCount.' handoff ready',
                    'tone' => $handoffProjectCount > 0 ? 'ready' : 'manual',
                ],
                [
                    'label' => 'Disabled mappings',
                    'value' => $disabledProjectCount.' disabled',
                    'tone' => $disabledProjectCount > 0 ? 'attention' : 'ready',
                ],
                [
                    'label' => 'Sync failed',
                    'value' => $failedCount.' sync failed',
                    'tone' => $failedCount > 0 ? 'attention' : 'ready',
                    'href' => $failedQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_site' => $site->id,
                            'ticket_external' => 'failed',
                        ])
                        : null,
                    'action' => 'Review failed tickets',
                ],
                [
                    'label' => 'Sync pending',
                    'value' => $pendingCount.' sync pending',
                    'tone' => $pendingCount > 0 ? 'manual' : 'ready',
                    'href' => $pendingQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_site' => $site->id,
                            'ticket_external' => 'pending',
                        ])
                        : null,
                    'action' => 'Review pending tickets',
                ],
            ]),
            'status_counts' => $statusItems,
            'recent_failures' => $recentFailures,
        ];
    }

    private function externalIssueFailureProjectKey(AuditEvent $event): string
    {
        $projectKey = data_get($event->metadata, 'project_key');

        return is_string($projectKey) && trim($projectKey) !== ''
            ? trim($projectKey)
            : 'Project not recorded';
    }

    private function externalIssueFailureStatus(AuditEvent $event): ?string
    {
        $status = data_get($event->metadata, 'status');

        if (is_int($status) || (is_string($status) && preg_match('/^\d{3}$/', $status))) {
            return 'Status '.$status;
        }

        if (is_string($status) && preg_match('/^[A-Za-z0-9 _.-]{1,40}$/', $status)) {
            return 'Status '.$status;
        }

        return null;
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'updatePrivacy', $site);

        $validated = $request->validate([
            'mask_selectors' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = $site->settings ?? [];
        $settings['mask_selectors'] = $this->parseMaskSelectors($validated['mask_selectors'] ?? '');

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Site privacy settings saved.');
    }

    public function updateSupportAgents(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'manageAccess', $site);

        $accountAgentIds = $site->account()
            ->firstOrFail()
            ->agents()
            ->whereNull('deactivated_at')
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values()
            ->all();

        $validated = $request->validate([
            'support_agent_ids' => ['required', 'array', 'min:1'],
            'support_agent_ids.*' => ['integer', Rule::in($accountAgentIds)],
        ], [
            'support_agent_ids.required' => 'Choose at least one support agent.',
            'support_agent_ids.min' => 'Choose at least one support agent.',
            'support_agent_ids.*.in' => 'Choose only agents from this account.',
        ]);

        $beforeAgentIds = $this->eligibleSupportAgentIds($site);
        $afterAgentIds = $this->normalizeAgentIds($validated['support_agent_ids']);

        if (! $this->hasAssignedSiteManager($site, $afterAgentIds)) {
            throw ValidationException::withMessages([
                'support_agent_ids' => 'Keep at least one account owner or admin assigned so site access remains manageable.',
            ]);
        }

        $site->supportAgents()->sync($afterAgentIds);

        if ($beforeAgentIds !== $afterAgentIds) {
            $this->recordSiteAccessChange($site, $request->user(), $beforeAgentIds, $afterAgentIds);
        }

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Site access saved.');
    }

    private function authorizeSiteAbility(Request $request, string $ability, Site $site, int $status = 403): void
    {
        $agent = $request->user();

        abort_unless($agent && Gate::forUser($agent)->allows($ability, $site), $status);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{0: Collection<int, Site>, 1: array{search: string, workload: string, install: string, workload_options: array<string, string>, install_options: array<string, string>, active: list<array{label: string, value: string}>, has_active_filters: bool, visible_count: int, result_count: int, summary_label: string}}
     */
    private function filteredSites(Collection $sites, Request $request): array
    {
        $visibleCount = $sites->count();
        $workloadOptions = [
            'all' => 'All workloads',
            'active' => 'Active support work',
            'quiet' => 'Quiet',
        ];
        $installOptions = [
            'all' => 'All install states',
            'needs_attention' => 'Needs attention',
            'live' => 'Live',
        ];
        $search = trim($this->stringQuery($request, 'site_search'));
        $workload = $this->normalizeSiteFilter(
            $this->stringQuery($request, 'site_workload', 'all'),
            array_keys($workloadOptions),
        );
        $install = $this->normalizeSiteFilter(
            $this->stringQuery($request, 'site_install', 'all'),
            array_keys($installOptions),
        );

        $filteredSites = $sites
            ->filter(fn (Site $site): bool => $this->siteMatchesSearch($site, $search))
            ->filter(fn (Site $site): bool => $this->siteMatchesWorkloadFilter($site, $workload))
            ->filter(fn (Site $site): bool => $this->siteMatchesInstallFilter($site, $install))
            ->values();
        $activeFilters = [];

        if ($search !== '') {
            $activeFilters[] = ['label' => 'Search', 'value' => $search];
        }

        if ($workload !== 'all') {
            $activeFilters[] = ['label' => 'Workload', 'value' => $workloadOptions[$workload]];
        }

        if ($install !== 'all') {
            $activeFilters[] = ['label' => 'Install', 'value' => $installOptions[$install]];
        }

        $resultCount = $filteredSites->count();
        $hasActiveFilters = $activeFilters !== [];

        return [
            $filteredSites,
            [
                'search' => $search,
                'workload' => $workload,
                'install' => $install,
                'workload_options' => $workloadOptions,
                'install_options' => $installOptions,
                'active' => $activeFilters,
                'has_active_filters' => $hasActiveFilters,
                'visible_count' => $visibleCount,
                'result_count' => $resultCount,
                'summary_label' => $hasActiveFilters
                    ? "{$resultCount} shown of {$visibleCount} visible"
                    : "{$visibleCount} visible",
            ],
        ];
    }

    private function stringQuery(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeSiteFilter(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : 'all';
    }

    private function siteMatchesSearch(Site $site, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return Str::contains(
            Str::lower($site->name.' '.($site->domain ?? '')),
            Str::lower($search),
        );
    }

    private function siteMatchesWorkloadFilter(Site $site, string $workload): bool
    {
        $hasWorkload = ((int) $site->open_conversations_count) > 0
            || ((int) $site->open_tickets_count) > 0
            || ((int) $site->pending_tickets_count) > 0;

        return match ($workload) {
            'active' => $hasWorkload,
            'quiet' => ! $hasWorkload,
            default => true,
        };
    }

    private function siteMatchesInstallFilter(Site $site, string $install): bool
    {
        $installHealth = SiteInstallHealth::fromVisitor($site->latestVisitor);

        return match ($install) {
            'needs_attention' => $installHealth['needs_attention'],
            'live' => $installHealth['label'] === 'Live',
            default => true,
        };
    }

    private function account(Request $request): Account
    {
        abort_unless($request->user()?->account_id, 403);

        return $request->user()->account()->firstOrFail();
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
     * @return array<int, string>
     */
    private function parseMaskSelectors(string $value): array
    {
        $selectors = preg_split('/\R/', $value) ?: [];
        $selectors = array_map(fn (string $selector): string => trim($selector), $selectors);
        $selectors = array_filter($selectors, fn (string $selector): bool => $selector !== '');
        $selectors = array_map(fn (string $selector): string => mb_substr($selector, 0, 255), $selectors);

        return array_values(array_unique($selectors));
    }

    /**
     * @return array<int, int>
     */
    private function eligibleSupportAgentIds(Site $site): array
    {
        return $site->eligibleSupportAgents()
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $agentIds
     * @return array<int, int>
     */
    private function normalizeAgentIds(array $agentIds): array
    {
        return collect($agentIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $agentIds
     */
    private function hasAssignedSiteManager(Site $site, array $agentIds): bool
    {
        return $site->account()
            ->firstOrFail()
            ->agents()
            ->whereIn('users.id', $agentIds)
            ->whereNull('deactivated_at')
            ->whereIn('account_role', [
                AccountRole::Owner->value,
                AccountRole::Admin->value,
            ])
            ->exists();
    }

    /**
     * @param  array<int, int>  $beforeAgentIds
     * @param  array<int, int>  $afterAgentIds
     */
    private function recordSiteAccessChange(Site $site, User $actor, array $beforeAgentIds, array $afterAgentIds): void
    {
        $site->auditEvents()->create([
            'account_id' => $site->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'action' => 'site_access.updated',
            'metadata' => [
                'before_agent_ids' => $beforeAgentIds,
                'after_agent_ids' => $afterAgentIds,
                'added_agent_ids' => array_values(array_diff($afterAgentIds, $beforeAgentIds)),
                'removed_agent_ids' => array_values(array_diff($beforeAgentIds, $afterAgentIds)),
            ],
            'occurred_at' => now(),
        ]);
    }

    private function normalizeDomain(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $url = preg_match('/^https?:\/\//i', $value) === 1 ? $value : "https://{$value}";
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return mb_strtolower($value);
        }

        return mb_strtolower($host.($port ? ":{$port}" : ''));
    }

    private function publicKey(): string
    {
        do {
            $key = 'site_'.Str::lower(Str::random(32));
        } while (Site::query()->where('public_key', $key)->exists());

        return $key;
    }

    private function widgetInstallSnippet(Site $site): string
    {
        $baseUrl = $this->widgetBaseUrl();
        $attributes = [
            'src' => "{$baseUrl}/widget.js",
            'data-wayfindr-api-base-url' => $baseUrl,
            'data-wayfindr-site-key' => $site->public_key,
        ];

        $reverb = $this->publicReverbConfig();
        $lines = [];

        if ($reverb !== null) {
            $lines[] = '<script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>';
            $attributes = [
                ...$attributes,
                'data-wayfindr-reverb-app-key' => $reverb['app_key'],
                'data-wayfindr-reverb-host' => $reverb['host'],
                'data-wayfindr-reverb-port' => $reverb['port'],
                'data-wayfindr-reverb-scheme' => $reverb['scheme'],
            ];
        }

        $lines[] = '<script';

        foreach ($attributes as $name => $value) {
            $lines[] = sprintf('  %s="%s"', $name, $this->attribute($value));
        }

        $lines[] = '></script>';

        return implode(PHP_EOL, $lines);
    }

    private function widgetBaseUrl(): string
    {
        return rtrim((string) config('app.url', url('/')), '/');
    }

    /**
     * @return array{app_key: string, host: string, port: string, scheme: string}|null
     */
    private function publicReverbConfig(): ?array
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $appKey = config('broadcasting.connections.reverb.key');
        $host = config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.scheme');

        if (! $this->hasConfigValue($appKey) || ! $this->hasConfigValue($host) || ! $this->hasConfigValue($port) || ! $this->hasConfigValue($scheme)) {
            return null;
        }

        return [
            'app_key' => (string) $appKey,
            'host' => (string) $host,
            'port' => (string) $port,
            'scheme' => (string) $scheme,
        ];
    }

    private function hasConfigValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function attribute(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
