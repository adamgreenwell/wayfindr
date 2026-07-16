<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can inspect their account role and same-account roster', function (): void {
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
        ->assertSee('Role changes are limited to account owners')
        ->assertSee('Olive Owner')
        ->assertSee('olive@example.test')
        ->assertSee('Owner')
        ->assertSee('Ada Admin')
        ->assertSee('Admin')
        ->assertSee('Bea Builder')
        ->assertSee('1 open conversation')
        ->assertSee('1 open ticket')
        ->assertSee('3 support assignments')
        ->assertSee('2 sites')
        ->assertDontSee('Mallory Elsewhere')
        ->assertDontSee('Other Support')
        ->assertDontSee('Restricted Store');
});

test('account overview gives admins a section map for management work', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Account map')
        ->assertSee('href="#account-context-heading"', false)
        ->assertSee('Account boundary')
        ->assertSee('href="#role-boundary-heading"', false)
        ->assertSee('Role boundary')
        ->assertSee('href="#site-access-matrix"', false)
        ->assertSee('Site access')
        ->assertSee('href="#external-issue-readiness-heading"', false)
        ->assertSee('External issue readiness')
        ->assertSee('href="#account-activity-heading"', false)
        ->assertSee('Account activity')
        ->assertSee('href="#add-agent-heading"', false)
        ->assertSee('Add agent')
        ->assertSee('href="#team-alert-readiness-heading"', false)
        ->assertSee('Team alert readiness')
        ->assertSee('href="#agents"', false)
        ->assertSee('Agents');
});

test('account overview section map hides admin only sections from regular agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Account map')
        ->assertSee('href="#account-context-heading"', false)
        ->assertSee('href="#role-boundary-heading"', false)
        ->assertSee('href="#site-access-matrix"', false)
        ->assertSee('href="#account-activity-heading"', false)
        ->assertSee('href="#agents"', false)
        ->assertDontSee('href="#external-issue-readiness-heading"', false)
        ->assertDontSee('href="#add-agent-heading"', false)
        ->assertDontSee('href="#team-alert-readiness-heading"', false);
});

test('agent can inspect visible site access from the account overview', function (): void {
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
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Doug Dormant',
        'email' => 'doug@example.test',
        'deactivated_at' => now(),
    ]);

    $fallbackSite = Site::factory()->for($account)->create([
        'name' => 'Public Docs',
        'domain' => 'docs.example.test',
    ]);
    $explicitSite = Site::factory()->for($account)->create([
        'name' => 'VIP Portal',
        'domain' => 'vip.example.test',
    ]);
    $explicitSite->supportAgents()->attach([$owner->id, $agent->id, $deactivatedAgent->id]);

    $restrictedSite = Site::factory()->for($account)->create([
        'name' => 'Restricted Store',
        'domain' => 'store.example.test',
    ]);
    $restrictedSite->supportAgents()->attach($admin);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Site access matrix')
        ->assertSee('Public Docs')
        ->assertSee('docs.example.test')
        ->assertSee('Account-wide fallback')
        ->assertSee('All active account agents')
        ->assertSee('VIP Portal')
        ->assertSee('vip.example.test')
        ->assertSee('Explicit access')
        ->assertSee('2 assigned active agents')
        ->assertSee('Olive Owner')
        ->assertSee('Bea Builder')
        ->assertSee(route('dashboard.sites.show', $fallbackSite), false)
        ->assertSee(route('dashboard.sites.show', $explicitSite), false)
        ->assertDontSee('Restricted Store');
});

test('agent roster summarizes explicit and fallback site scope', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Olive Owner',
        'email' => 'olive@example.test',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Doug Dormant',
        'email' => 'doug@example.test',
        'deactivated_at' => now(),
    ]);

    $fallbackSite = Site::factory()->for($account)->create(['name' => 'Public Docs']);
    $explicitSite = Site::factory()->for($account)->create(['name' => 'VIP Portal']);
    $explicitSite->supportAgents()->attach([$owner->id, $agent->id, $deactivatedAgent->id]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Support scope')
        ->assertSeeInOrder([
            'Bea Builder',
            'bea@example.test',
            'Explicit: VIP Portal',
            'Fallback: Public Docs',
        ])
        ->assertSeeInOrder([
            'Doug Dormant',
            'doug@example.test',
            'No active support scope',
        ])
        ->assertSee(route('dashboard.sites.show', $fallbackSite), false)
        ->assertSee(route('dashboard.sites.show', $explicitSite), false);
});

