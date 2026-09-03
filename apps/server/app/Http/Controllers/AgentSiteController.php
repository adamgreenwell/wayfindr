<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\OperatorDashboardPresenter;
use App\Support\OperatorReadiness;
use App\Support\ReaderNumber;
use App\Support\SiteInstallHealth;
use App\Support\SitePurge;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\SiteRatingPrompt;
use App\Support\Sites\WidgetAppearance;
use App\Support\Sites\WidgetLanguage;
use App\Support\TicketExternalIssueState;
use App\Support\Visitors\LiveVisitorBoard;
use App\Support\Visitors\VisitorPresence;
use App\Support\WidgetRealtimeConfig;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
            ->visibleToAgentIncludingArchived($agent)
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
        // Always describes sites still in service, whichever state the operator is
        // browsing: an archived site has no install to fix and no support work to
        // chase, so counting it here would nag about a site deliberately retired.
        $siteOperationsSnapshot = $this->siteOperationsSnapshot(
            $sites->reject(fn (Site $site): bool => $site->isArchived())->values()
        );
        $siteInstallHealth = $sites
            ->mapWithKeys(fn (Site $site): array => [
                $site->id => $this->localizedSiteInstallHealth($site->latestVisitor),
            ])
            ->all();
        [$sites, $siteFilters] = $this->filteredSites($sites, $request);

        return view('agent.sites.index', [
            'account' => $account,
            'agent' => $agent,
            'siteEmptyState' => $this->siteEmptyState($siteFilters),
            'siteFilters' => $siteFilters,
            'siteInstallHealth' => $siteInstallHealth,
            'siteOperationsSnapshot' => $siteOperationsSnapshot,
            'siteStatusFeedback' => $this->siteIndexStatusFeedback($request->session()->get('status')),
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

        $validated = $request->validate(
            [
                'name' => ['required', 'string', 'max:255'],
                'domain' => ['nullable', 'string', 'max:255'],
            ],
            [],
            [
                'name' => __('sites.create.fields.name'),
                'domain' => __('sites.create.fields.domain'),
            ],
        );

        $site = $account->sites()->create([
            'name' => trim($validated['name']),
            'domain' => $this->normalizeDomain($validated['domain'] ?? null),
            // Assigned rather than defaulted, so a desk's sites stay tellable
            // apart on sight from the moment the second one is created.
            'color' => Site::nextColorForAccount((int) $account->id),
            'public_key' => $this->publicKey(),
            'settings' => [
                'mask_selectors' => [],
            ],
        ]);

        $site->supportAgents()->syncWithoutDetaching($request->user()->id);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'sites.flash.created');
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
        $externalIssueProviderParts = $site->externalIssueProjects
            ->pluck('providerConnection')
            ->filter()
            ->concat($externalIssueProviderConnections)
            ->unique('id')
            ->mapWithKeys(fn ($connection): array => [
                (int) $connection->getKey() => $this->localizedExternalIssueProviderParts($connection->provider),
            ]);
        $supportAgentIds = $this->eligibleSupportAgentIds($site);
        $maskSelectors = $this->maskSelectors($site);
        $maskTerms = $this->maskTerms($site);
        $externalIssueHealth = $this->externalIssueHealth($site);
        $installHealth = $this->localizedSiteInstallHealth($site->latestVisitor);
        $installHostDiagnostic = $this->localizedSiteInstallHostDiagnostic($site->latestVisitor, $site->domain);

        return view('agent.sites.show', [
            'account' => $account,
            'accountAgents' => $accountAgents,
            'agent' => $agent,
            'canViewSiteActivity' => $agent->isAdmin(),
            'canManageIntegrations' => Gate::forUser($agent)->allows('manageIntegrations', $site),
            'canManageSiteAccess' => Gate::forUser($agent)->allows('manageAccess', $site),
            'canUpdatePrivacy' => Gate::forUser($agent)->allows('updatePrivacy', $site),
            'canUpdateSite' => Gate::forUser($agent)->allows('update', $site),
            'appearance' => WidgetAppearance::for($site),
            'availability' => SiteAvailability::for($site),
            'availabilitySettings' => is_array($site->settings['availability'] ?? null)
                ? $site->settings['availability']
                : [],
            // Normalised here so the form needs no logic. This view mixes both
            // @php forms badly -- an inline @php() alongside its existing
            // @php...@endphp blocks silently breaks everything after it.
            'availabilityWeekdays' => $this->availabilityWeekdaysForForm($site),
            'intake' => SiteIntake::for($site),
            'ratingPrompt' => SiteRatingPrompt::for($site),
            'widgetLocale' => WidgetLanguage::for($site),
            'widgetLanguages' => WidgetLanguage::options(),
            'externalIssueHealth' => $externalIssueHealth,
            'externalIssueProviderConnections' => $externalIssueProviderConnections,
            'externalIssueProviderParts' => $externalIssueProviderParts,
            'installHealth' => $installHealth,
            'installHostDiagnostic' => $installHostDiagnostic,
            'installVerification' => $this->localizedSiteInstallVerification($site->latestVisitor),
            'presenceEnabled' => SitePresenceReporting::for($site)->enabled,
            'presencePageUrls' => SitePresenceReporting::for($site)->pageUrls,
            // The same number the visitor's notice quotes. An operator reading
            // "30 days" on the page where they configure this, while their
            // install deletes after seven, is being told something untrue by
            // the surface that exists to tell them the truth.
            'presenceRetentionDays' => SitePresenceReporting::retentionDays(),
            'presenceEvery' => SitePresenceReporting::HEARTBEAT_SECONDS,
            'maskSelectors' => $maskSelectors,
            'maskTerms' => $maskTerms,
            'operatorSmokePath' => OperatorDashboardPresenter::readiness($readiness->summary())['smoke_path'],
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
            'siteStatusFeedback' => $this->siteShowStatusFeedback($request->session()->get('status')),
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
            'widgetReverbConfig' => WidgetRealtimeConfig::public(),
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
                'label' => __('site_settings.activity.label'),
                'actor' => $event->actor instanceof User ? $event->actor->name : __('site_settings.activity.system'),
                'actor_is_authored' => $event->actor instanceof User,
                'subject' => $event->subject instanceof Site ? $event->subject->name : $site->name,
                'body' => __('site_settings.activity.body'),
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
                'label' => __('site_settings.load.conversations.label'),
                'value' => trans_choice('site_settings.load.conversations.count', $openConversationCount, ['count' => ReaderNumber::count($openConversationCount)]),
                'detail' => __('site_settings.load.conversations.detail'),
                'href' => route('dashboard.conversations.index', ['conversation_site' => $site->id]),
                'action' => __('site_settings.load.conversations.action'),
            ],
            [
                'label' => __('site_settings.load.open_tickets.label'),
                'value' => trans_choice('site_settings.load.open_tickets.count', $openTicketCount, ['count' => ReaderNumber::count($openTicketCount)]),
                'detail' => __('site_settings.load.open_tickets.detail'),
                'href' => route('dashboard.tickets.index', ['ticket_site' => $site->id]),
                'action' => __('site_settings.load.open_tickets.action'),
            ],
            [
                'label' => __('site_settings.load.pending_tickets.label'),
                'value' => trans_choice('site_settings.load.pending_tickets.count', $pendingTicketCount, ['count' => ReaderNumber::count($pendingTicketCount)]),
                'detail' => __('site_settings.load.pending_tickets.detail'),
                'href' => route('dashboard.tickets.index', [
                    'ticket_status' => 'pending',
                    'ticket_site' => $site->id,
                ]),
                'action' => __('site_settings.load.pending_tickets.action'),
            ],
            [
                'label' => __('site_settings.load.coverage.label'),
                'value' => trans_choice('site_settings.load.coverage.count', $supportAgentCount, ['count' => ReaderNumber::count($supportAgentCount)]),
                'detail' => $site->hasExplicitSupportAgents()
                    ? __('site_settings.load.coverage.explicit')
                    : __('site_settings.load.coverage.fallback'),
                'href' => route('dashboard.sites.show', $site).'#support-access-heading',
                'action' => __('site_settings.load.coverage.action'),
            ],
        ]);
    }

    /**
     * @param  array<int, int>  $supportAgentIds
     * @param  array<int, string>  $maskSelectors
     * @param  array{label: string, tone: string, detail: string, metrics: Collection<int, array{label: string, value: string, tone: string, href?: string|null, action?: string}>, status_counts: Collection<int, array{key: string, label: string, count: int, value: string}>, recent_failures: Collection<int, array{body_feedback: array<string, mixed>, status: string|null, occurred_at: Carbon|null}>}  $externalIssueHealth
     * @return Collection<int, array{label: string, value: string, tone: string, detail: string, href: string, action: string}>
     */
    private function siteSupportReadiness(Site $site, array $supportAgentIds, array $maskSelectors, array $externalIssueHealth): Collection
    {
        $installHealth = $this->localizedSiteInstallHealth($site->latestVisitor);
        $explicitSupport = $site->hasExplicitSupportAgents();
        $handoffProjectCount = $this->externalIssueHandoffProjectCount($site);

        return collect([
            [
                'label' => __('site_settings.readiness.items.install.label'),
                'value' => $installHealth['label'],
                'tone' => $installHealth['tone'],
                'detail' => $installHealth['needs_attention']
                    ? $installHealth['detail']
                    : __('site_settings.readiness.items.install.recent'),
                'href' => route('dashboard.sites.show', $site).'#install-verification',
                'action' => __('site_settings.readiness.items.install.action'),
            ],
            [
                'label' => __('site_settings.readiness.items.coverage.label'),
                'value' => $explicitSupport
                    ? __('site_settings.readiness.items.coverage.explicit')
                    : __('site_settings.readiness.items.coverage.fallback'),
                'tone' => $explicitSupport ? 'ready' : 'manual',
                'detail' => $explicitSupport
                    ? trans_choice('site_settings.readiness.items.coverage.assigned', count($supportAgentIds), ['count' => ReaderNumber::count(count($supportAgentIds))])
                    : __('site_settings.readiness.items.coverage.fallback_detail'),
                'href' => route('dashboard.sites.show', $site).'#support-access-heading',
                'action' => __('site_settings.readiness.items.coverage.action'),
            ],
            [
                'label' => __('site_settings.readiness.items.privacy.label'),
                'value' => count($maskSelectors) > 0
                    ? trans_choice('site_settings.readiness.items.privacy.configured', count($maskSelectors), ['count' => ReaderNumber::count(count($maskSelectors))])
                    : __('site_settings.readiness.items.privacy.none'),
                'tone' => count($maskSelectors) > 0 ? 'ready' : 'manual',
                'detail' => count($maskSelectors) > 0
                    ? __('site_settings.readiness.items.privacy.configured_detail')
                    : __('site_settings.readiness.items.privacy.none_detail'),
                'href' => route('dashboard.sites.show', $site).'#privacy-settings-heading',
                'action' => __('site_settings.readiness.items.privacy.action'),
            ],
            [
                'label' => __('site_settings.readiness.items.external.label'),
                'value' => $handoffProjectCount > 0
                    ? trans_choice('site_settings.readiness.items.external.mapped', $handoffProjectCount, ['count' => ReaderNumber::count($handoffProjectCount)])
                    : __('site_settings.readiness.items.external.none'),
                'tone' => $handoffProjectCount > 0 ? $externalIssueHealth['tone'] : 'manual',
                'detail' => $handoffProjectCount > 0
                    ? __('site_settings.readiness.items.external.mapped_detail')
                    : __('site_settings.readiness.items.external.none_detail'),
                'href' => route('dashboard.sites.show', $site).'#external-issue-routing-heading',
                'action' => __('site_settings.readiness.items.external.action'),
            ],
        ]);
    }

    private function externalIssueHandoffProjectCount(Site $site): int
    {
        return $site->externalIssueProjects
            ->filter(fn (SiteExternalIssueProject $project): bool => $project->supportsIssueCreationHandoff())
            ->count();
    }

    /** @return array{label: string, language: string|null} */
    private function localizedExternalIssueProviderParts(mixed $provider): array
    {
        if ($provider === 'other') {
            return [
                'label' => __('integrations.providers.other'),
                'language' => null,
            ];
        }

        if (! is_string($provider) || ! in_array($provider, ['github', 'gitlab', 'bitbucket', 'jira'], true)) {
            return [
                'label' => __('integrations.providers.external_tracker'),
                'language' => null,
            ];
        }

        return [
            'label' => ExternalIssueProvider::label($provider),
            'language' => '',
        ];
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     detail: string,
     *     metrics: Collection<int, array{label: string, value: string, tone: string, href?: string|null, action?: string}>,
     *     status_counts: Collection<int, array{key: string, label: string, count: int}>,
     *     recent_failures: Collection<int, array{body_feedback: array<string, mixed>, status: string|null, occurred_at: Carbon|null}>
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
            ->map(function (AuditEvent $event): array {
                $provider = ExternalIssueProvider::label(data_get($event->metadata, 'provider'));
                $storedProjectKey = data_get($event->metadata, 'project_key');
                $hasProjectKey = is_string($storedProjectKey) && trim($storedProjectKey) !== '';
                $project = $hasProjectKey
                    ? trim($storedProjectKey)
                    : __('site_settings.external.failures.unknown_project');

                return [
                    'body_feedback' => [
                        'key' => 'site_settings.external.failures.body',
                        'parameters' => array_filter([
                            'provider' => $provider,
                            'project' => $hasProjectKey ? $project : null,
                        ]),
                        ...($hasProjectKey ? [] : ['localized_parameters' => ['project' => $project]]),
                    ],
                    'status' => $this->externalIssueFailureStatus($event),
                    'occurred_at' => $event->occurred_at,
                ];
            });

        $failedCount = max((int) ($statusCounts[ExternalIssueSyncStatus::FAILED] ?? 0), $auditFailureCount);
        $pendingCount = (int) ($statusCounts[ExternalIssueSyncStatus::PENDING] ?? 0);
        $failedQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::FAILED] ?? 0);
        $pendingQueueCount = (int) ($queueStateCounts[TicketExternalIssueState::PENDING] ?? 0);
        $statusItems = collect(ExternalIssueSyncStatus::values())
            ->map(fn (string $status): array => [
                'key' => $status,
                'label' => __("site_settings.external.status_counts.{$status}.label"),
                'count' => $status === ExternalIssueSyncStatus::FAILED
                    ? $failedCount
                    : (int) ($statusCounts[$status] ?? 0),
            ])
            ->map(fn (array $item): array => [
                ...$item,
                'value' => trans_choice(
                    "site_settings.external.status_counts.{$item['key']}.count",
                    $item['count'],
                    ['count' => ReaderNumber::count($item['count'])],
                ),
            ])
            ->values();

        [$state, $tone] = match (true) {
            $mappedProjectCount === 0 => ['not_configured', 'manual'],
            $disabledProjectCount > 0 => ['disabled', 'attention'],
            $failedCount > 0 => ['failed', 'attention'],
            $pendingCount > 0 => ['pending', 'manual'],
            $handoffProjectCount === 0 => ['not_ready', 'manual'],
            default => ['ready', 'ready'],
        };

        return [
            'label' => __("site_settings.external.states.{$state}.label"),
            'tone' => $tone,
            'detail' => __("site_settings.external.states.{$state}.detail"),
            'metrics' => collect([
                [
                    'label' => __('site_settings.external.metrics.mapped.label'),
                    'value' => trans_choice('site_settings.external.metrics.mapped.count', $mappedProjectCount, ['count' => ReaderNumber::count($mappedProjectCount)]),
                    'tone' => $mappedProjectCount > 0 ? 'ready' : 'manual',
                ],
                [
                    'label' => __('site_settings.external.metrics.handoff.label'),
                    'value' => trans_choice('site_settings.external.metrics.handoff.count', $handoffProjectCount, ['count' => ReaderNumber::count($handoffProjectCount)]),
                    'tone' => $handoffProjectCount > 0 ? 'ready' : 'manual',
                ],
                [
                    'label' => __('site_settings.external.metrics.disabled.label'),
                    'value' => trans_choice('site_settings.external.metrics.disabled.count', $disabledProjectCount, ['count' => ReaderNumber::count($disabledProjectCount)]),
                    'tone' => $disabledProjectCount > 0 ? 'attention' : 'ready',
                ],
                [
                    'label' => __('site_settings.external.metrics.failed.label'),
                    'value' => trans_choice('site_settings.external.metrics.failed.count', $failedCount, ['count' => ReaderNumber::count($failedCount)]),
                    'tone' => $failedCount > 0 ? 'attention' : 'ready',
                    'href' => $failedQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_site' => $site->id,
                            'ticket_external' => 'failed',
                        ])
                        : null,
                    'action' => __('site_settings.external.metrics.failed.action'),
                ],
                [
                    'label' => __('site_settings.external.metrics.pending.label'),
                    'value' => trans_choice('site_settings.external.metrics.pending.count', $pendingCount, ['count' => ReaderNumber::count($pendingCount)]),
                    'tone' => $pendingCount > 0 ? 'manual' : 'ready',
                    'href' => $pendingQueueCount > 0
                        ? route('dashboard.tickets.index', [
                            'ticket_status' => 'all',
                            'ticket_site' => $site->id,
                            'ticket_external' => 'pending',
                        ])
                        : null,
                    'action' => __('site_settings.external.metrics.pending.action'),
                ],
            ]),
            'status_counts' => $statusItems,
            'recent_failures' => $recentFailures,
        ];
    }

    private function externalIssueFailureStatus(AuditEvent $event): ?string
    {
        $status = data_get($event->metadata, 'status');

        if (is_int($status) || (is_string($status) && preg_match('/^\d{3}$/', $status))) {
            return (string) $status;
        }

        if (is_string($status) && preg_match('/^[A-Za-z0-9 _.-]{1,40}$/', $status)) {
            return $status;
        }

        return null;
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'updatePrivacy', $site);

        $validated = $request->validate([
            'mask_selectors' => ['nullable', 'string', 'max:4000'],
            'mask_terms' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = $site->mutateSettings(function (array $settings) use ($validated): array {
            $settings['mask_selectors'] = $this->parseMaskSelectors($validated['mask_selectors'] ?? '');
            $settings['mask_terms'] = $this->parseMaskTerms($validated['mask_terms'] ?? '');

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.privacy_saved');
    }

    /**
     * Edit a site's name and domain.
     *
     * Kept apart from update() so the privacy form and the details form cannot
     * blank each other's fields by omission.
     */
    /**
     * @return array<string, array{open: bool, from: string, to: string}>
     */
    private function availabilityWeekdaysForForm(Site $site): array
    {
        $stored = is_array($site->settings['availability']['weekdays'] ?? null)
            ? $site->settings['availability']['weekdays']
            : [];

        $weekdays = [];

        foreach (SiteAvailability::DAYS as $day) {
            $hours = $stored[$day] ?? null;
            $open = is_array($hours) && isset($hours[0], $hours[1]);

            $weekdays[$day] = [
                'open' => $open,
                'from' => $open ? (string) $hours[0] : '09:00',
                'to' => $open ? (string) $hours[1] : '17:00',
            ];
        }

        return $weekdays;
    }

    /**
     * Set what a visitor is asked before the conversation starts.
     *
     * Its own method for the same reason the others are: one form must not be
     * able to blank another's fields by omitting them.
     */
    /**
     * Whether this site asks a visitor how it went.
     *
     * Off until an operator turns it on, and phrased in the form as a question
     * the desk asks rather than a metric it collects -- because that is what
     * the visitor experiences. A prompt nobody chose to show is an interruption
     * at the least welcome moment.
     */
    public function updateRating(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'rating_enabled' => ['nullable', 'boolean'],
            'rating_intro' => ['nullable', 'string', 'max:160'],
        ]);

        $settings = $site->mutateSettings(function (array $settings) use ($validated): array {
            $settings['rating'] = [
                'enabled' => (bool) ($validated['rating_enabled'] ?? false),
                'intro' => trim((string) ($validated['rating_intro'] ?? '')) ?: null,
            ];

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.rating_saved');
    }

    public function updateIntake(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'intake_intro' => ['nullable', 'string', 'max:300'],
            'intake_fields' => ['nullable', 'array'],
            'intake_fields.*' => [Rule::in([SiteIntake::OFF, SiteIntake::OPTIONAL, SiteIntake::REQUIRED])],
        ]);

        $fields = [];

        foreach (SiteIntake::FIELDS as $field) {
            $fields[$field] = $validated['intake_fields'][$field] ?? SiteIntake::OFF;
        }

        $settings = $site->mutateSettings(function (array $settings) use ($fields, $validated): array {
            $settings['intake'] = [
                'fields' => $fields,
                'intro' => trim((string) ($validated['intake_intro'] ?? '')) ?: null,
            ];

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.intake_saved');
    }

    /**
     * Who is on this site right now.
     *
     * Its own page rather than a tab on the visitor directory, because the two
     * answer different questions and the difference is the point: the directory
     * is people, ordered by any contact of any kind, and this is a moment. A
     * board that shared the directory's ordering would put somebody who emailed
     * an hour ago above somebody reading a page this second.
     */
    public function live(Request $request, Site $site): View
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);

        $agent = $request->user();

        // Read as of NOW, not as of route binding.
        //
        // The whole revocation path downstream depends on this response
        // dropping its `[data-live-rows]` element -- that absence is what tells
        // an open board to clear itself. Built from the model the route
        // resolved, a revocation committing in between rendered a full board,
        // rows element and all, so the resync that fetched this page saw
        // nothing wrong and carried on showing visitors.
        $site = Site::query()->whereKey($site->getKey())->first() ?? $site;

        $reporting = SitePresenceReporting::for($site);

        $snapshot = $reporting->enabled && ! $site->isArchived()
            ? LiveVisitorBoard::snapshotFor($site)
            : ['visitors' => collect(), 'total' => 0];

        return view('agent.sites.live', [
            'agent' => $agent,
            'account' => $agent?->account,
            'site' => $site,
            'reporting' => $reporting,
            // Empty when the site does not watch, rather than "whoever the
            // query happens to match". Contacted visitors keep reporting
            // through bootstrap and message fetches, so an unguarded query
            // would put a nonzero count above a paragraph explaining that the
            // board stays empty by design.
            'visitors' => $snapshot['visitors'],
            // From the SAME read as the rows. Asked separately, a visitor
            // committing between the two landed in the count and not in the
            // table -- and the browser then counted them again when the
            // buffered socket event replayed them as an arrival.
            //
            // Still uncapped past 200: the list stops there so one page stays
            // readable, and telling an agent "200" when four hundred people
            // are on the site is the one number here they would have taken at
            // face value.
            'presentCount' => $snapshot['total'],
            'presentMinutes' => LiveVisitorBoard::PRESENT_MINUTES,
            'canUpdatePrivacy' => Gate::forUser($agent)->allows('updatePrivacy', $site),
            'realtime' => $this->presenceRealtimeConfig($site),
            // Words for the script, chosen here. The socket carries a state
            // and this page picks the sentence, which is the same rule the
            // conversation presence payload follows: a payload broadcast to
            // every agent watching cannot know which language each of them
            // reads.
            'presenceLabels' => collect(VisitorPresence::states())
                // From the CATALOGUE, not from the support class. The class
                // deliberately answers in English because it can be reached
                // where no request has scoped a locale; a surface translating a
                // state is the only thing that may consult `presence.php`.
                ->mapWithKeys(fn (string $state): array => [$state => __('presence.'.$state)])
                ->all(),
        ]);
    }

    /**
     * What the board needs to open a socket, or null if it cannot.
     *
     * Null disables the script entirely and the page stays what the server
     * rendered -- correct at load, going stale quietly, which is a better
     * failure than a board that looks live and is not.
     *
     * @return array<string, mixed>|null
     */
    private function presenceRealtimeConfig(Site $site): ?array
    {
        // An archived site has no board to subscribe to: SitePresenceChannel
        // queries `servable()` and refuses every authorization. Handing the
        // page a config anyway meant the socket opened, the auth failed, the
        // reconnect fired, and the agent watched "Reconnecting to live
        // updates" for as long as they left the tab open -- retrying something
        // that is refused by design and will never succeed.
        if ($site->isArchived()) {
            return null;
        }

        if ((string) config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $key = config('broadcasting.connections.reverb.key');
        // The CLIENT host, falling back to the server one. In a containerised
        // install the server-side address is an internal service name the
        // browser cannot resolve, which is why the agent conversation page
        // reads these the same way.
        $host = config('broadcasting.connections.reverb.options.client_host')
            ?? config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.client_port')
            ?? config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.client_scheme')
            ?? config('broadcasting.connections.reverb.options.scheme');

        foreach ([$key, $host, $port, $scheme] as $value) {
            if (! is_scalar($value) || (string) $value === '') {
                return null;
            }
        }

        return [
            'appKey' => (string) $key,
            'authEndpoint' => url('/broadcasting/auth'),
            'channelName' => 'private-sites.'.$site->id.'.presence',
            'host' => (string) $host,
            'port' => (string) $port,
            'scheme' => (string) $scheme,
            'eventName' => 'visitor.presence.updated',
            'presentMinutes' => LiveVisitorBoard::PRESENT_MINUTES,
            // How many rows the server will ever render. The board needs it to
            // know whether its own row count is the whole truth: at or below
            // this, every visitor counted is on the page and a departure really
            // does lower the total. Above it, the rows are a window and the
            // total has to come from the server.
            'displayLimit' => LiveVisitorBoard::DISPLAY_LIMIT,
        ];
    }

    /**
     * Turn presence reporting on or off for this site (ADR 0019 §1).
     *
     * Gated on `updatePrivacy` rather than `update`, because this is not a
     * preference about how the widget looks. It decides whether the install
     * records people who never asked it to -- somebody who lands on a pricing
     * page and leaves. ADR 0019 makes that an operator's decision to take
     * deliberately, so it belongs behind the same gate as the masking rules and
     * off until somebody chooses it.
     */
    public function updatePresence(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'updatePrivacy', $site);

        $validated = $request->validate([
            'presence_enabled' => ['nullable', 'boolean'],
            'presence_page_urls' => ['nullable', 'boolean'],
        ]);

        $enabled = (bool) ($validated['presence_enabled'] ?? false);
        $pageUrls = (bool) ($validated['presence_page_urls'] ?? false);
        $removed = 0;

        // The settings write and the cleanup are ONE locked transaction, which
        // is what mutateSettings() is for. A heartbeat in flight takes the same
        // lock before it writes, so revoking cannot pass over a row a request
        // already on its way then creates -- and no other settings form can
        // save its stale copy of this column afterwards and put the revoked
        // value back.
        $site->mutateSettings(function (array $settings) use ($site, $enabled, $pageUrls, &$removed): array {
            $settings['presence'] = ['enabled' => $enabled, 'page_urls' => $pageUrls];

            // Switching presence off is a revocation, so the rows it collected
            // go. Leaving them to age out over thirty days would mean the
            // visitor directory still listing people who never made contact on
            // a site whose operator has just said not to watch them. Only rows
            // this feature created and nobody has since been in touch through:
            // somebody who arrived as a heartbeat and later wrote in stays.
            if (! $enabled) {
                $removed = $this->forgetPresenceOnlyVisitors($site);
            }

            return $settings;
        });

        // Addresses go whenever the switch is off, not only while presence is
        // on. Contacted visitors are kept and they hold addresses too, written
        // by bootstrap and conversation start, so an operator unchecking this
        // box while switching presence off would otherwise keep exactly the
        // addresses they unchecked it for. "From now on" is the wrong scope for
        // a control that exists because a path held a secret.
        //
        // AFTER the settings transaction, not inside it, and this is the safe
        // order rather than the convenient one. Every writer of an address
        // reads the setting under the site lock, so once the revocation has
        // COMMITTED no further address can be written -- and a request that
        // committed before it is exactly what this sweep is here to clean up.
        // Running inside meant one transaction holding a row lock per visitor
        // across the whole table while an operator waited on a form post.
        //
        // It is still synchronous, and on a very large site it is still slow.
        // Chunked with a transaction per chunk, so the locks are short and
        // another request can interleave; a site with hundreds of thousands of
        // presence rows will want this on a queue, which is a change to make
        // when somebody has one.
        if (! $pageUrls) {
            $this->forgetStoredPageUrls($site);
        }

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', $this->presenceStatusMessage($enabled, $removed));
    }

    /** @return array{key: string, count: int}|string */
    private function presenceStatusMessage(bool $enabled, int $removed): array|string
    {
        if ($enabled) {
            return 'site_settings.flash.presence_on';
        }

        if ($removed === 0) {
            return 'site_settings.flash.presence_off';
        }

        return [
            'key' => $removed === 1
                ? 'site_settings.flash.presence_off_removed_one'
                : 'site_settings.flash.presence_off_removed_many',
            'count' => $removed,
        ];
    }

    /**
     * Drop every page address this site has stored for its visitors.
     *
     * Only the visitor rows. A conversation's `started_page_url` is part of a
     * support record somebody wrote in about, and deleting history because a
     * collection setting changed is a different decision from the one the
     * operator just took.
     */
    private function forgetStoredPageUrls(Site $site): void
    {
        Visitor::query()
            ->where('site_id', $site->id)
            ->whereNotNull('metadata')
            // Only rows that actually hold an address. Without this the sweep
            // takes a lock and an update for EVERY visitor on the site, inside
            // the operator's own request -- and on an established site that is
            // most of the table for the sake of the few rows that have one.
            //
            // A LIKE rather than a JSON path, so the two drivers run the same
            // query. It over-matches harmlessly: a row whose key is present but
            // already null re-saves unchanged.
            //
            // `metadata` is a `json` column and PostgreSQL has no LIKE operator
            // for that type -- but Laravel's Postgres grammar appends `::text`
            // to any operator containing "like", so this emits
            // `"metadata"::text like ?` and works. Verified against PostgreSQL
            // 16 rather than assumed; do not "fix" it into a whereRaw CAST,
            // which only hardcodes what the grammar already does.
            ->where('metadata', 'like', '%last_page_url%')
            ->chunkById(200, function ($visitors) use ($site): bool {
                $superseded = false;

                // A transaction per chunk. Each row is re-read under its own
                // lock before writing, so the lock is held for one row's work
                // rather than for the length of the sweep.
                DB::transaction(function () use ($visitors, $site, &$superseded): void {
                    // Is this revocation still the current one?
                    //
                    // The sweep runs after its settings transaction commits and
                    // therefore holds no site lock, so a second operator can
                    // turn addresses back on while it walks the table --
                    // whereupon heartbeats legitimately store addresses again
                    // and this sweep, still going, deletes them as it reaches
                    // them. The operator who re-enabled watches addresses
                    // appear and vanish for as long as the older sweep lasts.
                    //
                    // Read under the SHARED lock every other reader takes, so
                    // the answer is either wholly before or wholly after a
                    // settings write, never halfway through one. Site before
                    // visitor, the same order as the heartbeat and the settings
                    // form, so this cannot deadlock against either.
                    $current = Site::query()->whereKey($site->getKey())->sharedLock()->first();

                    if ($current === null || SitePresenceReporting::for($current)->pageUrls) {
                        $superseded = true;

                        return;
                    }

                    foreach ($visitors as $visitor) {
                        // Re-read under a lock before writing. `metadata` is one
                        // JSON column, so a save replaces the whole value: writing
                        // the copy this loop read would erase host context that
                        // bootstrap or a conversation committed in between -- and
                        // this runs at exactly the moment such writes are likely,
                        // because operators change this setting on a live site.
                        $locked = Visitor::query()->whereKey($visitor->getKey())->lockForUpdate()->first();

                        if ($locked === null) {
                            continue;
                        }

                        $metadata = is_array($locked->metadata) ? $locked->metadata : [];

                        if (! array_key_exists('last_page_url', $metadata)) {
                            continue;
                        }

                        unset($metadata['last_page_url']);

                        $locked->forceFill(['metadata' => $metadata])->save();
                    }
                });

                // Stops the walk, rather than skipping one chunk of it.
                return ! $superseded;
            });
    }

    private function forgetPresenceOnlyVisitors(Site $site): int
    {
        return Visitor::query()
            ->where('site_id', $site->id)
            ->where('presence_only', true)
            ->whereDoesntHave('conversations')
            ->whereDoesntHave('tickets')
            ->delete();
    }

    /**
     * Choose the language this site's widget speaks by default.
     *
     * Its own method for the same reason the others are: one form must not be
     * able to blank another's fields by omitting them.
     */
    public function updateLanguage(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'widget_locale' => ['nullable', 'string', Rule::in(array_keys(WidgetLanguage::SUPPORTED))],
        ]);

        $settings = $site->mutateSettings(function (array $settings) use ($validated): array {
            // Null rather than an empty string, so "not configured" is one value
            // and the widget can tell it from a language it does not carry.
            $settings['locale'] = WidgetLanguage::sanitize($validated['widget_locale'] ?? null);

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.language_saved');
    }

    /**
     * Set when the support desk is open for this site.
     *
     * Its own method for the same reason updateDetails() is: one form must not
     * be able to blank another's fields by omitting them.
     */
    public function updateAvailability(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'availability_enabled' => ['nullable', 'boolean'],
            'availability_timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            // Operator-authored, so an install in any language tells its own
            // visitors why nobody is answering. Hardcoding this in the widget
            // would make it an English string needing extraction later.
            'availability_away_message' => ['nullable', 'string', 'max:500'],
            'availability_open' => ['nullable', 'array'],
            'availability_open.*' => ['nullable', 'string'],
            'availability_from' => ['nullable', 'array'],
            'availability_from.*' => ['nullable', 'date_format:H:i'],
            'availability_to' => ['nullable', 'array'],
            'availability_to.*' => ['nullable', 'date_format:H:i'],
        ]);

        $weekdays = [];

        foreach (SiteAvailability::DAYS as $day) {
            // filter_var, not a cast: the hidden partner input submits the
            // string "0", and (bool) "0" is false only by luck of PHP's rules
            // while (bool) "false" would be true. Read it as a flag.
            $isOpen = filter_var($validated['availability_open'][$day] ?? false, FILTER_VALIDATE_BOOL);
            $from = $validated['availability_from'][$day] ?? null;
            $to = $validated['availability_to'][$day] ?? null;

            // A day is only open if it carries a usable pair. SiteAvailability
            // discards a malformed range anyway; rejecting it here means the
            // operator sees the day is closed instead of believing otherwise.
            $weekdays[$day] = $isOpen && is_string($from) && is_string($to) && $from < $to
                ? [$from, $to]
                : null;
        }

        $settings = $site->mutateSettings(function (array $settings) use ($validated, $weekdays): array {
            $availability = is_array($settings['availability'] ?? null) ? $settings['availability'] : [];

            $settings['availability'] = [
                'enabled' => filter_var($validated['availability_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'timezone' => $validated['availability_timezone'],
                'weekdays' => $weekdays,
                'away_message' => trim((string) ($validated['availability_away_message'] ?? '')) ?: null,
                // Preserved rather than rewritten: editing the schedule is not the
                // same action as reopening a desk somebody closed early.
                'closed_until' => $availability['closed_until'] ?? null,
            ];

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.hours_saved');
    }

    /**
     * Close the desk early, without touching the schedule.
     *
     * Separate from updateAvailability() because they are different actions by
     * different people at different moments: one is configuration, this is
     * "something came up, we are stepping out". Folding it into the schedule
     * form would mean an operational close required editing hours nobody meant
     * to change.
     */
    public function closeAvailability(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'closure' => ['required', 'string', Rule::in(SiteAvailability::CLOSURES)],
        ]);

        $endsAt = SiteAvailability::closureEndsAt($site, $validated['closure']);

        if ($endsAt === null) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->with('status', 'site_settings.flash.desk_left_open');
        }

        $this->storeClosure($site, $endsAt->toIso8601String());

        // Report when the desk is BACK, which is not always when the close
        // expires: one ending outside opening hours hands back to the schedule
        // rather than to that moment. "Rest of today" ends at closing time, so
        // it is outside hours by definition and the two always differ.
        $reopens = SiteAvailability::for($site)->opensAt;

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', $reopens === null
                ? 'site_settings.flash.desk_closed_no_return'
                : [
                    'key' => 'site_settings.flash.desk_closed_return',
                    'reopens_at' => $reopens->toIso8601String(),
                ]);
    }

    /**
     * Hand the desk back before the close would have expired.
     */
    public function reopenAvailability(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $this->storeClosure($site, null);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.desk_reopened');
    }

    /**
     * Write only `closed_until`, leaving the rest of the schedule alone.
     *
     * The mirror of updateAvailability() preserving this field: neither action
     * may quietly rewrite the other's, or closing early would blank the hours
     * and reopening would restore a schedule nobody asked for.
     */
    private function storeClosure(Site $site, ?string $closedUntil): void
    {
        $settings = $site->mutateSettings(function (array $settings) use ($closedUntil): array {
            $availability = is_array($settings['availability'] ?? null) ? $settings['availability'] : [];

            $availability['closed_until'] = $closedUntil;
            $settings['availability'] = $availability;

            return $settings;
        });
    }

    /**
     * What this site's widget looks like and says to its own visitors.
     *
     * Its own method for the reason updateAvailability() is: one form must not
     * be able to blank another's fields by omitting them.
     */
    /**
     * The address visitors write to for this site.
     *
     * Its own action, like the schedule and the appearance beside it: one form
     * must not be able to blank another's fields by omitting them. Without this
     * the column existed and nothing ever populated it, so every delivery was
     * ignored and the channel was unreachable without editing the database.
     */
    public function updateInboundAddress(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'inbound_address' => ['nullable', 'string', 'email:filter', 'max:255'],
        ]);

        // Normalised before the uniqueness check, or Support@x and support@x
        // are two addresses to the database and one to every mail server.
        $address = strtolower(trim((string) ($validated['inbound_address'] ?? '')));

        if ($address !== '' && Site::query()
            ->whereKeyNot($site->getKey())
            ->whereRaw('LOWER(inbound_address) = ?', [$address])
            ->exists()) {
            throw ValidationException::withMessages([
                'inbound_address' => __('site_settings.validation.inbound_unique'),
            ]);
        }

        $site->forceFill(['inbound_address' => $address === '' ? null : $address])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', $address === '' ? 'site_settings.flash.inbound_off' : 'site_settings.flash.inbound_saved');
    }

    public function updateAppearance(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'widget_accent' => ['nullable', 'string', 'max:9'],
            'widget_position' => ['required', 'string', Rule::in(WidgetAppearance::POSITIONS)],
            'widget_greeting' => ['nullable', 'string', 'max:120'],
            'widget_placeholder' => ['nullable', 'string', 'max:120'],
        ]);

        $accent = trim((string) ($validated['widget_accent'] ?? ''));

        if ($accent !== '' && WidgetAppearance::accentRejection($accent) !== null) {
            throw ValidationException::withMessages(['widget_accent' => __('site_settings.validation.widget_accent')]);
        }

        $settings = $site->mutateSettings(function (array $settings) use ($accent, $validated): array {

            $settings['appearance'] = [
                'accent' => $accent === '' ? null : $accent,
                'position' => $validated['widget_position'],
                'greeting' => trim((string) ($validated['widget_greeting'] ?? '')) ?: null,
                'placeholder' => trim((string) ($validated['widget_placeholder'] ?? '')) ?: null,
            ];

            return $settings;
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.appearance_saved');
    }

    public function updateDetails(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'update', $site);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255'],
            // Constrained to the palette rather than accepting a colour value.
            // The stored key is interpolated into a CSS custom property name on
            // three surfaces, so an unvalidated value would be an injection
            // point as well as a broken accent.
            //
            // `sometimes`, not `required`: this method exists precisely so one
            // form cannot blank another's fields by omission, and a caller that
            // never mentions colour should leave it alone rather than fail.
            'color' => ['sometimes', Rule::enum(SiteColor::class)],
        ]);

        $before = [
            'name' => $site->name,
            'domain' => $site->domain,
            'color' => $site->resolvedColor()->value,
        ];

        $site->forceFill([
            'name' => trim($validated['name']),
            'domain' => $this->normalizeDomain($validated['domain'] ?? null),
            'color' => isset($validated['color'])
                ? SiteColor::from($validated['color'])
                : $site->resolvedColor(),
        ])->save();

        $after = [
            'name' => $site->name,
            'domain' => $site->domain,
            'color' => $site->resolvedColor()->value,
        ];

        if ($before !== $after) {
            $this->recordSiteAudit($site, $request->user(), 'site.details_updated', [
                'before' => $before,
                'after' => $after,
            ]);
        }

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.details_saved');
    }

    /**
     * Take a site out of service without destroying anything.
     *
     * The widget stops resolving the site immediately - see WidgetSiteResolver -
     * and the site leaves the working lists, but every conversation, ticket and
     * audit event stays exactly where it was. Reversible via unarchive().
     */
    public function archive(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'archive', $site);

        if ($site->isArchived()) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->with('status', 'site_settings.flash.already_archived');
        }

        $site->forceFill(['archived_at' => now()])->save();

        // Record the scale of what just stopped serving: an operator reading
        // this later wants to know whether a live site was taken down.
        $this->recordSiteAudit($site, $request->user(), 'site.archived', [
            'conversations' => $site->conversations()->count(),
            'tickets' => $site->tickets()->count(),
        ]);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.archived');
    }

    public function unarchive(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'archive', $site);

        if (! $site->isArchived()) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->with('status', 'site_settings.flash.not_archived');
        }

        $site->forceFill(['archived_at' => null])->save();

        $this->recordSiteAudit($site, $request->user(), 'site.unarchived', []);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.restored');
    }

    /**
     * Irreversibly destroy a site and everything beneath it.
     */
    public function purge(Request $request, Site $site, SitePurge $purge): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'purge', $site);

        if ($reason = $this->purgeBlockedReason($site)) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->withErrors(['confirm_name' => $reason]);
        }

        // Typing the name is the last thing standing between an operator and a
        // cascade they cannot undo, so it is validated as a hard match.
        $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if ($request->string('confirm_name')->trim()->value() !== $site->name) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->withErrors(['confirm_name' => __('site_settings.validation.purge_name')]);
        }

        $summary = $purge->purge($site, $request->user());

        return redirect()
            ->route('dashboard.sites.index')
            ->with('status', [
                'key' => 'sites.flash.purged',
                'parameters' => ['site' => $site->name],
                // Raw until the destination page renders. The translated
                // directory belongs to its reader; formatting these during
                // the write would freeze one request's language into the
                // flash instead.
                'counts' => $summary,
            ]);
    }

    /**
     * Why this site may not be purged right now, or null when it may be.
     *
     * A site must be archived before it can be destroyed. Archiving has already
     * taken the widget out of service, so purging cannot pull the ground from
     * under a visitor who is part-way through a conversation, and retiring a
     * site becomes two deliberate steps rather than one irreversible click.
     * It still allows a same-day deletion obligation to be met: archive, then
     * purge.
     */
    private function purgeBlockedReason(Site $site): ?string
    {
        if (! $site->isArchived()) {
            return __('site_settings.validation.purge_archived');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordSiteAudit(Site $site, User $actor, string $action, array $metadata): void
    {
        $site->auditEvents()->create([
            'account_id' => $site->account_id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
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
            'support_agent_ids.required' => __('site_settings.validation.support_required'),
            'support_agent_ids.min' => __('site_settings.validation.support_required'),
            'support_agent_ids.*.in' => __('site_settings.validation.support_account'),
        ]);

        $beforeAgentIds = $this->eligibleSupportAgentIds($site);
        $afterAgentIds = $this->normalizeAgentIds($validated['support_agent_ids']);

        if (! $this->hasAssignedSiteManager($site, $afterAgentIds)) {
            throw ValidationException::withMessages([
                'support_agent_ids' => __('site_settings.validation.support_manager'),
            ]);
        }

        $site->supportAgents()->sync($afterAgentIds);

        if ($beforeAgentIds !== $afterAgentIds) {
            $this->recordSiteAccessChange($site, $request->user(), $beforeAgentIds, $afterAgentIds);
        }

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.access_saved');
    }

    private function authorizeSiteAbility(Request $request, string $ability, Site $site, int $status = 403): void
    {
        $agent = $request->user();

        abort_unless($agent && Gate::forUser($agent)->allows($ability, $site), $status);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array{0: Collection<int, Site>, 1: array{search: string, workload: string, install: string, state: string, workload_options: array<string, string>, install_options: array<string, string>, state_options: array<string, string>, active: list<array{label: string, value: string, value_is_authored: bool}>, has_active_filters: bool, visible_count: int, result_count: int, summary_label: string}}
     */
    private function siteMatchesStateFilter(Site $site, string $state): bool
    {
        return match ($state) {
            'archived' => $site->isArchived(),
            'all' => true,
            default => ! $site->isArchived(),
        };
    }

    private function filteredSites(Collection $sites, Request $request): array
    {
        $stateOptions = [
            'active' => __('sites.index.filters.options.state.active_sites'),
            'archived' => __('sites.index.filters.options.state.archived'),
            'all' => __('sites.index.filters.options.state.all'),
        ];
        $state = $this->normalizeSiteFilter(
            $this->stringQuery($request, 'site_state', 'active'),
            array_keys($stateOptions),
        );

        // Applied before the visible count so "N visible" describes the set the
        // operator is actually looking at, rather than counting retired sites
        // the page is not showing.
        $sites = $sites
            ->filter(fn (Site $site): bool => $this->siteMatchesStateFilter($site, $state))
            ->values();

        $visibleCount = $sites->count();
        $workloadOptions = [
            'all' => __('sites.index.filters.options.workload.all'),
            'active' => __('sites.index.filters.options.workload.active'),
            'quiet' => __('sites.index.filters.options.workload.without_work'),
        ];
        $installOptions = [
            'all' => __('sites.index.filters.options.install.all'),
            'needs_attention' => __('sites.index.filters.options.install.needs_attention'),
            'live' => __('sites.index.filters.options.install.live'),
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
            $activeFilters[] = [
                'label' => __('sites.index.filters.search'),
                'value' => $search,
                'value_is_authored' => true,
            ];
        }

        if ($workload !== 'all') {
            $activeFilters[] = [
                'label' => __('sites.index.filters.workload'),
                'value' => $workloadOptions[$workload],
                'value_is_authored' => false,
            ];
        }

        if ($install !== 'all') {
            $activeFilters[] = [
                'label' => __('sites.index.filters.install'),
                'value' => $installOptions[$install],
                'value_is_authored' => false,
            ];
        }

        // 'active' is the default view rather than a filter the operator chose,
        // so it earns no chip; the other two are worth announcing.
        if ($state !== 'active') {
            $activeFilters[] = [
                'label' => __('sites.index.filters.state'),
                'value' => $stateOptions[$state],
                'value_is_authored' => false,
            ];
        }

        $resultCount = $filteredSites->count();
        $hasActiveFilters = $activeFilters !== [];

        return [
            $filteredSites,
            [
                'search' => $search,
                'workload' => $workload,
                'install' => $install,
                'state' => $state,
                'workload_options' => $workloadOptions,
                'install_options' => $installOptions,
                'state_options' => $stateOptions,
                'active' => $activeFilters,
                'has_active_filters' => $hasActiveFilters,
                'visible_count' => $visibleCount,
                'result_count' => $resultCount,
                'summary_label' => $hasActiveFilters
                    ? trans_choice('sites.index.filters.summary.shown', $resultCount, [
                        'shown' => ReaderNumber::count($resultCount),
                        'visible' => ReaderNumber::count($visibleCount),
                    ])
                    : trans_choice('sites.index.filters.summary.visible', $visibleCount, [
                        'count' => ReaderNumber::count($visibleCount),
                    ]),
            ],
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<array{label: string, value: string, detail: string, href: string|null, action: string|null}>
     */
    private function siteOperationsSnapshot(Collection $sites): array
    {
        $visibleCount = $sites->count();
        $activeSiteCount = $sites
            ->filter(fn (Site $site): bool => $this->siteHasActiveWorkload($site))
            ->count();
        $openConversationCount = $sites->sum(fn (Site $site): int => (int) $site->open_conversations_count);
        $openTicketCount = $sites->sum(fn (Site $site): int => (int) $site->open_tickets_count);
        $pendingTicketCount = $sites->sum(fn (Site $site): int => (int) $site->pending_tickets_count);
        $installAttentionCount = $sites
            ->filter(fn (Site $site): bool => SiteInstallHealth::fromVisitor($site->latestVisitor)['needs_attention'])
            ->count();
        $explicitAccessCount = $sites
            ->filter(fn (Site $site): bool => ((int) $site->support_agents_count) > 0)
            ->count();
        $fallbackAccessCount = max(0, $visibleCount - $explicitAccessCount);

        return [
            [
                'label' => __('sites.index.snapshot.visible.label'),
                'value' => trans_choice('sites.index.snapshot.visible.value', $visibleCount, [
                    'count' => ReaderNumber::count($visibleCount),
                ]),
                'detail' => __('sites.index.snapshot.visible.detail'),
                'href' => route('dashboard.sites.index'),
                'action' => __('sites.index.snapshot.visible.action'),
            ],
            [
                'label' => __('sites.index.snapshot.workload.label'),
                'value' => trans_choice('sites.index.snapshot.workload.value', $activeSiteCount, [
                    'count' => ReaderNumber::count($activeSiteCount),
                ]),
                'detail' => __('sites.index.snapshot.workload.detail', [
                    'conversations' => trans_choice('sites.index.counts.open_conversations', $openConversationCount, ['count' => ReaderNumber::count($openConversationCount)]),
                    'open_tickets' => trans_choice('sites.index.counts.open_tickets', $openTicketCount, ['count' => ReaderNumber::count($openTicketCount)]),
                    'pending_tickets' => trans_choice('sites.index.counts.pending_tickets', $pendingTicketCount, ['count' => ReaderNumber::count($pendingTicketCount)]),
                ]),
                'href' => route('dashboard.sites.index', ['site_workload' => 'active']),
                'action' => __('sites.index.snapshot.workload.action'),
            ],
            [
                'label' => __('sites.index.snapshot.install.label'),
                'value' => trans_choice('sites.index.snapshot.install.value', $installAttentionCount, [
                    'count' => ReaderNumber::count($installAttentionCount),
                ]),
                'detail' => __('sites.index.snapshot.install.detail'),
                'href' => route('dashboard.sites.index', ['site_install' => 'needs_attention']),
                'action' => __('sites.index.snapshot.install.action'),
            ],
            [
                'label' => __('sites.index.snapshot.access.label'),
                'value' => trans_choice('sites.index.snapshot.access.value', $explicitAccessCount, [
                    'count' => ReaderNumber::count($explicitAccessCount),
                ]),
                'detail' => trans_choice('sites.index.snapshot.access.detail', $fallbackAccessCount, [
                    'count' => ReaderNumber::count($fallbackAccessCount),
                ]),
                'href' => null,
                'action' => null,
            ],
        ];
    }

    /**
     * @param  array{search: string, workload: string, install: string, state: string, workload_options: array<string, string>, install_options: array<string, string>, state_options: array<string, string>, active: list<array{label: string, value: string, value_is_authored: bool}>, has_active_filters: bool, visible_count: int, result_count: int, summary_label: string}  $siteFilters
     * @return array{heading: array{key: string, parameters: array<string, string>}, detail: string, actions: list<array{label: string, url: string}>}
     */
    private function siteEmptyState(array $siteFilters): array
    {
        $actions = $siteFilters['has_active_filters']
            ? [['label' => __('sites.index.empty.actions.clear_all'), 'url' => route('dashboard.sites.index')]]
            : [['label' => __('sites.add_site'), 'url' => route('dashboard.sites.create')]];

        if ($siteFilters['search'] !== '') {
            array_unshift($actions, [
                'label' => __('sites.index.empty.actions.clear_search'),
                'url' => $this->siteFilterUrl($siteFilters, ['search' => '']),
            ]);

            return [
                'heading' => [
                    'key' => 'sites.index.empty.search.heading',
                    'parameters' => ['search' => $siteFilters['search']],
                ],
                'detail' => __('sites.index.empty.search.detail'),
                'actions' => $actions,
            ];
        }

        if ($siteFilters['install'] === 'needs_attention') {
            array_unshift($actions, [
                'label' => __('sites.index.empty.actions.clear_install'),
                'url' => $this->siteFilterUrl($siteFilters, ['install' => 'all']),
            ]);

            return [
                'heading' => ['key' => 'sites.index.empty.install_attention.heading', 'parameters' => []],
                'detail' => __('sites.index.empty.install_attention.detail'),
                'actions' => $actions,
            ];
        }

        if ($siteFilters['install'] === 'live') {
            array_unshift($actions, [
                'label' => __('sites.index.empty.actions.clear_install'),
                'url' => $this->siteFilterUrl($siteFilters, ['install' => 'all']),
            ]);

            return [
                'heading' => ['key' => 'sites.index.empty.live.heading', 'parameters' => []],
                'detail' => __('sites.index.empty.live.detail'),
                'actions' => $actions,
            ];
        }

        if ($siteFilters['workload'] === 'active') {
            array_unshift($actions, [
                'label' => __('sites.index.empty.actions.clear_workload'),
                'url' => $this->siteFilterUrl($siteFilters, ['workload' => 'all']),
            ]);

            return [
                'heading' => ['key' => 'sites.index.empty.workload_active.heading', 'parameters' => []],
                'detail' => __('sites.index.empty.workload_active.detail'),
                'actions' => $actions,
            ];
        }

        if ($siteFilters['workload'] === 'quiet') {
            array_unshift($actions, [
                'label' => __('sites.index.empty.actions.clear_workload'),
                'url' => $this->siteFilterUrl($siteFilters, ['workload' => 'all']),
            ]);

            return [
                'heading' => ['key' => 'sites.index.empty.workload_quiet.heading', 'parameters' => []],
                'detail' => __('sites.index.empty.workload_quiet.detail'),
                'actions' => $actions,
            ];
        }

        if ($siteFilters['state'] === 'archived') {
            return [
                'heading' => ['key' => 'sites.index.empty.archived.heading', 'parameters' => []],
                'detail' => __('sites.index.empty.archived.detail'),
                'actions' => [['label' => __('sites.index.empty.actions.back_to_active'), 'url' => route('dashboard.sites.index')]],
            ];
        }

        return [
            'heading' => ['key' => 'sites.index.empty.default.heading', 'parameters' => []],
            'detail' => __('sites.index.empty.default.detail'),
            'actions' => $actions,
        ];
    }

    /**
     * Keep the shared install-health service state-only on this extracted
     * surface. The same service also feeds request-neutral callers, so
     * translating it globally would leak whichever request locale happened to
     * be active into them.
     *
     * @return array{label: string, tone: string, detail: string, needs_attention: bool, action_label: string|null}
     */
    private function localizedSiteInstallHealth(?Visitor $visitor): array
    {
        $health = SiteInstallHealth::fromVisitor($visitor);
        $lastSeenAt = $visitor?->last_seen_at;

        if (! $lastSeenAt) {
            return [
                ...$health,
                'label' => __('sites.index.install.not_installed'),
                'detail' => __('sites.index.install.no_check_in'),
                'action_label' => __('sites.index.install.finish'),
            ];
        }

        return [
            ...$health,
            'label' => $health['needs_attention']
                ? __('sites.index.install.needs_check')
                : __('sites.index.install.live'),
            'detail' => __('sites.index.install.seen', ['elapsed' => $lastSeenAt->diffForHumans()]),
            'action_label' => $health['needs_attention']
                ? __('sites.index.install.review')
                : null,
        ];
    }

    /**
     * Translate the host diagnostic without teaching the request-neutral
     * install-health service about the dashboard locale.
     *
     * @return array{checked_in_host: string|null, status: string, label: string, tone: string, detail: string, needs_attention: bool, detail_feedback: array<string, mixed>}
     */
    private function localizedSiteInstallHostDiagnostic(?Visitor $visitor, ?string $domain): array
    {
        $diagnostic = SiteInstallHealth::hostDiagnostic($visitor, $domain);
        $base = 'site_settings.verification.host.'.$diagnostic['status'];
        $parameters = array_filter([
            'checked' => $diagnostic['checked_in_host'],
            'expected' => $domain ? explode(':', $domain)[0] : null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return [
            ...$diagnostic,
            'label' => __($base.'.label'),
            'detail_feedback' => [
                'key' => $base.'.detail',
                'parameters' => $parameters,
            ],
        ];
    }

    /** @return array{status: string, tone: string, message: string, guidance: string} */
    private function localizedSiteInstallVerification(?Visitor $visitor): array
    {
        $lastSeenAt = $visitor?->last_seen_at;
        $state = ! $lastSeenAt
            ? 'not_seen'
            : ($lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(30)) ? 'recent' : 'stale');
        $parameters = $lastSeenAt ? ['elapsed' => $lastSeenAt->diffForHumans()] : [];

        return [
            'status' => __("site_settings.verification.{$state}.status", $parameters),
            'tone' => $state === 'recent' ? 'ready' : ($state === 'not_seen' ? 'attention' : 'manual'),
            'message' => __("site_settings.verification.{$state}.message"),
            'guidance' => __("site_settings.verification.{$state}.guidance"),
        ];
    }

    /**
     * Resolve structured settings feedback only after the destination request
     * has selected its reader language.
     *
     * @return array{key: string, parameters?: array<string, string>, localized_parameters?: array<string, string>}|string|null
     */
    private function siteShowStatusFeedback(mixed $status): array|string|null
    {
        if (! is_array($status)) {
            return is_string($status) ? $status : null;
        }

        $key = $status['key'] ?? null;

        if (! is_string($key) || ! str_starts_with($key, 'site_settings.flash.')) {
            return null;
        }

        if (isset($status['count'])) {
            return [
                'key' => $key,
                'localized_parameters' => ['count' => ReaderNumber::count((int) $status['count'])],
            ];
        }

        if ($key === 'site_settings.flash.desk_closed_return' && is_string($status['reopens_at'] ?? null)) {
            $reopensAt = Carbon::parse($status['reopens_at']);

            return [
                'key' => $key,
                'localized_parameters' => [
                    'time' => $reopensAt->format('H:i'),
                    'date' => $reopensAt->translatedFormat('j M'),
                ],
            ];
        }

        return $key;
    }

    /**
     * Resolve count phrases when the translated destination renders, while
     * leaving the deleted site name as authored data for the feedback
     * component to mark with an unknown language.
     *
     * @return array{key: string, parameters?: array<string, string>, localized_parameters?: array<string, string>}|string|null
     */
    private function siteIndexStatusFeedback(mixed $status): array|string|null
    {
        if (! is_array($status) || ($status['key'] ?? null) !== 'sites.flash.purged') {
            return is_string($status) ? $status : null;
        }

        $counts = is_array($status['counts'] ?? null) ? $status['counts'] : [];
        $countPhrase = fn (string $name): string => trans_choice(
            'sites.flash.purge_counts.'.$name,
            (int) ($counts[$name] ?? 0),
            ['count' => ReaderNumber::count((int) ($counts[$name] ?? 0))],
        );

        return [
            'key' => 'sites.flash.purged',
            'parameters' => [
                'site' => (string) data_get($status, 'parameters.site', ''),
            ],
            'localized_parameters' => [
                'conversations' => $countPhrase('conversations'),
                'tickets' => $countPhrase('tickets'),
                'attachments' => $countPhrase('attachments'),
            ],
        ];
    }

    /**
     * @param  array{search: string, workload: string, install: string}  $siteFilters
     * @param  array{search?: string, workload?: string, install?: string}  $overrides
     */
    private function siteFilterUrl(array $siteFilters, array $overrides = []): string
    {
        $search = $overrides['search'] ?? $siteFilters['search'];
        $workload = $overrides['workload'] ?? $siteFilters['workload'];
        $install = $overrides['install'] ?? $siteFilters['install'];
        $state = $overrides['state'] ?? $siteFilters['state'];
        $query = [];

        if ($search !== '') {
            $query['site_search'] = $search;
        }

        if ($workload !== 'all') {
            $query['site_workload'] = $workload;
        }

        if ($install !== 'all') {
            $query['site_install'] = $install;
        }

        if ($state !== 'active') {
            $query['site_state'] = $state;
        }

        return route('dashboard.sites.index', $query);
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
        return match ($workload) {
            'active' => $this->siteHasActiveWorkload($site),
            'quiet' => ! $this->siteHasActiveWorkload($site),
            default => true,
        };
    }

    private function siteHasActiveWorkload(Site $site): bool
    {
        return ((int) $site->open_conversations_count) > 0
            || ((int) $site->open_tickets_count) > 0
            || ((int) $site->pending_tickets_count) > 0;
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
    private function maskTerms(Site $site): array
    {
        $terms = $site->settings['mask_terms'] ?? [];

        return is_array($terms) ? array_values(array_filter($terms, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    private function parseMaskSelectors(string $value): array
    {
        return $this->parseLines($value);
    }

    /**
     * @return array<int, string>
     */
    private function parseMaskTerms(string $value): array
    {
        return $this->parseLines($value);
    }

    /**
     * Split operator textarea input into trimmed, bounded, unique lines. Used
     * for both mask selectors and inferred-sensitivity terms. Both are public
     * widget configuration, so they must hold only CSS selectors or plain terms,
     * never secrets.
     *
     * @return array<int, string>
     */
    private function parseLines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $lines = array_map(fn (string $line): string => trim($line), $lines);
        $lines = array_filter($lines, fn (string $line): bool => $line !== '');
        $lines = array_map(fn (string $line): string => mb_substr($line, 0, 255), $lines);

        return array_values(array_unique($lines));
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

        $reverb = WidgetRealtimeConfig::public();
        $lines = [];

        if ($reverb !== null) {
            // No CDN tag: widget.js carries the realtime library itself, so
            // the snippet a customer pastes into their page loads one script,
            // from one origin, and needs nothing allowlisted beyond the
            // Wayfindr host (issue #714).
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
    private function attribute(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
