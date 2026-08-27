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
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\OperatorReadiness;
use App\Support\SiteInstallHealth;
use App\Support\SitePurge;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\SiteRatingPrompt;
use App\Support\Sites\WidgetAppearance;
use App\Support\Sites\WidgetLanguage;
use App\Support\TicketExternalIssueState;
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
        [$sites, $siteFilters] = $this->filteredSites($sites, $request);

        return view('agent.sites.index', [
            'account' => $account,
            'agent' => $agent,
            'siteEmptyState' => $this->siteEmptyState($siteFilters),
            'siteFilters' => $siteFilters,
            'siteOperationsSnapshot' => $siteOperationsSnapshot,
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
        $maskTerms = $this->maskTerms($site);
        $externalIssueHealth = $this->externalIssueHealth($site);

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
            'dataResponsibility' => config('wayfindr.data_responsibility'),
            'externalIssueCapabilities' => ExternalIssueCapability::options(),
            'externalIssueHealth' => $externalIssueHealth,
            'externalIssueProviderConnections' => $externalIssueProviderConnections,
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'presenceEnabled' => SitePresenceReporting::for($site)->enabled,
            'presencePageUrls' => SitePresenceReporting::for($site)->pageUrls,
            'presenceEvery' => SitePresenceReporting::HEARTBEAT_SECONDS,
            'maskSelectors' => $maskSelectors,
            'maskTerms' => $maskTerms,
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
            'mask_terms' => ['nullable', 'string', 'max:4000'],
        ]);

        $settings = $site->settings ?? [];
        $settings['mask_selectors'] = $this->parseMaskSelectors($validated['mask_selectors'] ?? '');
        $settings['mask_terms'] = $this->parseMaskTerms($validated['mask_terms'] ?? '');

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Site privacy settings saved.');
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

        $settings = $site->settings ?? [];
        $settings['rating'] = [
            'enabled' => (bool) ($validated['rating_enabled'] ?? false),
            'intro' => trim((string) ($validated['rating_intro'] ?? '')) ?: null,
        ];

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Rating prompt saved.');
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

        $settings = $site->settings ?? [];
        $settings['intake'] = [
            'fields' => $fields,
            'intro' => trim((string) ($validated['intake_intro'] ?? '')) ?: null,
        ];

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Visitor intake saved.');
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

        $settings = $site->settings ?? [];
        $settings['presence'] = [
            'enabled' => $enabled,
            'page_urls' => (bool) ($validated['presence_page_urls'] ?? false),
        ];

        // Locked, because a heartbeat in flight takes the same lock before it
        // writes. Without that, revoking presence could pass over a row a
        // request already on its way then created, leaving one visitor behind
        // on a site that had just said not to watch anybody.
        $removed = DB::transaction(function () use ($site, $settings, $enabled): int {
            Site::query()->whereKey($site->getKey())->lockForUpdate()->first();

            $site->forceFill(['settings' => $settings])->save();

            // Switching it off is a revocation, so the rows collected under it
            // go. Leaving them to age out over thirty days would mean the
            // visitor directory still listing people who never made contact,
            // on a site whose operator has just said it should not watch them
            // -- and every surface describing that list would be saying
            // something the setting contradicts.
            //
            // Only rows this feature created and nobody has since been in
            // touch through. Somebody who arrived as a heartbeat and later
            // wrote in is a contact, and stays.
            return $enabled ? 0 : $this->forgetPresenceOnlyVisitors($site);
        });

        // Turning page addresses off clears the ones already stored.
        //
        // The form recommends this switch to operators whose paths carry
        // invitation codes or reset tokens, so "from now on" is the wrong
        // scope: a visitor who does not heartbeat again keeps the address that
        // prompted the change for up to thirty days, and it is on an agent's
        // screen the whole time. The operator's decision is about the data,
        // not about future requests.
        if ($enabled && ! $settings['presence']['page_urls']) {
            $this->forgetStoredPageUrls($site);
        }

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', $this->presenceStatusMessage($enabled, $removed));
    }

    private function presenceStatusMessage(bool $enabled, int $removed): string
    {
        if ($enabled) {
            return 'Live visitor presence is on.';
        }

        if ($removed === 0) {
            return 'Live visitor presence is off.';
        }

        return $removed === 1
            ? 'Live visitor presence is off. 1 visitor who never made contact was deleted.'
            : 'Live visitor presence is off. '.$removed.' visitors who never made contact were deleted.';
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
            ->chunkById(200, function ($visitors): void {
                foreach ($visitors as $visitor) {
                    $metadata = is_array($visitor->metadata) ? $visitor->metadata : [];

                    if (! array_key_exists('last_page_url', $metadata)) {
                        continue;
                    }

                    unset($metadata['last_page_url']);

                    $visitor->forceFill(['metadata' => $metadata])->save();
                }
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

        $settings = $site->settings ?? [];
        // Null rather than an empty string, so "not configured" is one value
        // and the widget can tell it from a language it does not carry.
        $settings['locale'] = WidgetLanguage::sanitize($validated['widget_locale'] ?? null);

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Widget language saved.');
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

        $settings = $site->settings ?? [];
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

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Support hours saved.');
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
                ->with('status', 'The desk was left open.');
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
                ? 'Desk closed. The schedule has no opening to return to.'
                : 'Desk closed. Support is back at '.$reopens->format('H:i').' on '.$reopens->format('j M').'.');
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
            ->with('status', 'Desk reopened.');
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
        $settings = $site->settings ?? [];
        $availability = is_array($settings['availability'] ?? null) ? $settings['availability'] : [];

        $availability['closed_until'] = $closedUntil;
        $settings['availability'] = $availability;

        $site->forceFill(['settings' => $settings])->save();
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
                'inbound_address' => 'Another site already receives mail at that address.',
            ]);
        }

        $site->forceFill(['inbound_address' => $address === '' ? null : $address])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', $address === '' ? 'Inbound email turned off.' : 'Inbound email address saved.');
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

        if ($accent !== '' && ($rejection = WidgetAppearance::accentRejection($accent)) !== null) {
            throw ValidationException::withMessages(['widget_accent' => $rejection]);
        }

        $settings = $site->settings ?? [];

        $settings['appearance'] = [
            'accent' => $accent === '' ? null : $accent,
            'position' => $validated['widget_position'],
            'greeting' => trim((string) ($validated['widget_greeting'] ?? '')) ?: null,
            'placeholder' => trim((string) ($validated['widget_placeholder'] ?? '')) ?: null,
        ];

        $site->forceFill(['settings' => $settings])->save();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Widget appearance saved.');
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
            ->with('status', 'Site details saved.');
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
                ->with('status', 'That site is already archived.');
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
            ->with('status', 'Site archived. The widget has stopped serving it, and nothing has been deleted.');
    }

    public function unarchive(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSiteAbility($request, 'view', $site, 404);
        $this->authorizeSiteAbility($request, 'archive', $site);

        if (! $site->isArchived()) {
            return redirect()
                ->route('dashboard.sites.show', $site)
                ->with('status', 'That site is not archived.');
        }

        $site->forceFill(['archived_at' => null])->save();

        $this->recordSiteAudit($site, $request->user(), 'site.unarchived', []);

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'Site restored. The widget is serving it again.');
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
                ->withErrors(['confirm_name' => 'That name did not match, so nothing was deleted. Type the site name exactly to confirm.']);
        }

        $summary = $purge->purge($site, $request->user());

        return redirect()
            ->route('dashboard.sites.index')
            ->with('status', sprintf(
                'Site "%s" was permanently deleted, along with %d %s, %d %s and %d %s.',
                $site->name,
                $summary['conversations'],
                Str::plural('conversation', $summary['conversations']),
                $summary['tickets'],
                Str::plural('ticket', $summary['tickets']),
                $summary['attachments'],
                Str::plural('attachment', $summary['attachments']),
            ));
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
            return 'This site has to be archived before it can be deleted. Archive it first, confirm the widget has stopped serving, then delete it.';
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
            'active' => 'Active sites',
            'archived' => 'Archived',
            'all' => 'All states',
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

        // 'active' is the default view rather than a filter the operator chose,
        // so it earns no chip; the other two are worth announcing.
        if ($state !== 'active') {
            $activeFilters[] = ['label' => 'State', 'value' => $stateOptions[$state]];
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
                    ? "{$resultCount} shown of {$visibleCount} visible"
                    : "{$visibleCount} visible",
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
                'label' => 'Visible sites',
                'value' => $visibleCount.' '.Str::plural('visible site', $visibleCount),
                'detail' => 'Visible to your support role before filters.',
                'href' => route('dashboard.sites.index'),
                'action' => 'Review sites',
            ],
            [
                'label' => 'Active support work',
                'value' => $activeSiteCount.' active '.Str::plural('site', $activeSiteCount),
                'detail' => sprintf(
                    '%s, %s, %s across visible sites.',
                    $openConversationCount.' open '.Str::plural('conversation', $openConversationCount),
                    $openTicketCount.' open '.Str::plural('ticket', $openTicketCount),
                    $pendingTicketCount.' pending '.Str::plural('ticket', $pendingTicketCount),
                ),
                'href' => route('dashboard.sites.index', ['site_workload' => 'active']),
                'action' => 'Review active sites',
            ],
            [
                'label' => 'Install attention',
                'value' => $installAttentionCount.' '.Str::plural('site', $installAttentionCount).' '
                    .($installAttentionCount === 1 ? 'needs' : 'need').' install attention',
                'detail' => 'Widget installs that have not checked in recently or have not reported yet.',
                'href' => route('dashboard.sites.index', ['site_install' => 'needs_attention']),
                'action' => 'Review installs',
            ],
            [
                'label' => 'Support access',
                'value' => $explicitAccessCount.' '.Str::plural('site', $explicitAccessCount).' with explicit access',
                'detail' => $fallbackAccessCount.' '.($fallbackAccessCount === 1 ? 'uses' : 'use').' account-wide fallback.',
                'href' => null,
                'action' => null,
            ],
        ];
    }

    /**
     * @param  array{search: string, workload: string, install: string, workload_options: array<string, string>, install_options: array<string, string>, active: list<array{label: string, value: string}>, has_active_filters: bool, visible_count: int, result_count: int, summary_label: string}  $siteFilters
     * @return array{heading: string, detail: string, actions: list<array{label: string, url: string}>}
     */
    private function siteEmptyState(array $siteFilters): array
    {
        $actions = $siteFilters['has_active_filters']
            ? [['label' => 'Clear all site filters', 'url' => route('dashboard.sites.index')]]
            : [['label' => 'Add site', 'url' => route('dashboard.sites.create')]];

        if ($siteFilters['search'] !== '') {
            array_unshift($actions, [
                'label' => 'Clear search',
                'url' => $this->siteFilterUrl($siteFilters, ['search' => '']),
            ]);

            return [
                'heading' => sprintf('No sites match "%s".', $siteFilters['search']),
                'detail' => 'Search checks site name and domain. Clear the search term or loosen the other site filters to review more visible sites.',
                'actions' => $actions,
            ];
        }

        if ($siteFilters['install'] === 'needs_attention') {
            array_unshift($actions, [
                'label' => 'Clear install health filter',
                'url' => $this->siteFilterUrl($siteFilters, ['install' => 'all']),
            ]);

            return [
                'heading' => 'No sites need install attention right now.',
                'detail' => 'Every visible site has sent a recent widget signal. Clear the install health filter to review all connected sites.',
                'actions' => $actions,
            ];
        }

        if ($siteFilters['install'] === 'live') {
            array_unshift($actions, [
                'label' => 'Clear install health filter',
                'url' => $this->siteFilterUrl($siteFilters, ['install' => 'all']),
            ]);

            return [
                'heading' => 'No live widget installs match these filters.',
                'detail' => 'Try clearing the install health filter to see sites that still need their first widget signal.',
                'actions' => $actions,
            ];
        }

        if ($siteFilters['workload'] === 'active') {
            array_unshift($actions, [
                'label' => 'Clear workload filter',
                'url' => $this->siteFilterUrl($siteFilters, ['workload' => 'all']),
            ]);

            return [
                'heading' => 'No sites have active support work right now.',
                'detail' => 'Clear the workload filter to include quiet sites that may still need install or access review.',
                'actions' => $actions,
            ];
        }

        if ($siteFilters['workload'] === 'quiet') {
            array_unshift($actions, [
                'label' => 'Clear workload filter',
                'url' => $this->siteFilterUrl($siteFilters, ['workload' => 'all']),
            ]);

            return [
                'heading' => 'No quiet sites match these filters.',
                'detail' => 'Clear the workload filter to include sites with active conversations or tickets.',
                'actions' => $actions,
            ];
        }

        if ($siteFilters['state'] === 'archived') {
            return [
                'heading' => 'No sites are archived.',
                'detail' => 'Archiving takes a site out of service without deleting anything, so it can be undone at any time. Nothing here means every site you can see is still serving its widget.',
                'actions' => [['label' => 'Back to active sites', 'url' => route('dashboard.sites.index')]],
            ];
        }

        return [
            'heading' => 'No sites are visible to you yet.',
            'detail' => 'Add the first site to get a public key and widget install snippet.',
            'actions' => $actions,
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