test('agent roster keeps multi-site support scope summaries scannable', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);

    $explicitSites = collect(['Alpha Docs', 'Beta Store', 'Gamma Portal'])
        ->map(fn (string $name): Site => tap(
            Site::factory()->for($account)->create(['name' => $name]),
            function (Site $site) use ($agent): void {
                $site->supportAgents()->attach($agent);
            },
        ));

    collect(['Public Docs', 'Knowledge Base', 'Marketing Site'])
        ->each(fn (string $name) => Site::factory()->for($account)->create(['name' => $name]));

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSeeInOrder([
            'Bea Builder',
            '3 explicit sites',
            'Explicit: Alpha Docs, Beta Store + 1 more',
            '3 fallback sites',
            'Fallback: Knowledge Base, Marketing Site + 1 more',
            'Review site access',
        ])
        ->assertSee(route('dashboard.sites.show', $explicitSites->first()), false);
});

test('agent roster summarizes visible assigned workload without leaking restricted site work', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $viewer = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Quinn Queue',
        'email' => 'quinn@example.test',
    ]);

    $visibleSite = Site::factory()->for($account)->create(['name' => 'Public Docs']);
    $visibleSite->supportAgents()->attach([$viewer->id, $teammate->id]);
    $visibleVisitor = Visitor::factory()->for($visibleSite)->create();

    Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create([
        'assigned_agent_id' => $viewer->id,
        'status' => 'open',
    ]);
    Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create([
        'assigned_agent_id' => $viewer->id,
        'status' => 'open',
    ]);
    Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create([
        'assigned_agent_id' => $viewer->id,
        'status' => 'closed',
    ]);
    Ticket::factory()->for($account)->for($visibleSite)->create([
        'assignee_id' => $viewer->id,
        'status' => 'open',
    ]);

    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($teammate);
    $restrictedVisitor = Visitor::factory()->for($restrictedSite)->create();
    Conversation::factory()->for($restrictedSite)->for($restrictedVisitor)->create([
        'assigned_agent_id' => $teammate->id,
        'status' => 'open',
    ]);
    Ticket::factory()->for($account)->for($restrictedSite)->create([
        'assignee_id' => $teammate->id,
        'status' => 'open',
    ]);

    $this->actingAs($viewer)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Workload')
        ->assertSeeInOrder([
            'Bea Builder',
            'bea@example.test',
            '2 open conversations',
            '1 open ticket',
        ])
        ->assertSeeInOrder([
            'Quinn Queue',
            'quinn@example.test',
            'No assigned open work',
        ])
        ->assertDontSee('Restricted Store');
});

test('account overview shows agent alert digest delivery status without raw provider errors', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
        'email' => 'ada@example.test',
    ]);

    User::factory()->for($account)->create([
        'name' => 'Quinn Queued',
        'email' => 'queued@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                'candidate_count' => 2,
                'message' => User::digestQueuedMessage(2),
                'last_attempted_at' => now()->subMinutes(5)->toISOString(),
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Faye Failed',
        'email' => 'failed@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'candidate_count' => 1,
                'message' => 'Digest email could not be queued.',
                'error' => 'SMTP provider secret stack trace should not render',
                'last_attempted_at' => now()->subMinutes(9)->toISOString(),
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Ivy Immediate',
        'email' => 'immediate@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for(Account::factory())->create([
        'name' => 'Outside Digest',
        'email' => 'outside@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'message' => 'Outside failure should not render.',
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Alert delivery')
        ->assertSee('Quinn Queued')
        ->assertSee('Digest')
        ->assertSee('Queued digest email')
        ->assertSee('Queued digest email with 2 alerts.')
        ->assertSee('Faye Failed')
        ->assertSee('Digest delivery failed')
        ->assertSee('Digest email could not be queued.')
        ->assertSee('Ivy Immediate')
        ->assertSee('Immediate')
        ->assertDontSee('SMTP provider secret stack trace should not render')
        ->assertDontSee('Outside Digest')
        ->assertDontSee('Outside failure should not render.');
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
        ->assertSee('Status 503')
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
        ->assertSee('Status 502')
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

test('account admins can inspect team alert readiness without leaking provider details', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Ivy Immediate',
        'email' => 'immediate@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Quinn Digest',
        'email' => 'digest@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_QUEUED,
                'candidate_count' => 3,
                'message' => User::digestQueuedMessage(3),
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Nora New Digest',
        'email' => 'not-run@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Faye Failed',
        'email' => 'failed@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'message' => 'Digest email could not be queued.',
                'error' => 'SMTP provider secret stack trace should not render',
            ],
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Quinn Quiet',
        'email' => 'quiet@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_QUIET,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Ash Dashboard',
        'email' => 'dashboard@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ASSIGNED,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Doug Dormant',
        'email' => 'dormant@example.test',
        'deactivated_at' => now(),
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for(Account::factory())->create([
        'name' => 'Outside Failed',
        'email' => 'outside@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'message' => 'Outside failure should not render.',
            ],
        ],
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Team alert readiness')
        ->assertSee('7 active')
        ->assertSee('2 immediate email')
        ->assertSee('1 digest ready')
        ->assertSee('1 digest needs baseline')
        ->assertSee('1 needs attention')
        ->assertSee('2 dashboard only or quiet')
        ->assertSee('1 deactivated')
        ->assertSee('Faye Failed')
        ->assertDontSee('SMTP provider secret stack trace should not render')
        ->assertDontSee('Outside Failed')
        ->assertDontSee('Outside failure should not render.');
});

