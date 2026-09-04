<?php

namespace App\Http\Controllers;

use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgentSiteExternalIssueProjectController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function store(Request $request, Site $site): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeSiteAbility($agent, 'view', $site, 404);
        $this->authorizeSiteAbility($agent, 'manageIntegrations', $site);

        $validated = $request->validate(
            [
                'external_issue_provider_connection_id' => [
                    'required',
                    'integer',
                    Rule::exists('external_issue_provider_connections', 'id')
                        ->where('account_id', $site->account_id)
                        ->where('is_enabled', true),
                ],
                'project_key' => ['required', 'string', 'max:255'],
                'project_name' => ['nullable', 'string', 'max:255'],
                'web_url' => ['nullable', 'url', 'max:2048'],
            ],
            [],
            [
                'external_issue_provider_connection_id' => __('site_settings.external.routing.connection'),
                'project_key' => __('site_settings.external.routing.project_key'),
                'project_name' => __('site_settings.external.routing.project_name'),
                'web_url' => __('site_settings.external.routing.project_url'),
            ],
        );

        DB::transaction(function () use ($agent, $site, $validated): void {
            [$agent, $site] = $this->lockedIntegrationManagerAndSite($agent, $site);
            $connection = ExternalIssueProviderConnection::query()
                ->where('account_id', $site->account_id)
                ->where('is_enabled', true)
                ->whereKey($validated['external_issue_provider_connection_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $mapping = $site->externalIssueProjects()->create([
                'account_id' => $site->account_id,
                'external_issue_provider_connection_id' => $connection->id,
                'project_key' => trim($validated['project_key']),
                'project_name' => $this->blankToNull($validated['project_name'] ?? null),
                'web_url' => $this->blankToNull($validated['web_url'] ?? null),
                'settings' => [],
            ]);

            $this->recordActivity($site, $agent, 'site.external_issue_project_mapped', [
                'external_issue_provider_connection_id' => $connection->id,
                'site_external_issue_project_id' => $mapping->id,
                'provider' => $connection->provider,
                'project_key' => $mapping->project_key,
            ]);
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.project_mapped');
    }

    public function destroy(Request $request, Site $site, SiteExternalIssueProject $externalIssueProject): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeSiteAbility($agent, 'view', $site, 404);
        $this->authorizeSiteAbility($agent, 'manageIntegrations', $site);

        DB::transaction(function () use ($agent, $externalIssueProject, $site): void {
            [$agent, $site] = $this->lockedIntegrationManagerAndSite($agent, $site);
            $externalIssueProject = SiteExternalIssueProject::query()
                ->whereKey($externalIssueProject->id)
                ->where('site_id', $site->id)
                ->where('account_id', $site->account_id)
                ->lockForUpdate()
                ->firstOrFail();

            $metadata = [
                'site_external_issue_project_id' => $externalIssueProject->id,
                'external_issue_provider_connection_id' => $externalIssueProject->external_issue_provider_connection_id,
                'project_key' => $externalIssueProject->project_key,
            ];

            $externalIssueProject->delete();

            $this->recordActivity($site, $agent, 'site.external_issue_project_removed', $metadata);
        });

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->with('status', 'site_settings.flash.project_removed');
    }

    private function authorizeSiteAbility(?User $agent, string $ability, Site $site, int $status = 403): void
    {
        abort_unless($agent && Gate::forUser($agent)->allows($ability, $site), $status);
    }

    /** @return array{0: User, 1: Site} */
    private function lockedIntegrationManagerAndSite(User $agent, Site $site): array
    {
        $accountId = (int) $site->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $agent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
        $site = Site::query()
            ->whereKey($site->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('view', $site), 404);
        abort_unless(Gate::forUser($agent)->allows('manageIntegrations', $site), 403);

        return [$agent, $site];
    }

    private function recordActivity(Site $site, User $actor, string $action, array $metadata): void
    {
        $site->auditEvents()->create([
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
