<?php

// The account-level Integrations home (#554, #511 WS3, motivated by #22).
//
// Provider connections are account-scoped, so their setup lives on an
// account page instead of the bottom of an individual site's detail page.
// Every agent can see what is connected and who manages it; adding
// connections stays admin-only. Site pages cross-link here instead of
// embedding the account-scoped form.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\CustomRole;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\CssSelector\CssSelectorConverter;

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

test('the connection name field resets language only for user-authored data', function (): void {
    $fixture = integrationsAccount();
    $fixture['admin']->forceFill(['locale' => 'de'])->save();

    $inputFor = function (string $html): DOMElement {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $input = (new DOMXPath($document))->query('//input[@id="provider_connection_name"]')->item(0);

        expect($input)->toBeInstanceOf(DOMElement::class);

        return $input;
    };

    $empty = $inputFor((string) $this->actingAs($fixture['admin']->fresh())
        ->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->getContent());

    expect($empty->getAttribute('placeholder'))->toBe('GitHub Technik')
        ->and($empty->hasAttribute('lang'))->toBeFalse('the translated placeholder was reset to an unknown language');

    $this->from(route('dashboard.account.integrations'))
        ->post(route('dashboard.external-issue-provider-connections.store'), [
            'return_to' => 'integrations',
            'provider' => 'github',
            'name' => 'Datenpunkt connection',
            'base_url' => 'not-a-url',
        ])
        ->assertSessionHasErrors('base_url');

    $filled = $inputFor((string) $this->get(route('dashboard.account.integrations'))
        ->assertOk()
        ->getContent());

    expect($filled->getAttribute('value'))->toBe('Datenpunkt connection')
        ->and($filled->hasAttribute('lang'))->toBeTrue('the user-authored connection name carries no language reset')
        ->and($filled->getAttribute('lang'))->toBe('');
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

    $connectionNameId = 'connection_'.$github->id.'_name';
    $capabilitiesHeadingId = 'connection_'.$github->id.'_capabilities_heading';
    $webhookSettingsLabelId = 'connection_'.$github->id.'_webhook_settings_label';
    $connectionName = $xpath->query('//*[@id="'.$connectionNameId.'"]')->item(0);
    $capabilitiesHeading = $xpath->query('//*[@id="'.$capabilitiesHeadingId.'"]')->item(0);
    $webhookSettingsLabel = $xpath->query('//*[@id="'.$webhookSettingsLabelId.'"]')->item(0);

    expect($connectionName)->toBeInstanceOf(DOMElement::class)
        ->and($connectionName->hasAttribute('lang'))->toBeTrue()
        ->and($connectionName->getAttribute('lang'))->toBe('')
        ->and($capabilitiesHeading)->toBeInstanceOf(DOMElement::class)
        ->and($capabilitiesHeading->textContent)->toBe('Verbindungsfunktionen')
        ->and($webhookSettingsLabel)->toBeInstanceOf(DOMElement::class)
        ->and($webhookSettingsLabel->textContent)->toBe('Einstellungen für eingehende Webhooks')
        ->and($xpath->query('//*[@aria-labelledby="'.$capabilitiesHeadingId.' '.$connectionNameId.'"]')->length)->toBe(1)
        ->and($xpath->query('//*[@aria-labelledby="'.$webhookSettingsLabelId.' '.$connectionNameId.'"]')->length)->toBe(1);
});

