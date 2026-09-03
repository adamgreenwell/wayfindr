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
        ->assertSeeInOrder(['Connect', 'GitHub', 'GitLab'])
        ->assertSee('Save the provider connection first.')
        ->assertSee('creates its unique inbound webhook URL only after the connection exists')
        ->assertSee('Map a site to a project.');
});

test('empty integration states and the read-only role are localized', function (): void {
    foreach ([
        'de' => [
            'empty_connections' => 'Noch keine Anbieter-Verbindungen.',
            'empty_sites' => 'Noch keine Websites.',
            'admin_hint' => 'Anbieter-Verbindungen werden von einer Admin-Person des Kontos verwaltet.',
            'add' => 'Anbieter-Verbindung hinzufügen',
        ],
        'it' => [
            'empty_connections' => 'Ancora nessuna connessione provider.',
            'empty_sites' => 'Ancora nessun sito.',
            'admin_hint' => 'Le connessioni provider sono gestite da un amministratore dell’account.',
            'add' => 'Aggiungi connessione provider',
        ],
    ] as $locale => $copy) {
        $account = Account::factory()->create();
        $admin = User::factory()->for($account)->create([
            'account_role' => 'admin',
            'locale' => $locale,
        ]);
        $agent = User::factory()->for($account)->create([
            'account_role' => 'agent',
            'locale' => $locale,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard.account.integrations'))
            ->assertOk()
            ->assertSee($copy['empty_connections'])
            ->assertSee($copy['empty_sites'])
            ->assertSee($copy['add'])
            ->assertDontSee('No provider connections yet.')
            ->assertDontSee('No sites yet.');

        $this->actingAs($agent)
            ->get(route('dashboard.account.integrations'))
            ->assertOk()
            ->assertSee($copy['admin_hint'])
            ->assertDontSee($copy['add'])
            ->assertDontSee('managed by an account admin');
    }
});

test('the integrations page follows the reader language across provider states', function (): void {
    $account = Account::factory()->create(['name' => 'Datenpunkt Account']);
    $german = User::factory()->for($account)->create([
        'name' => 'Ada Datenpunkt',
        'account_role' => 'admin',
        'locale' => 'de',
    ]);
    $italian = User::factory()->for($account)->create([
        'name' => 'Arianna Datenpunkt',
        'account_role' => 'admin',
        'locale' => 'it',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Datenpunkt Docs']);

    $github = ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Datenpunkt GitHub',
        'provider' => 'github',
        'base_url' => 'https://datenpunkt.example/github',
        'credentials' => ['token' => 'datenpunkt-token'],
        'capabilities' => [
            'create_issue' => true,
            'add_comment' => true,
            'sync_status' => false,
        ],
    ]);
    ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Datenpunkt GitLab',
        'provider' => 'gitlab',
        'credentials' => ['token' => 'datenpunkt-token', 'webhook_secret' => 'datenpunkt-secret'],
    ]);
    ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Datenpunkt Jira',
        'provider' => 'jira',
        'credentials' => ['token' => 'datenpunkt-token', 'webhook_secret' => 'datenpunkt-secret'],
        'settings' => [
            'inbound_webhook' => [
                'verified' => true,
                'event' => 'datenpunkt_event',
                'status_code' => 202,
            ],
        ],
        'last_checked_at' => now()->subMinutes(3),
    ]);
    ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Datenpunkt custom',
        'provider' => 'other',
        'is_enabled' => false,
    ]);
    ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Datenpunkt future provider',
        'provider' => 'future_provider',
    ]);

    $site->externalIssueProjects()->create([
        'account_id' => $account->id,
        'external_issue_provider_connection_id' => $github->id,
        'project_key' => 'datenpunkt/project',
        'project_name' => 'Datenpunkt project',
        'web_url' => 'https://datenpunkt.example/project',
        'settings' => [],
    ]);

    $germanResponse = $this->actingAs($german)->get(route('dashboard.account.integrations'));

    $germanResponse->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('Anbieter-Verbindungen')
        ->assertSee('5 Verbindungen')
        ->assertSee('Verbindungsfunktionen')
        ->assertSee('aria-label="Verbindungsfunktionen für Datenpunkt GitHub"', false)
        ->assertSee('aria-label="Einstellungen für eingehende Webhooks für Datenpunkt GitHub"', false)
        ->assertSee('Anbieter kann Issues erstellen')
        ->assertSee('Eingehender Abgleich nicht eingerichtet.')
        ->assertSee('Eingehender Abgleich eingerichtet, nicht bestätigt.')
        ->assertSee('Eingehender Abgleich bestätigt.')
        ->assertSee('Letztes bestätigtes Ereignis:')
        ->assertSee('HTTP-Status:')
        ->assertSee('vor 3 Minuten')
        ->assertSee('GitHub-Einstellungen:')
        ->assertSee('GitLab-Einstellungen:')
        ->assertSee('Jira-Einstellungen:')
        ->assertSee('Deaktiviert')
        ->assertSee('Andere')
        ->assertSee('Externer Tracker')
        ->assertSee('Website-Projektzuordnungen')
        ->assertSee('1 von 1 Website zugeordnet')
        ->assertDontSee('Provider connections')
        ->assertDontSee('Inbound sync not configured.')
        ->assertDontSee('Map a project');

    $italianResponse = $this->actingAs($italian)->get(route('dashboard.account.integrations'));

    $italianResponse->assertOk()
        ->assertSee('<html lang="it">', false)
        ->assertSee('Connessioni provider')
        ->assertSee('5 connessioni')
        ->assertSee('Funzioni della connessione')
        ->assertSee('aria-label="Funzioni della connessione Datenpunkt GitHub"', false)
        ->assertSee('aria-label="Impostazioni del webhook in ingresso per Datenpunkt GitHub"', false)
        ->assertSee('Il provider può creare segnalazioni')
        ->assertSee('Sincronizzazione in ingresso non configurata.')
        ->assertSee('Sincronizzazione in ingresso configurata, non verificata.')
        ->assertSee('Sincronizzazione in ingresso verificata.')
        ->assertSee('Ultimo evento verificato:')
        ->assertSee('Stato HTTP:')
        ->assertSee('3 minuti fa')
        ->assertSee('Impostazioni GitHub:')
        ->assertSee('Impostazioni GitLab:')
        ->assertSee('Impostazioni Jira:')
        ->assertSee('Disabilitata')
        ->assertSee('Altro')
        ->assertSee('Sistema esterno')
        ->assertSee('Associazioni fra siti e progetti')
        ->assertSee('1 su 1 sito con associazioni')
        ->assertDontSee('Provider connections')
        ->assertDontSee('Inbound sync not configured.')
        ->assertDontSee('Map a project');

    $document = new DOMDocument;
    $document->loadHTML((string) $germanResponse->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    foreach (['Datenpunkt GitHub', 'https://datenpunkt.example/github', 'Datenpunkt Docs', 'datenpunkt/project', 'datenpunkt_event', '202'] as $value) {
        expect($xpath->query('//*[@lang="" and normalize-space(.)="'.$value.'"]')?->length)
            ->toBeGreaterThan(0, "{$value} is not marked as language-neutral integration data");
    }

    foreach (['GitHub', 'GitLab', 'Bitbucket', 'Jira'] as $provider) {
        expect($xpath->query('//option[@lang="" and normalize-space(.)="'.$provider.'"]')?->length)
            ->toBe(1, "{$provider} is not marked as a provider-owned name");
    }
});

