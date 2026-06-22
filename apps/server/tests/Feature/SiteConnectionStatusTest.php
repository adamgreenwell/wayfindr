<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ExternalIssueSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard shows site widget connection status from latest visitor check in', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $connectedSite = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $quietSite = Site::factory()->for($account)->create([
        'name' => 'Unused Smoke Site',
        'domain' => 'smoke.example.test',
    ]);
    $staleSite = Site::factory()->for($account)->create([
        'name' => 'Quiet Docs',
        'domain' => 'quiet.example.test',
    ]);
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Site']);

    Visitor::factory()->for($connectedSite)->create([
        'anonymous_id' => 'anon-old',
        'last_seen_at' => now()->subHour(),
        'metadata' => [
            'last_page_url' => 'https://wayfindr.cc/old',
        ],
    ]);

    Visitor::factory()->for($connectedSite)->create([
        'anonymous_id' => 'anon-current',
        'last_seen_at' => now()->subMinutes(5),
        'metadata' => [
            'last_page_url' => 'https://wayfindr.cc/pricing',
        ],
    ]);

    Visitor::factory()->for($staleSite)->create([
        'anonymous_id' => 'anon-stale',
        'last_seen_at' => now()->subDays(2),
        'metadata' => [
            'last_page_url' => 'https://quiet.example.test/help',
        ],
    ]);

    Visitor::factory()->for($otherSite)->create([
        'anonymous_id' => 'anon-other',
        'last_seen_at' => now()->subMinute(),
        'metadata' => [
            'last_page_url' => 'https://other.example.test',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Install health')
        ->assertSee('2 sites need setup attention.')
        ->assertSee('Review site installs')
        ->assertSee('/dashboard/sites#site-install-health', false)
        ->assertSee('Wayfindr Public Site')
        ->assertSee('Live')
        ->assertSee('Seen 5 minutes ago')
        ->assertSee('https://wayfindr.cc/pricing')
        ->assertSee('Quiet Docs')
        ->assertSee('Needs check')
        ->assertSee('Seen 2 days ago')
        ->assertSee('Review install')
        ->assertSee("/dashboard/sites/{$staleSite->id}#install-verification", false)
        ->assertSee('Unused Smoke Site')
        ->assertSee('Not installed')
        ->assertSee('No check-in yet')
        ->assertSee('Finish install')
        ->assertSee("/dashboard/sites/{$quietSite->id}#install-verification", false)
        ->assertDontSee('https://wayfindr.cc/old')
        ->assertDontSee('Other Site')
        ->assertDontSee('https://other.example.test');
});

test('site index shows install health cues for visible sites', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $connectedSite = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $quietSite = Site::factory()->for($account)->create([
        'name' => 'Unused Smoke Site',
        'domain' => 'smoke.example.test',
    ]);
    $staleSite = Site::factory()->for($account)->create([
        'name' => 'Quiet Docs',
        'domain' => 'quiet.example.test',
    ]);

    Visitor::factory()->for($connectedSite)->create([
        'anonymous_id' => 'anon-current',
        'last_seen_at' => now()->subMinutes(5),
        'metadata' => [
            'last_page_url' => 'https://wayfindr.cc/pricing',
        ],
    ]);

    Visitor::factory()->for($staleSite)->create([
        'anonymous_id' => 'anon-stale',
        'last_seen_at' => now()->subDays(2),
        'metadata' => [
            'last_page_url' => 'https://quiet.example.test/help',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('id="site-install-health"', false)
        ->assertSee('Install health')
        ->assertSee('Wayfindr Public Site')
        ->assertSee("/dashboard/sites/{$connectedSite->id}/tester", false)
        ->assertSee('Live')
        ->assertSee('Seen 5 minutes ago')
        ->assertSee('Quiet Docs')
        ->assertSee('Needs check')
        ->assertSee('Seen 2 days ago')
        ->assertSee('Review install')
        ->assertSee("/dashboard/sites/{$staleSite->id}#install-verification", false)
        ->assertSee('Unused Smoke Site')
        ->assertSee('Not installed')
        ->assertSee('No check-in yet')
        ->assertSee('Finish install')
        ->assertSee("/dashboard/sites/{$quietSite->id}#install-verification", false)
        ->assertSee('https://wayfindr.cc/pricing')
        ->assertSee('https://quiet.example.test/help');
});

test('agents can open a hosted tester page for sites they support', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
        'public_key' => 'site_public_docs',
    ]);
    $site->supportAgents()->attach($agent);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}/tester")
        ->assertOk()
        ->assertSee('Wayfindr Public Site tester')
        ->assertSee('Test surface')
        ->assertSee('Verification run')
        ->assertSee('Sample page')
        ->assertSee('visitor@example.test')
        ->assertSee('data-wayfindr-mask', false)
        ->assertSee('tester-site-'.$site->id.'-agent-'.$agent->id)
        ->assertSee('src="http://localhost:8000/widget.js"', false)
        ->assertSee('apiBaseUrl: "http:\/\/localhost:8000"', false)
        ->assertSee('sitePublicKey: "site_public_docs"', false)
        ->assertSee("wayfindr_source: 'tester'", false)
        ->assertSee('/dashboard/conversations', false)
        ->assertSee("/dashboard/sites/{$site->id}", false);
});