test('integration writes answer in the language of the page they return to', function (): void {
    $fixture = integrationsAccount();
    $admin = $fixture['admin'];
    $admin->forceFill(['locale' => 'de'])->save();
    $admin = $admin->fresh();

    // Connection creation is shared with the now-extracted site page, so the
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
        ->toContain('Basis-URL')
        ->not->toContain('valid URL');

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

    $this->actingAs($admin)
        ->from(route('dashboard.sites.show', $fixture['site']))
        ->post(route('dashboard.external-issue-provider-connections.store'), [
            'site_id' => $fixture['site']->id,
            'provider' => 'gitlab',
            'name' => 'Datenpunkt site connection',
            'capabilities' => ['create_issue'],
        ])
        ->assertRedirect(route('dashboard.sites.show', $fixture['site']))
        ->assertSessionHas('status', 'site_settings.flash.connection_saved');

    $this->get(route('dashboard.sites.show', $fixture['site']))
        ->assertOk()
        ->assertSee('Anbieter-Verbindung gespeichert.')
        ->assertDontSee('Provider connection saved.');

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

test('provider connection mutations reauthorize the integration manager under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $integrationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageIntegrations->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $integrationRole->id,
    ]);
    $connection = ExternalIssueProviderConnection::factory()->for($account)->create([
        'credentials' => ['token' => 'original-token', 'webhook_secret' => 'original-secret'],
        'capabilities' => ['create_issue' => true, 'add_comment' => false, 'sync_status' => false],
    ]);

    $this->actingAs($manager);

    // Model a permission revocation after the request's first authorization
    // check by keeping the authenticated object on its stale custom role.
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'store' => $this->post(route('dashboard.external-issue-provider-connections.store'), [
            'provider' => 'github',
            'name' => 'Stale provider write',
            'credential_token' => 'late-token',
            'capabilities' => ['create_issue'],
        ]),
        'webhook secret' => $this->put(route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection), [
            'webhook_secret' => 'late-secret',
        ]),
        'capabilities' => $this->put(route('dashboard.external-issue-provider-connections.capabilities.update', $connection), [
            'capabilities' => ['create_issue', 'add_comment'],
        ]),
    };

    $response->assertForbidden();

    expect(ExternalIssueProviderConnection::query()->count())->toBe(1)
        ->and($connection->fresh()->credentials)->toBe([
            'token' => 'original-token',
            'webhook_secret' => 'original-secret',
        ])
        ->and($connection->fresh()->capabilities)->toBe([
            'create_issue' => true,
            'add_comment' => false,
            'sync_status' => false,
        ]);
})->with(['store', 'webhook secret', 'capabilities']);

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

/**
 * The integrations page as a DOM. Named for this file: Pest helpers are global.
 */
function accountIntegrationsPageXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return new DOMXPath($document);
}

/**
 * Every inline stylesheet on the page, joined, with comments removed so a
 * comment above a rule cannot be read as part of its selector.
 */
function accountIntegrationsPageStylesheet(DOMXPath $xpath): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', implode("\n", array_map(
        fn (DOMNode $style): string => $style->textContent,
        iterator_to_array($xpath->query('//style')),
    )));
}

test('a connection row shows its state as a chip, not as a navigation verb', function (): void {
    // The provider-connection row is a <div>: it goes nowhere. Its
    // Enabled/Disabled state sat in `.management-action`, the accent-coloured
    // verb that on every other row names where a click takes you.
    $fixture = integrationsAccount();
    $enabled = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create(['name' => 'Engineering GitHub', 'provider' => 'github']);
    $disabled = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create(['name' => 'Legacy tracker', 'provider' => 'other', 'is_enabled' => false]);

    $xpath = accountIntegrationsPageXpath((string) $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent());

    foreach ([[$enabled, 'ready', 'Enabled'], [$disabled, 'manual', 'Disabled']] as [$connection, $status, $label]) {
        $row = $xpath->query('//*[@id="connection_'.$connection->id.'_name"]/ancestor::div[contains(@class, "management-link")][1]')->item(0);

        expect($row)->not->toBeNull("the {$connection->name} row did not render; this guard is checking nothing");

        expect($xpath->query('.//*[contains(@class, "management-action")]', $row)->length)
            ->toBe(0, "the {$connection->name} row still shows its state as a navigation verb");

        $chip = $xpath->query('.//*[contains(@class, "readiness-status")]', $row)->item(0);

        expect($chip)->not->toBeNull("the {$connection->name} row does not show its state as a chip");

        expect($chip->getAttribute('data-status'))->toBe($status, "the {$connection->name} chip carries the wrong tone")
            ->and(trim($chip->textContent))->toBe($label);
    }

    // The row used to block every span inside it (`.management-link span`),
    // which outranked `.readiness-status` and top-aligned the chip's label.
    preg_match('/\.management-link \.readiness-status\s*\{([^}]*)\}/', accountIntegrationsPageStylesheet($xpath), $rule);

    expect(str_contains($rule[1] ?? '', 'display: inline-flex'))
        ->toBeTrue('nothing restores the chip\'s own box inside a management row, so the row\'s span rule blocks it');
});

