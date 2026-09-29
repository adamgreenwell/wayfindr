<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can inspect their account role and visible support scope', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Olive Owner',
        'email' => 'olive@example.test',
    ]);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
        'email' => 'ada@example.test',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    User::factory()->for($otherAccount)->create([
        'name' => 'Mallory Elsewhere',
        'email' => 'mallory@example.test',
    ]);

    $visibleSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visibleSite->supportAgents()->attach([$owner->id, $admin->id, $agent->id]);
    $visibleVisitor = Visitor::factory()->for($visibleSite)->create();
    Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create([
        'assigned_agent_id' => $agent->id,
        'status' => 'open',
    ]);
    Ticket::factory()->for($account)->for($visibleSite)->create([
        'assignee_id' => $agent->id,
        'status' => 'open',
    ]);

    // Neither is a support assignment: one is deactivated, the other belongs
    // to another account. The overview counts them no more than the Team
    // page's access matrix lists them.
    $visibleSite->supportAgents()->attach([
        User::factory()->for($account)->create(['deactivated_at' => now()])->id,
        User::query()->where('email', 'mallory@example.test')->value('id'),
    ]);

    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($admin);
    $restrictedVisitor = Visitor::factory()->for($restrictedSite)->create();
    Conversation::factory()->for($restrictedSite)->for($restrictedVisitor)->create([
        'assigned_agent_id' => $admin->id,
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Account')
        ->assertSee('Acme Support')
        ->assertSee('Your role')
        ->assertSee('Agent')
        // The role rules live on the Roles page now, which this agent cannot
        // open -- see `the role rules are the roles page's lede`.
        ->assertDontSee('Role changes are limited to account owners')
        ->assertSee('4 agents')
        ->assertSee('3 support assignments')
        ->assertSee('2 sites')
        // The people are the Team page's (AgentAccountTeamTest).
        ->assertDontSee('olive@example.test')
        ->assertDontSee('1 open conversation')
        ->assertDontSee('Mallory Elsewhere')
        ->assertDontSee('Other Support')
        ->assertDontSee('Restricted Store');
});

test('account overview leaves navigation to the account sidebar and the people to the team page', function (): void {
    // The overview used to open with an in-page "Account map" of jump links
    // and carry a second directory of management pages further down. The
    // account sidebar replaces both (AccountContextSidebarTest); what stays is
    // the account's own state. The roster and everything about the people on
    // it moved to the Team page (AgentAccountTeamTest).
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('aria-label="Account sections"', false)
        ->assertDontSee('Account map')
        ->assertDontSee('id="account-map-heading"', false)
        ->assertDontSee('id="account-management-heading"', false)
        ->assertDontSee('class="management-list"', false)
        ->assertDontSee('id="role-boundary-heading"', false)
        ->assertSee('id="account-context-heading"', false)
        ->assertSee('id="external-issue-readiness-heading"', false)
        ->assertDontSee('id="add-agent-heading"', false)
        ->assertDontSee('id="team-alert-readiness-heading"', false)
        ->assertDontSee('id="agents"', false)
        ->assertDontSee('id="site-access-matrix"', false)
        ->assertDontSee('id="account-activity-heading"', false);
});

test('account overview hides admin only sections from regular agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('id="account-context-heading"', false)
        ->assertDontSee('id="external-issue-readiness-heading"', false);
});

test('the data responsibility reminder is a closed disclosure on the overview', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $html = (string) $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee('id="data-responsibility-heading"', false)
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $disclosure = $xpath->query('//details[contains(@class, "details-disclosure")][summary[normalize-space(.)="Data responsibility"]]')->item(0);

    expect($disclosure)->toBeInstanceOf(DOMElement::class, 'the data responsibility reminder is not a disclosure')
        ->and($disclosure->hasAttribute('open'))->toBeFalse('the data responsibility disclosure renders open')
        ->and($disclosure->textContent)->toContain('Retaining visitor-supplied data may create privacy, security, and legal obligations.')
        ->and($xpath->query('.//a[@href="'.config('wayfindr.data_responsibility.docs_url').'"]', $disclosure)->length)->toBe(1, 'the data responsibility docs link left the disclosure');
});