test('integration writes answer in the language of the page they return to', function (): void {
    $fixture = integrationsAccount();
    $admin = $fixture['admin'];
    $admin->forceFill(['locale' => 'de'])->save();
    $admin = $admin->fresh();

    // Connection creation is shared with the still-English site page, so the
    // Referer decides the validation language instead of the write route.
    $invalid = [
        'return_to' => 'integrations',
        'provider' => 'github',
        'name' => 'Datenpunkt connection',
        'base_url' => 'not-a-url',
    ];

    $this->actingAs($admin)
        ->from(route('dashboard.account.integrations'))
        ->post(route('dashboard.external-issue-provider-connections.store'), $invalid)
        ->assertSessionHasErrors('base_url');

    expect((string) session('errors')->first('base_url'))
        ->toContain('Basis-URL')
        ->not->toContain('valid URL');

    $this->actingAs($admin)
        ->from(route('dashboard.sites.show', $fixture['site']))
        ->post(route('dashboard.external-issue-provider-connections.store'), array_diff_key($invalid, ['return_to' => true]))
        ->assertSessionHasErrors('base_url');

    expect((string) session('errors')->first('base_url'))
        ->toContain('valid URL')
        ->not->toContain('Basis-URL');

    $this->actingAs($admin)
        ->from(route('dashboard.account.integrations'))
        ->post(route('dashboard.external-issue-provider-connections.store'), [
            'return_to' => 'integrations',
            'provider' => 'github',
            'name' => 'Datenpunkt connection',
            'capabilities' => ['create_issue'],
        ])
        ->assertRedirect(route('dashboard.account.integrations'))
        ->assertSessionHas('status', 'integrations.flash.connection_saved');

    $this->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Anbieter-Verbindung gespeichert.')
        ->assertDontSee('Provider connection saved.');

    $connection = $fixture['account']->externalIssueProviderConnections()->sole();

    // These two writes belong only to integrations and therefore resolve the
    // locale even without a Referer header.
    $this->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
        'webhook_secret' => str_repeat('x', 4097),
    ])->assertSessionHasErrors('webhook_secret');

    expect((string) session('errors')->first('webhook_secret'))
        ->toContain('Webhook-Geheimnis')
        ->not->toContain('webhook secret');

    $admin->forceFill(['locale' => 'it'])->save();
    $admin = $admin->fresh();

    $this->actingAs($admin)
        ->put(route('dashboard.external-issue-provider-connections.capabilities.update', $connection), [
            'capabilities' => ['create_issue', 'add_comment'],
        ])
        ->assertSessionHas('status', 'integrations.flash.capabilities_updated');

    $this->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Funzioni del provider aggiornate.')
        ->assertDontSee('Provider capabilities updated.');

    $this->actingAs($admin)
        ->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
            'webhook_secret' => 'datenpunkt-secret',
        ])
        ->assertSessionHas('status', 'integrations.flash.secret_saved');

    $this->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->assertSee('Segreto del webhook in ingresso salvato.')
        ->assertDontSee('Inbound webhook secret saved.');
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
        ->assertSessionHas('status', 'integrations.flash.connection_saved');

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
        ->assertSeeInOrder(['HTTP', '200']);

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
        ->assertSessionHas('status', 'integrations.flash.secret_saved');

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
        ->assertRedirect(route('dashboard.account.integrations'))
        ->assertSessionHas('status', 'integrations.flash.secret_cleared');

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
        ->assertSessionHas('status', 'integrations.flash.capabilities_updated');

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