test('only a management row that navigates reacts to the pointer', function (): void {
    // `.management-link` lays out rows that navigate (<a>) and rows that do not
    // (<div>). The hover background applied to both, so a read-only row lit up
    // under the cursor like a link and clicking it did nothing.
    $fixture = integrationsAccount();
    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create(['provider' => 'github']);

    $xpath = accountIntegrationsPageXpath((string) $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent());

    // Both kinds are on this page, so the rule has something to spare.
    expect($xpath->query('//div[contains(@class, "management-link")]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//a[contains(@class, "management-link")]')->length)->toBeGreaterThan(0);

    preg_match_all('/([^{}]*\.management-link:hover[^{]*)\{/', accountIntegrationsPageStylesheet($xpath), $rules);

    expect($rules[1])->not->toBe([], 'no hover rule for management rows was found; this guard is checking nothing');

    foreach ($rules[1] as $selectorList) {
        foreach (array_map('trim', explode(',', $selectorList)) as $selector) {
            if (! str_contains($selector, '.management-link:hover')) {
                continue;
            }

            expect(preg_match('/(^|\s)a\.management-link:hover$/', $selector))
                ->toBe(1, "the hover rule `{$selector}` paints management rows that are not links");
        }
    }
});

/**
 * Whether any `display: block` rule aimed at management rows matches this
 * node, evaluated against the page's own stylesheet and DOM rather than by
 * matching the selector's spelling.
 */
function accountIntegrationsPageRowRuleBlocks(DOMXPath $xpath, DOMNode $node): bool
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', accountIntegrationsPageStylesheet($xpath), $rules, PREG_SET_ORDER);
    $converter = new CssSelectorConverter;

    foreach ($rules as [, $selectorList, $declarations]) {
        if (! preg_match('/(^|;)\s*display:\s*block\s*(;|$)/', trim($declarations))) {
            continue;
        }

        foreach (array_map('trim', explode(',', $selectorList)) as $selector) {
            if (! str_contains($selector, '.management-link')) {
                continue;
            }

            foreach ($xpath->query($converter->toXPath($selector)) as $matched) {
                if ($matched->isSameNode($node)) {
                    return true;
                }
            }
        }
    }

    return false;
}

test('a value inside a management row\'s text line stays inline with the words around it', function (): void {
    // `.management-link span { display: block }` meant the row's text lines,
    // and also blocked every span INSIDE them: a connection's lede put its
    // provider, its URL and its capabilities on three lines, and a grant's
    // summary broke around the site it names.
    $fixture = integrationsAccount();
    $owner = User::factory()->for($fixture['account'])->create(['account_role' => AccountRole::Owner]);
    ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
        'base_url' => 'https://github.example.test',
    ]);
    $operator = User::factory()->create(['platform_role' => 'operator']);
    BreakGlassGrant::factory()->scopedToSite($fixture['site'])->create(['requester_id' => $operator->id]);

    $integrations = accountIntegrationsPageXpath((string) $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent());
    $operatorAccess = accountIntegrationsPageXpath((string) $this->actingAs($owner)
        ->get(route('dashboard.account.break-glass.index'))->assertOk()->getContent());

    $cases = [
        'the connection URL' => [$integrations, '//*[contains(@class, "management-link")]/span/span[contains(@class, "lede")]', 'span[@lang="" and normalize-space()="https://github.example.test"]'],
        'the granted site' => [$operatorAccess, '//*[contains(@class, "management-link")]/span/strong', 'span[@lang="" and normalize-space()="Acme Docs"]'],
    ];

    foreach ($cases as $case => [$xpath, $linePath, $valuePath]) {
        $value = $xpath->query($linePath.'//'.$valuePath)->item(0);

        expect($value)->not->toBeNull("{$case} did not render inside a row's text line; this guard is checking nothing");

        $line = $xpath->query('ancestor::*[parent::span[parent::*[contains(@class, "management-link")]]][1]', $value)->item(0);

        expect(accountIntegrationsPageRowRuleBlocks($xpath, $line))
            ->toBeTrue("the text line holding {$case} is no longer a line of its own")
            ->and(accountIntegrationsPageRowRuleBlocks($xpath, $value))
            ->toBeFalse("{$case} is blocked onto a line of its own, breaking the sentence it sits in");
    }
});