test('the role rules are the roles page\'s lede, not an overview card', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee('Role boundary')
        ->assertDontSee('Role changes are limited to account owners')
        ->assertDontSee('Manage custom roles');

    $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->assertSeeInOrder([
            '<h1>Custom roles</h1>',
            'Role changes are limited to account owners. Owners cannot change their own role, and every role change is audited.',
            'Owners and admins can suspend access without deleting account history.',
            'id="create-role-heading"',
        ], false);
});

test('account admins can inspect external issue readiness without raw provider details', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $secondSite = Site::factory()->for($account)->create(['name' => 'Status Portal']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);

    $githubConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'provider' => 'github',
            'name' => 'Engineering GitHub',
            'credentials' => ['token' => 'ghp_account_secret'],
        ]);
    $disabledConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'is_enabled' => false,
            'name' => 'Dormant GitLab',
            'provider' => 'gitlab',
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($githubConnection, 'providerConnection')
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
            'project_name' => 'Wayfindr',
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($secondSite)
        ->for($disabledConnection, 'providerConnection')
        ->create([
            'project_key' => 'acme/status',
            'project_name' => 'Status Portal',
        ]);

    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'linked',
        ]);
    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'sync_pending',
        ]);
    TicketExternalLink::factory()
        ->for($account)
        ->for($secondSite)
        ->create([
            'provider' => 'gitlab',
            'project_key' => 'acme/status',
            'sync_status' => 'sync_failed',
        ]);
    TicketExternalLink::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'provider' => 'github',
            'project_key' => 'other/private',
            'sync_status' => 'sync_failed',
        ]);

    AuditEvent::factory()
        ->for($account)
        ->for($secondSite)
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => [
                'provider' => 'gitlab',
                'project_key' => 'acme/status',
                'status' => 503,
                'message' => 'Authorization: Bearer ghp_account_secret raw provider body should stay private',
            ],
            'occurred_at' => now()->subMinutes(6),
        ]);
    AuditEvent::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => [
                'provider' => 'github',
                'project_key' => 'other/private',
                'status' => 401,
            ],
            'occurred_at' => now(),
        ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Needs attention')
        ->assertSee('2 provider connections')
        ->assertSee('2 mapped projects')
        ->assertSee('1 disabled')
        ->assertSee('1 sync failed')
        ->assertSee('1 sync pending')
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_external' => 'failed',
        ]))
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_external' => 'pending',
        ]))
        ->assertSee('Engineering GitHub')
        ->assertSee('GitHub')
        ->assertSee('Acme Docs')
        ->assertSee('adamgreenwell/wayfindr')
        ->assertSee('Dormant GitLab')
        ->assertSee('Status Portal')
        ->assertSee('acme/status')
        ->assertSee('Last external sync failure')
        ->assertSee('Status')
        ->assertSee('503')
        ->assertSee(route('dashboard.sites.show', $site), false)
        ->assertSee(route('dashboard.sites.show', $secondSite), false)
        ->assertDontSee('ghp_account_secret')
        ->assertDontSee('Authorization: Bearer')
        ->assertDontSee('raw provider body should stay private')
        ->assertDontSee('Other Support')
        ->assertDontSee('other/private')
        ->assertDontSee('Status 401');
});

test('regular agents do not see account wide external issue readiness', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach($agent);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Engineering GitHub',
            'provider' => 'github',
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(['project_key' => 'acme/private-ops-repo']);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee('External issue readiness')
        ->assertDontSee('Engineering GitHub')
        ->assertDontSee('acme/private-ops-repo');
});

test('integration only roles see provider readiness without ticket derived metrics', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageIntegrations->value],
    ]);
    $integrationManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Visible integration site']);
    $connection = ExternalIssueProviderConnection::factory()->for($account)->create([
        'name' => 'Visible provider connection',
    ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(['project_key' => 'visible/provider-project']);
    TicketExternalLink::factory()->for($account)->for($site)->create([
        'sync_status' => 'sync_failed',
    ]);
    TicketExternalLink::factory()->for($account)->for($site)->create([
        'sync_status' => 'sync_pending',
    ]);
    AuditEvent::factory()->for($account)->for($site)->create([
        'action' => 'ticket.external_sync_failed',
        'metadata' => [
            'provider' => 'github',
            'project_key' => 'hidden/ticket-project',
            'status' => 503,
        ],
    ]);

    $this->actingAs($integrationManager)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Visible provider connection')
        ->assertSee('visible/provider-project')
        ->assertSee('Provider connections and mapped projects are configured for external handoff.')
        ->assertDontSee('1 sync failed')
        ->assertDontSee('1 sync pending')
        ->assertDontSee('no failed syncs')
        ->assertDontSee('Last external sync failure')
        ->assertDontSee('No recent external sync failures for this account.')
        ->assertDontSee('hidden/ticket-project')
        ->assertDontSee('Status 503')
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_external' => 'failed',
        ]));
});

