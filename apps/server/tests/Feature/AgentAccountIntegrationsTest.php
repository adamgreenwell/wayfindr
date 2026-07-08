<?php

// The account-level Integrations home (#554, #511 WS3, motivated by #22).
//
// Provider connections are account-scoped, so their setup lives on an
// account page instead of the bottom of an individual site's detail page.
// Every agent can see what is connected and who manages it; adding
// connections stays admin-only. Site pages cross-link here instead of
// embedding the account-scoped form.

use App\Models\Account;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function integrationsAccount(): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['name' => 'Ada Admin', 'account_role' => 'admin']);
    $agent = User::factory()->for($account)->create(['name' => 'Riley Agent', 'account_role' => 'agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    return compact('account', 'admin', 'agent', 'site');
}

test('admins see connections, setup guidance, and the add form', function (): void {
    $fixture = integrationsAccount();

    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
    ]);

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Integrations')
        ->assertSee('Provider connections')
        ->assertSee('Engineering GitHub')
        ->assertSee('Add provider connection')
        ->assertSee('Site project mappings')
        ->assertSee('Acme Docs')
        ->assertSee('Map a project');
});

test('agents see the connections read-only with an admin hint', function (): void {
    $fixture = integrationsAccount();

    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
    ]);

    $this->actingAs($fixture['agent'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Engineering GitHub')
        ->assertSee('managed by an account admin')
        ->assertDontSee('Add provider connection');
});

test('the empty state guides admins toward the first connection', function (): void {
    $fixture = integrationsAccount();

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('No provider connections yet.')
        ->assertSee('Connect GitHub or GitLab');
});

test('the account page links every agent to the integrations home', function (): void {
    $fixture = integrationsAccount();

    $this->actingAs($fixture['agent'])
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee('Integrations')
        ->assertSee(route('dashboard.account.integrations'));

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee(route('dashboard.account.integrations'))
        ->assertSee('Reply templates');
});

test('the site page cross-links to the integrations home instead of embedding the form', function (): void {
    $fixture = integrationsAccount();

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.sites.show', $fixture['site']))
        ->assertOk()
        ->assertSee(route('dashboard.account.integrations'))
        ->assertDontSee('Add provider connection')
        // The site-scoped project mapping stays on the site page.
        ->assertSee('Map project');
});

test('saving a connection from the integrations home returns to it', function (): void {
    $fixture = integrationsAccount();

    $this->actingAs($fixture['admin'])
        ->post(route('dashboard.external-issue-provider-connections.store'), [
            'return_to' => 'integrations',
            'provider' => 'github',
            'name' => 'Engineering GitHub',
            'credential_token' => 'token-123',
            'capabilities' => ['create_issue'],
        ])
        ->assertRedirect(route('dashboard.account.integrations'))
        ->assertSessionHas('status', 'Provider connection saved.');

    expect($fixture['account']->externalIssueProviderConnections()->count())->toBe(1);
});

test('the mapping overview honors site support-assignment visibility', function (): void {
    $fixture = integrationsAccount();

    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
    ]);

    $restrictedSite = Site::factory()->for($fixture['account'])->create(['name' => 'Restricted Ops']);
    $restrictedSite->supportAgents()->attach($fixture['admin']);
    $restrictedSite->externalIssueProjects()->create([
        'account_id' => $fixture['account']->id,
        'external_issue_provider_connection_id' => $connection->id,
        'project_key' => 'acme/secret-ops',
        'project_name' => 'Secret Ops',
    ]);

    // The unassigned agent sees the account-wide fallback site, but the
    // restricted site (which would 404 for them) leaks neither its name nor
    // its project key through the overview.
    $this->actingAs($fixture['agent'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Acme Docs')
        ->assertDontSee('Restricted Ops')
        ->assertDontSee('acme/secret-ops');

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Restricted Ops')
        ->assertSee('acme/secret-ops');
});
