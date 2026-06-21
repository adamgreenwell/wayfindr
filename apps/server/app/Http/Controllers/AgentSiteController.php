<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use App\Support\ExternalIssueSyncStatus;
use App\Support\OperatorReadiness;
use App\Support\SiteInstallHealth;
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
                'supportAgents as support_agents_count' => fn ($query) => $query
                    ->where('users.account_id', $account->id)
                    ->whereNull('users.deactivated_at'),
            ])
            ->orderBy('name')
            ->get();

        return view('agent.sites.index', [
            'account' => $account,
            'agent' => $agent,
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
     * @param  array<int, string>  $maskSelectors
     * @param  array{label: string, tone: string, status_counts: Collection<int, array{key: string, label: string, count: int}>, recent_failures: Collection<int, array{provider: string, project_key: string, status: string|null, occurred_at: Carbon|null}>}  $externalIssueHealth
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
            ->filter(fn ($project): bool => in_array($project->providerConnection?->provider, ['github', 'gitlab'], true)
                && $project->providerConnection?->is_enabled === true
                && $project->hasCapability('create_issue'))
            ->count();
    }

    /**
     * @return array{
     *     label: string,
     *     tone: string,
     *     status_counts: Collection<int, array{key: string, label: string, count: int}>,
     *     recent_failures: Collection<int, array{provider: string, project_key: string, status: string|null, occurred_at: Carbon|null}>
     * }
     */
    private function externalIssueHealth(Site $site): array
    {
        $statusCounts = $site->ticketExternalLinks()
            ->where('account_id', $site->account_id)
            ->selectRaw('sync_status, count(*) as aggregate')
            ->groupBy('sync_status')
            ->pluck('aggregate', 'sync_status');

        $statusItems = collect(ExternalIssueSyncStatus::options())
            ->map(fn (string $label, string $status): array => [
                'key' => $status,
                'label' => $label,
                'count' => (int) ($statusCounts[$status] ?? 0),
            ])
            ->values();

        $recentFailures = $site->auditEvents()
            ->where('account_id', $site->account_id)
            ->where('action', 'ticket.external_sync_failed')
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

        $failedCount = (int) ($statusCounts['sync_failed'] ?? 0);
        $pendingCount = (int) ($statusCounts['sync_pending'] ?? 0);

        return [
            'label' => match (true) {
                $failedCount > 0 || $recentFailures->isNotEmpty() => 'Needs attention',
                $pendingCount > 0 => 'Sync pending',
                default => 'Healthy',
            },
            'tone' => match (true) {
                $failedCount > 0 || $recentFailures->isNotEmpty() => 'attention',
                $pendingCount > 0 => 'manual',
                default => 'ready',
            },
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