test('account external issue readiness follows visible site scope', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $restrictedAdmin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Rory Restricted',
    ]);
    $visibleSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($restrictedAdmin);

    $visibleConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'provider' => 'github',
            'name' => 'Visible GitHub',
        ]);
    $restrictedConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'is_enabled' => false,
            'name' => 'Restricted GitLab',
            'provider' => 'gitlab',
        ]);

    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($visibleSite)
        ->for($visibleConnection, 'providerConnection')
        ->create(['project_key' => 'adamgreenwell/wayfindr']);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($restrictedSite)
        ->for($restrictedConnection, 'providerConnection')
        ->create(['project_key' => 'private/restricted']);

    TicketExternalLink::factory()
        ->for($account)
        ->for($visibleSite)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'sync_pending',
        ]);
    TicketExternalLink::factory()
        ->for($account)
        ->for($restrictedSite)
        ->create([
            'provider' => 'gitlab',
            'project_key' => 'private/restricted',
            'sync_status' => 'sync_failed',
        ]);
    AuditEvent::factory()
        ->for($account)
        ->for($restrictedSite)
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => [
                'provider' => 'gitlab',
                'project_key' => 'private/restricted',
                'status' => 503,
                'message' => 'restricted provider body',
            ],
        ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Sync pending')
        ->assertSee('1 provider connection')
        ->assertSee('1 mapped project')
        ->assertSee('0 disabled')
        ->assertSee('0 sync failed')
        ->assertSee('1 sync pending')
        ->assertSee('Visible GitHub')
        ->assertSee('Acme Docs')
        ->assertSee('adamgreenwell/wayfindr')
        ->assertSee(route('dashboard.sites.show', $visibleSite), false)
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Restricted GitLab')
        ->assertDontSee('private/restricted')
        ->assertDontSee('Status 503')
        ->assertDontSee('restricted provider body')
        ->assertDontSee(route('dashboard.sites.show', $restrictedSite), false);
});

test('account external issue readiness counts audit only sync failures', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'provider' => 'github',
            'name' => 'Engineering GitHub',
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(['project_key' => 'adamgreenwell/wayfindr']);
    AuditEvent::factory()
        ->for($account)
        ->for($site)
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => [
                'provider' => 'github',
                'project_key' => 'adamgreenwell/wayfindr',
                'status' => 502,
                'message' => 'raw provider exception should stay hidden',
            ],
        ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Needs attention')
        ->assertSee('1 sync failed')
        ->assertSee('Last external sync failure')
        ->assertSee('Status')
        ->assertSee('502')
        ->assertDontSee('raw provider exception should stay hidden');
});

test('account external issue readiness only links unresolved failed tickets to the failed queue', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create(['subject' => 'Resolved external sync']);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'provider' => 'github',
            'name' => 'Engineering GitHub',
        ]);
    $project = SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(['project_key' => 'adamgreenwell/wayfindr']);

    $failureMetadata = [
        'provider' => 'github',
        'project_key' => 'adamgreenwell/wayfindr',
        'site_external_issue_project_id' => $project->id,
    ];

    AuditEvent::factory()
        ->for($account)
        ->for($site)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => $failureMetadata,
            'occurred_at' => now()->subMinutes(10),
        ]);
    AuditEvent::factory()
        ->for($account)
        ->for($site)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.external_issue_created',
            'metadata' => $failureMetadata + ['external_key' => '#456'],
            'occurred_at' => now()->subMinute(),
        ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_external' => 'failed',
        ]));
});

test('account external issue readiness treats provider only setup as unmapped', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'provider' => 'github',
            'name' => 'Engineering GitHub',
        ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Not configured')
        ->assertSee('Map at least one site project before tickets can leave Wayfindr.')
        ->assertSee('1 provider connection')
        ->assertSee('0 mapped projects');
});