test('regular agents do not see team alert readiness rollups', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);

    User::factory()->for($account)->create([
        'name' => 'Faye Failed',
        'email' => 'failed@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
            'digest_delivery' => [
                'status' => User::ALERT_DIGEST_DELIVERY_FAILED,
                'message' => 'Digest email could not be queued.',
                'error' => 'SMTP provider secret stack trace should not render',
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee('Team alert readiness')
        ->assertDontSee('needs attention')
        ->assertDontSee('SMTP provider secret stack trace should not render');
});

test('account overview clarifies agent alert scope and quiet delivery state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    User::factory()->for($account)->create([
        'name' => 'Quinn Quiet',
        'email' => 'quiet@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_QUIET,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Ash Assigned',
        'email' => 'assigned@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ASSIGNED,
            'email' => false,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    User::factory()->for($account)->create([
        'name' => 'Ivy Immediate',
        'email' => 'immediate@example.test',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Quinn Quiet')
        ->assertSee('Quiet mode')
        ->assertSee('New dashboard and email alerts are paused.')
        ->assertSee('Ash Assigned')
        ->assertSee('Assigned-only')
        ->assertSee('Dashboard alerts only for assigned conversations and tickets.')
        ->assertSee('Ivy Immediate')
        ->assertSee('All support work')
        ->assertSee('Email alerts as they happen.');
});

test('agent can review recent account access activity from the account overview', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Olive Owner',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($owner);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $outsideAgent = User::factory()->for($otherAccount)->create(['name' => 'Mallory Elsewhere']);

    AuditEvent::factory()->for($account)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $agent->getMorphClass(),
        'subject_id' => $agent->id,
        'action' => 'agent.created',
        'metadata' => ['role' => AccountRole::Agent->value],
        'occurred_at' => now()->subMinutes(12),
    ]);

    AuditEvent::factory()->for($account)->for($site)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'action' => 'site_access.updated',
        'metadata' => [
            'before_agent_ids' => [],
            'after_agent_ids' => [$owner->id, $agent->id],
            'added_agent_ids' => [$owner->id, $agent->id],
            'removed_agent_ids' => [],
            'token' => 'should-not-render',
        ],
        'occurred_at' => now()->subMinutes(8),
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
            'password' => 'should-not-render',
        ],
        'occurred_at' => now()->subMinutes(4),
    ]);

    AuditEvent::factory()->for($account)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $agent->getMorphClass(),
        'subject_id' => $agent->id,
        'action' => 'agent.password_updated',
        'metadata' => [],
        'occurred_at' => now()->subMinutes(2),
    ]);

    AuditEvent::factory()->for($account)->for($restrictedSite)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $restrictedSite->getMorphClass(),
        'subject_id' => $restrictedSite->id,
        'action' => 'site_access.updated',
        'metadata' => [
            'before_agent_ids' => [],
            'after_agent_ids' => [$owner->id],
            'added_agent_ids' => [$owner->id],
            'removed_agent_ids' => [],
        ],
        'occurred_at' => now()->subMinute(),
    ]);

    AuditEvent::factory()->for($otherAccount)->create([
        'actor_type' => $outsideAgent->getMorphClass(),
        'actor_id' => $outsideAgent->id,
        'subject_type' => $outsideAgent->getMorphClass(),
        'subject_id' => $outsideAgent->id,
        'action' => 'agent.created',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Recent account activity')
        ->assertSee('4 shown')
        ->assertSee('Password changed')
        ->assertSee('Agent role changed')
        ->assertSee('Changed role from Agent to Admin')
        ->assertSee('Site access updated')
        ->assertSee('Updated support access')
        ->assertSee('Agent created')
        ->assertSee('Created agent account')
        ->assertSee('Olive Owner')
        ->assertSee('Bea Builder')
        ->assertSee('Acme Docs')
        ->assertSeeInOrder([
            'Password changed',
            'Agent role changed',
            'Site access updated',
            'Agent created',
        ])
        ->assertDontSee('Other Support')
        ->assertDontSee('Mallory Elsewhere')
        ->assertDontSee('Restricted Store')
        ->assertDontSee('should-not-render');
});

test('account overview explains when there is no account activity yet', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Recent account activity')
        ->assertSee('No account activity yet.');
});
