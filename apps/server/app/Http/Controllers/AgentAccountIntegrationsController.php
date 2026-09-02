<?php

namespace App\Http\Controllers;

use App\Support\ExternalIssueCapability;
use App\Support\ExternalIssueProvider;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentAccountIntegrationsController extends Controller
{
    /**
     * The account-level Integrations home: provider connections are
     * account-scoped, so their setup lives here instead of at the bottom of an
     * individual site's page. Every agent can see what is connected (and who
     * to ask); managing connections stays admin-only.
     */
    public function show(Request $request): View
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        $providerConnections = $account->externalIssueProviderConnections()
            ->orderBy('name')
            ->get();

        // Same visibility rule as the account overview and queues: explicit
        // support assignments restrict which sites an agent sees, and a site
        // that would 404 for this agent must not leak its name or project
        // keys through the mapping overview.
        $sites = $account->sites()
            ->visibleToAgent($agent)
            ->with('externalIssueProjects.providerConnection')
            ->orderBy('name')
            ->get();

        return view('agent.account.integrations', [
            'agent' => $agent,
            'account' => $account,
            'providerConnections' => $providerConnections,
            'sites' => $sites,
            'externalIssueProviders' => collect(ExternalIssueProvider::values())
                ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerParts($provider)])
                ->all(),
            'externalIssueCapabilities' => collect(ExternalIssueCapability::values())
                ->mapWithKeys(fn (string $capability): array => [$capability => [
                    'label' => __('integrations.capabilities.labels.'.$capability),
                    'permission' => __('integrations.capabilities.permissions.'.$capability),
                ]])
                ->all(),
            'canManageIntegrations' => $agent->isAdmin(),
        ]);
    }

    /** @return array{label: string, language: string|null} */
    private function providerParts(string $provider): array
    {
        if (in_array($provider, ['github', 'gitlab', 'bitbucket', 'jira'], true)) {
            return [
                'label' => ExternalIssueProvider::label($provider),
                // Product names, not words supplied by this catalogue.
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
}