test('account external issue readiness labels project handoff states', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $readySite = Site::factory()->for($account)->create(['name' => 'Docs']);
    $linkOnlySite = Site::factory()->for($account)->create(['name' => 'Knowledge Base']);
    $disabledSite = Site::factory()->for($account)->create(['name' => 'Status Portal']);
    $unsupportedSite = Site::factory()->for($account)->create(['name' => 'Roadmap']);

    $readyConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Ready GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);
    $linkOnlyConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Readonly GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => false,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $disabledConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Dormant GitLab',
            'provider' => 'gitlab',
            'is_enabled' => false,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);
    $unsupportedConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Future Bitbucket',
            'provider' => 'bitbucket',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);

    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($readySite)
        ->for($readyConnection, 'providerConnection')
        ->create(['project_key' => 'acme/docs']);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($linkOnlySite)
        ->for($linkOnlyConnection, 'providerConnection')
        ->create(['project_key' => 'acme/kb']);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($disabledSite)
        ->for($disabledConnection, 'providerConnection')
        ->create(['project_key' => 'acme/status']);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($unsupportedSite)
        ->for($unsupportedConnection, 'providerConnection')
        ->create(['project_key' => 'acme/roadmap']);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('External issue handoff')
        ->assertSeeInOrder([
            'acme/docs',
            'Handoff ready',
            'Can create external issues.',
            'acme/kb',
            'Link only',
            'External issue creation is not enabled.',
            'acme/roadmap',
            'Link only',
            'Wayfindr issue creation is not available for this provider yet.',
            'acme/status',
            'Blocked',
            'Provider connection is disabled.',
        ]);
});

test('account overview follows the reader language through populated management states', function (string $locale, array $copy): void {
    $account = Account::factory()->create(['name' => 'Datenpunkt Account']);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
        'name' => 'Ada Datenpunkt',
        'email' => 'ada@datenpunkt.example',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Datenpunkt',
        'email' => 'bea@datenpunkt.example',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Datenpunkt Docs',
        'domain' => 'docs.datenpunkt.example',
    ]);
    $site->supportAgents()->attach([$owner->id, $agent->id]);

    $connection = ExternalIssueProviderConnection::factory()->for($account)->create([
        'provider' => 'github',
        'name' => 'Datenpunkt GitHub',
    ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'datenpunkt/project',
            'project_name' => 'Datenpunkt Project',
        ]);

    AuditEvent::factory()->for($account)->for($site)->create([
        'action' => 'ticket.external_sync_failed',
        'metadata' => [
            'provider' => 'github',
            'project_key' => 'datenpunkt/project',
            'status' => 503,
            'message' => 'Provider secret must not render.',
        ],
        'occurred_at' => now()->subMinutes(3),
    ]);
    AuditEvent::factory()->for($account)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $agent->getMorphClass(),
        'subject_id' => $agent->id,
        'action' => 'agent.role_changed',
        'metadata' => [
            'old_role' => AccountRole::Agent->value,
            'new_role' => AccountRole::Admin->value,
        ],
        'occurred_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($owner)->get(route('dashboard.account.show'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('aria-label="'.$copy['sections'].'"', false)
        ->assertSee($copy['external'])
        ->assertSee($copy['attention'])
        ->assertSee($copy['handoff'])
        ->assertDontSee('Account sections')
        ->assertDontSee('External issue readiness')
        ->assertDontSee('Provider secret must not render.');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach ([
        'Datenpunkt Account',
        'Datenpunkt Docs',
        'Datenpunkt GitHub',
        'GitHub',
        'datenpunkt/project',
        'Datenpunkt Project',
        '503',
    ] as $value) {
        expect($xpath->query('//*[@lang="" and normalize-space(.)="'.$value.'"]')->length)
            ->toBeGreaterThan(0, "{$value} is not marked as language-neutral account data");
    }

    $heading = $xpath->query('//*[@id="external-issue-readiness-heading"]')->item(0);

    expect($heading)->toBeInstanceOf(DOMElement::class)
        ->and($heading->hasAttribute('lang'))->toBeFalse('translated account copy was reset to an unknown language');
})->with([
    'German' => ['de', [
        'sections' => 'Kontobereiche',
        'external' => 'Bereitschaft für externe Issues',
        'attention' => 'Erfordert Aufmerksamkeit',
        'handoff' => 'Übergabe bereit',
    ]],
    'Italian' => ['it', [
        'sections' => 'Sezioni dell’account',
        'external' => 'Prontezza delle segnalazioni esterne',
        'attention' => 'Richiede attenzione',
        'handoff' => 'Passaggio pronto',
    ]],
]);