test('tester visitors do not satisfy install health check ins', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'name' => 'Quiet Docs',
        'domain' => 'quiet.example.test',
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-real',
        'last_seen_at' => now()->subDays(2),
        'metadata' => [
            'last_page_url' => 'https://quiet.example.test/help',
        ],
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => "tester-site-{$site->id}-agent-{$agent->id}",
        'last_seen_at' => now()->subMinute(),
        'metadata' => [
            'last_page_url' => "http://localhost/dashboard/sites/{$site->id}/tester",
        ],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Needs check')
        ->assertSee('Seen 2 days ago')
        ->assertSee('https://quiet.example.test/help')
        ->assertDontSee("http://localhost/dashboard/sites/{$site->id}/tester");
});

test('agents cannot open tester pages for unsupported sites', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assignedAgent = User::factory()->for($account)->create();
    $unsupportedAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($assignedAgent);

    $this->actingAs($unsupportedAgent)
        ->get("/dashboard/sites/{$site->id}/tester")
        ->assertNotFound();
});

test('site settings show the latest widget check in details', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-current',
        'last_seen_at' => now()->subMinutes(3),
        'metadata' => [
            'last_page_url' => 'https://wayfindr.cc/docs',
        ],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Latest check-in')
        ->assertSee('Seen 3 minutes ago')
        ->assertSee('https://wayfindr.cc/docs')
        ->assertSee('Install verification')
        ->assertSee('Open tester')
        ->assertSee("/dashboard/sites/{$site->id}/tester", false)
        ->assertSee('The widget has checked in recently.')
        ->assertSee('Last verified page')
        ->assertSee('Verify again')
        ->assertDontSee('Setup attention')
        ->assertSee("/dashboard/sites/{$site->id}?verify=", false)
        ->assertDontSee("href=\"http://localhost/dashboard/sites/{$site->id}#install-verification\"", false);
});

test('site settings summarize support readiness from existing site signals', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
        'settings' => [
            'mask_selectors' => ['[data-secret]', 'input[name="token"]'],
        ],
    ]);
    $site->supportAgents()->attach([$admin->id, $agent->id]);
    $disabledConnection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'is_enabled' => false,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($disabledConnection, 'providerConnection')
        ->create(['project_key' => 'adamgreenwell/disabled-wayfindr']);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-current',
        'last_seen_at' => now()->subMinutes(4),
        'metadata' => [
            'last_page_url' => 'https://wayfindr.cc/docs',
        ],
    ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Support readiness')
        ->assertSee('Site support loop')
        ->assertSee('Widget install')
        ->assertSee('Live')
        ->assertSee('The widget has checked in recently.')
        ->assertSee('Support coverage')
        ->assertSee('Explicit access')
        ->assertSee('2 assigned')
        ->assertSee('Privacy masking')
        ->assertSee('2 selectors configured')
        ->assertSee('External routing')
        ->assertSee('Not mapped')
        ->assertSee('Map external issue routing if tickets should leave Wayfindr.')
        ->assertSee('#support-access-heading', false)
        ->assertSee('#privacy-settings-heading', false)
        ->assertSee('#external-issue-routing-heading', false)
        ->assertSee("/dashboard/sites/{$site->id}/tester", false);
});

