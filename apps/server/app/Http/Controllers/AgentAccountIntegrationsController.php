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

        $sites = $account->sites()
            ->with('externalIssueProjects.providerConnection')
            ->orderBy('name')
            ->get();

        return view('agent.account.integrations', [
            'agent' => $agent,
            'account' => $account,
            'providerConnections' => $providerConnections,
            'sites' => $sites,
            'externalIssueProviders' => ExternalIssueProvider::options(),
            'externalIssueCapabilities' => ExternalIssueCapability::options(),
            'canManageIntegrations' => $agent->isAdmin(),
        ]);
    }
}