test('the add-connection form is reachable by heading', function (): void {
    // Its heading was a <strong>, so a reader moving by headings went from
    // Provider connections straight to Site project mappings and never met
    // the form.
    $fixture = integrationsAccount();

    $xpath = accountIntegrationsPageXpath((string) $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent());

    $heading = $xpath->query('//form[@aria-labelledby="integration-create-heading"]//h3[@id="integration-create-heading"]')->item(0);

    expect($heading)->not->toBeNull('the add-connection form has no heading element naming it');

    expect(trim($heading->textContent))->toBe('Add provider connection');

    // There is no global h3 rule; without the section-header one the heading
    // takes the browser's margins and outsizes the h2 it sits under.
    preg_match('/([^{}]*)\{\s*margin:\s*0;\s*font-size:\s*1rem;\s*\}/', accountIntegrationsPageStylesheet($xpath), $rule);

    expect(in_array('.section-header h3', array_map('trim', explode(',', $rule[1] ?? '')), true))
        ->toBeTrue('no rule sizes an h3 inside .section-header, so the new heading renders at the browser default');
});

test('setup guidance is open only while it is needed', function (): void {
    // Four setup steps, then provider instructions for every connection, all
    // permanently open: three connections put about 350 words of setup prose
    // above the data on every visit. The order matters before the first
    // connection exists; a connection's provider instructions matter until a
    // signed delivery proves the provider side is configured.
    $fixture = integrationsAccount();

    $setupOrder = function (DOMXPath $xpath): ?DOMElement {
        return $xpath->query('//details[summary[normalize-space(.)="'.__('integrations.connections.setup.heading').'"]]')->item(0);
    };
    $providerInstructions = function (DOMXPath $xpath, ExternalIssueProviderConnection $connection): ?DOMElement {
        return $xpath->query('//*[@id="connection_'.$connection->id.'_webhook_settings_label"]/ancestor::details[1]')->item(0);
    };
    $page = fn (): DOMXPath => accountIntegrationsPageXpath((string) $this->actingAs($fixture['admin'])
        ->get(route('dashboard.account.integrations'))->assertOk()->getContent());

    $xpath = $page();

    expect($setupOrder($xpath))->not->toBeNull('the setup order is not a disclosure');

    expect($setupOrder($xpath)->hasAttribute('open'))
        ->toBeTrue('the setup order is collapsed before the first connection exists, when it is the next thing to do');

    $unverified = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Engineering GitHub',
        'provider' => 'github',
    ]);
    // A secret saved on our side proves nothing about the provider's side, so
    // this one still needs the instructions.
    $configured = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Platform GitLab',
        'provider' => 'gitlab',
        'credentials' => ['token' => 'token', 'webhook_secret' => 'secret'],
    ]);
    $verified = ExternalIssueProviderConnection::factory()->for($fixture['account'])->create([
        'name' => 'Ops Jira',
        'provider' => 'jira',
        'credentials' => ['token' => 'token', 'webhook_secret' => 'secret'],
        'settings' => ['inbound_webhook' => ['verified' => true, 'event' => 'jira:issue_updated', 'status_code' => 202]],
        'last_checked_at' => now()->subMinutes(3),
    ]);

    $xpath = $page();

    expect($setupOrder($xpath)->hasAttribute('open'))
        ->toBeFalse('the setup order stays open once connections exist, above the data on every visit');

    expect($providerInstructions($xpath, $unverified))->not->toBeNull('provider instructions are not in a disclosure');

    expect($providerInstructions($xpath, $unverified)->hasAttribute('open'))
        ->toBeTrue('provider instructions are collapsed while the provider side is still unconfigured');

    expect($providerInstructions($xpath, $configured)->hasAttribute('open'))
        ->toBeTrue('provider instructions are collapsed once a secret is saved, before any signed delivery proved the provider side');

    expect($providerInstructions($xpath, $verified)->hasAttribute('open'))
        ->toBeFalse('provider instructions stay open after a signed delivery proved the provider side works');

    // The instructions are inside the disclosure; the URL and the secret form
    // they refer to are not.
    $details = $providerInstructions($xpath, $unverified);

    expect(str_contains($details->textContent, __('integrations.webhook.github_title')))->toBeTrue('the GitHub instructions left the disclosure')
        ->and(str_contains($details->textContent, $unverified->inboundWebhookUrl()))->toBeFalse('the generated URL is hidden inside the disclosure')
        ->and($xpath->query('.//form', $details)->length)->toBe(0, 'the webhook secret form is hidden inside the disclosure');
});