test('site settings show external issue readiness for a mapped site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Engineering GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
            'project_name' => 'Wayfindr',
        ]);
    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => ExternalIssueSyncStatus::PENDING,
        ]);
    $failedTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create(['subject' => 'External sync failed']);
    AuditEvent::factory()
        ->for($account)
        ->for($site)
        ->for($failedTicket, 'subject')
        ->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => [
                'provider' => 'github',
                'project_key' => 'adamgreenwell/wayfindr',
                'status' => 502,
                'message' => 'raw provider exception should stay hidden',
            ],
            'occurred_at' => now()->subMinutes(3),
        ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Needs attention')
        ->assertSee('Review failed syncs before relying on external handoff for this site.')
        ->assertSee('1 mapped project')
        ->assertSee('1 handoff ready')
        ->assertSee('0 disabled')
        ->assertSee('1 sync failed')
        ->assertSee('1 sync pending')
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_site' => $site->id,
            'ticket_external' => 'failed',
        ]))
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_site' => $site->id,
            'ticket_external' => 'pending',
        ]))
        ->assertSee('Last external sync failure')
        ->assertSee('GitHub could not sync adamgreenwell/wayfindr.')
        ->assertSee('Status 502')
        ->assertSee('Provider details withheld')
        ->assertSee('#external-issue-routing-heading', false)
        ->assertDontSee('raw provider exception should stay hidden');
});

test('site settings treat provider only external issue setup as not configured', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Engineering GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Not configured')
        ->assertSee('Map a project before this site can send tickets outside Wayfindr.')
        ->assertSee('0 mapped projects')
        ->assertSee('0 handoff ready')
        ->assertSee('0 disabled');
});

test('site settings flag disabled external issue mappings', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'is_enabled' => false,
            'name' => 'Dormant GitLab',
            'provider' => 'gitlab',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'internal/helpdesk',
            'project_name' => 'Helpdesk',
        ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('Needs attention')
        ->assertSee('Enable or replace disabled provider mappings before ticket handoff depends on them.')
        ->assertSee('1 mapped project')
        ->assertSee('0 handoff ready')
        ->assertSee('1 disabled')
        ->assertSee('Dormant GitLab')
        ->assertSee('internal/helpdesk');
});

test('site external issue readiness counts audit failures beyond the displayed timeline', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Engineering GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ]);
    SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(['project_key' => 'adamgreenwell/wayfindr']);

    foreach (range(1, 5) as $index) {
        AuditEvent::factory()
            ->for($account)
            ->for($site)
            ->create([
                'action' => 'ticket.external_sync_failed',
                'metadata' => [
                    'provider' => 'github',
                    'project_key' => "adamgreenwell/wayfindr-{$index}",
                    'status' => 502,
                ],
                'occurred_at' => now()->subMinutes($index),
            ]);
    }

    $response = $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertSee('5 sync failed')
        ->assertSee('Last external sync failure')
        ->assertSee('Earlier external sync failure')
        ->assertSee('adamgreenwell/wayfindr-1')
        ->assertSee('adamgreenwell/wayfindr-2')
        ->assertSee('adamgreenwell/wayfindr-3')
        ->assertDontSee('adamgreenwell/wayfindr-4')
        ->assertDontSee('adamgreenwell/wayfindr-5');

    expect(substr_count($response->getContent(), 'external sync failure'))->toBe(3);
});

test('site external issue readiness only links unresolved failed tickets to the failed queue', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Wayfindr Public Site',
        'domain' => 'wayfindr.cc',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create(['subject' => 'Resolved external sync']);
    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create([
            'name' => 'Engineering GitHub',
            'provider' => 'github',
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
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
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('External issue readiness')
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_site' => $site->id,
            'ticket_external' => 'failed',
        ]));
});

test('site settings guide agents when the widget has not checked in yet', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'name' => 'Fresh Install',
        'domain' => 'fresh.example.test',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Setup attention')
        ->assertSee('Install verification')
        ->assertSee('Not seen yet')
        ->assertSee('Wayfindr has not seen this widget check in yet.')
        ->assertSee('Copy the snippet, load the site, then refresh this page.')
        ->assertSee('Finish the widget install by copying the snippet below, loading fresh.example.test, then using Verify again.')
        ->assertSee('Jump to snippet')
        ->assertSee('#install-snippet', false)
        ->assertSee('Verify again');
});

test('site settings call out stale widget check ins', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'name' => 'Quiet Site',
        'domain' => 'quiet.example.test',
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-stale',
        'last_seen_at' => now()->subDays(2),
        'metadata' => [
            'last_page_url' => 'https://quiet.example.test/help',
        ],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Setup attention')
        ->assertSee('Install verification')
        ->assertSee('Last seen 2 days ago')
        ->assertSee('Wayfindr has seen this widget before, but not recently.')
        ->assertSee('Visit the site and refresh this page if it should still be active.')
        ->assertSee('Check whether the widget still loads on quiet.example.test. If it does, use Verify again. If it does not, revisit the snippet.')
        ->assertSee('Open site')
        ->assertSee('https://quiet.example.test', false)
        ->assertSee('Jump to snippet')
        ->assertSee('Last verified page')
        ->assertSee('https://quiet.example.test/help');
});
