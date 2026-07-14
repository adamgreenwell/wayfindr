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
        ->assertSee('Connect GitHub or GitLab')
        ->assertSee('Save the provider connection first.')
        ->assertSee('creates its unique inbound webhook URL only after the connection exists')
        ->assertSee('Map a site to a project.');
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

test('the integrations page surfaces inbound webhook setup per connection', function (): void {
    $fixture = integrationsAccount();

    // A connection without a webhook secret prompts to configure inbound sync
    // and shows the receiver URL admins point the provider at.
    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
        'credentials' => ['token' => 'gh_token'],
    ]);

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Inbound sync not configured.')
        ->assertSee('Generated webhook URL')
        ->assertSee('application/json')
        ->assertSee('Issues')
        ->assertSee('Issue comments')
        ->assertSee(route('integrations.github.webhook', $connection), false);

    // A saved secret is configured, but not verified until a signed provider
    // delivery actually reaches Wayfindr.
    $connection->forceFill(['credentials' => ['token' => 'gh_token', 'webhook_secret' => 'whsec']])->save();

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Inbound sync configured, not verified.');

    $connection->recordInboundWebhookDelivery('issues', 200);

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Inbound sync verified.')
        ->assertSee('Latest verified event:')
        ->assertSee('issues')
        ->assertSee('HTTP 200');

    // Non-admins see the status but not the URL, and never the secret.
    $response = $this->actingAs($fixture['agent'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Inbound sync verified.');

    expect($response->getContent())
        ->not->toContain(route('integrations.github.webhook', $connection))
        ->not->toContain('whsec');
});

test('saved connections show provider-specific inbound webhook instructions', function (): void {
    $fixture = integrationsAccount();

    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Product GitLab',
        'provider' => 'gitlab',
    ]);
    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Support Jira',
        'provider' => 'jira',
    ]);

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('GitLab settings:')
        ->assertSee('Issues events')
        ->assertSee('Comments')
        ->assertSee('Jira settings:')
        ->assertSee('issue state changes and comment-created events')
        ->assertSee('If you replace it here, replace it there too.');
});

test('a disabled connection is not shown as inbound-sync verified', function (): void {
    $fixture = integrationsAccount();

    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Retired GitHub',
        'provider' => 'github',
        'is_enabled' => false,
        'credentials' => ['token' => 'gh_token', 'webhook_secret' => 'whsec'],
    ]);

    $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertDontSee('Inbound sync verified.');
});

test('an admin can set and clear the inbound webhook secret on an existing connection', function (): void {
    $fixture = integrationsAccount();

    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'provider' => 'github',
        'credentials' => ['token' => 'gh_token'],
        'settings' => ['inbound_webhook' => ['verified' => true, 'event' => 'issues', 'status_code' => 200]],
        'last_checked_at' => now(),
    ]);

    expect($connection->fresh()->hasWebhookSecret())->toBeFalse();

    $this->actingAs($fixture['admin'])
        ->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
            'webhook_secret' => 'whsec_new',
        ])
        ->assertRedirect(route('dashboard.account.integrations'))
        ->assertSessionHas('status', 'Inbound webhook secret saved.');

    $connection->refresh();
    expect($connection->hasWebhookSecret())->toBeTrue()
        // The API token is preserved, not clobbered.
        ->and(data_get($connection->credentials, 'token'))->toBe('gh_token')
        // Replacing a secret resets stale verification evidence.
        ->and($connection->hasVerifiedInboundWebhook())->toBeFalse()
        ->and($connection->last_checked_at)->toBeNull();

    // Clearing it removes only the secret.
    $this->actingAs($fixture['admin'])
        ->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
            'webhook_secret' => '',
        ])
        ->assertRedirect(route('dashboard.account.integrations'));

    $connection->refresh();
    expect($connection->hasWebhookSecret())->toBeFalse()
        ->and(data_get($connection->credentials, 'token'))->toBe('gh_token');
});

test('an admin can update saved connection capabilities without replacing credentials', function (): void {
    $fixture = integrationsAccount();
    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'provider' => 'github',
        'credentials' => ['token' => 'gh_token', 'webhook_secret' => 'whsec'],
        'capabilities' => ['create_issue' => true, 'add_comment' => false, 'sync_status' => false],
    ]);

    $this->actingAs($fixture['admin'])
        ->put(route('dashboard.external-issue-provider-connections.capabilities.update', $connection), [
            'capabilities' => ['create_issue', 'add_comment', 'sync_status'],
        ])
        ->assertRedirect(route('dashboard.account.integrations'))
        ->assertSessionHas('status', 'Provider capabilities updated.');

    $connection->refresh();

    expect($connection->capabilities)->toBe([
        'create_issue' => true,
        'add_comment' => true,
        'sync_status' => true,
    ])->and(data_get($connection->credentials, 'token'))->toBe('gh_token')
        ->and(data_get($connection->credentials, 'webhook_secret'))->toBe('whsec');
});

test('a non-admin cannot update saved connection capabilities', function (): void {
    $fixture = integrationsAccount();
    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create();

    $this->actingAs($fixture['agent'])
        ->put(route('dashboard.external-issue-provider-connections.capabilities.update', $connection), [
            'capabilities' => ['create_issue', 'add_comment'],
        ])
        ->assertForbidden();
});

test('an admin cannot update another account connection capabilities', function (): void {
    $fixture = integrationsAccount();
    $otherConnection = ExternalIssueProviderConnection::factory()
        ->for(Account::factory())
        ->create();

    $this->actingAs($fixture['admin'])
        ->put(route('dashboard.external-issue-provider-connections.capabilities.update', $otherConnection), [
            'capabilities' => ['create_issue'],
        ])
        ->assertNotFound();
});

test('a non-admin cannot set a webhook secret', function (): void {
    $fixture = integrationsAccount();
    $connection = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create(['provider' => 'github']);

    $this->actingAs($fixture['agent'])
        ->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
            'webhook_secret' => 'whsec',
        ])
        ->assertForbidden();
});

test('an admin cannot set a webhook secret on another account\'s connection', function (): void {
    $fixture = integrationsAccount();
    $otherConnection = ExternalIssueProviderConnection::factory()
        ->for(Account::factory())
        ->create(['provider' => 'github']);

    $this->actingAs($fixture['admin'])
        ->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $otherConnection), [
            'webhook_secret' => 'whsec',
        ])
        ->assertNotFound();
});
