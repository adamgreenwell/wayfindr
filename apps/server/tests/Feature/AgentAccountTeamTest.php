<?php

// The account's Team page (AgentAccountTeamController): the roster, its site
// access matrix, team alert readiness, and the recent changes to all three.
// They were the lower two-thirds of the account overview; what the overview
// kept is in AgentAccountOverviewTest.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can inspect the same-account roster on the team page', function (): void {
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
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSee('Olive Owner')
        ->assertSee('olive@example.test')
        ->assertSee('Owner')
        ->assertSee('Ada Admin')
        ->assertSee('Admin')
        ->assertSee('Bea Builder')
        ->assertSee('1 open conversation')
        ->assertSee('1 open ticket')
        ->assertDontSee('Mallory Elsewhere')
        ->assertDontSee('Other Support')
        ->assertDontSee('Restricted Store');
});

test('the team page carries every people section for an admin, and none of the overview\'s', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSeeInOrder([
            'id="add-agent-heading"',
            'id="team-alert-readiness-heading"',
            'id="agents"',
            'id="site-access-matrix"',
            'id="account-activity-heading"',
        ], false)
        ->assertDontSee('id="account-context-heading"', false)
        ->assertDontSee('id="external-issue-readiness-heading"', false)
        ->assertDontSee('Data responsibility');
});

test('the team page hides admin only sections from regular agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($agent)
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSee('id="site-access-matrix"', false)
        ->assertSee('id="account-activity-heading"', false)
        ->assertSee('id="agents"', false)
        ->assertDontSee('id="add-agent-heading"', false)
        ->assertDontSee('id="team-alert-readiness-heading"', false);
});

test('neither account page links to an anchor the split left on the other', function (): void {
    // Each roster row's "Review site access" jumps to the matrix. Splitting one
    // page into two turns a same-page anchor into a link to nothing, silently:
    // it still renders and resolves, and clicking it does not move. The site
    // pages carry the same guard in SiteAgentAccessTest.
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $targets = [];

    foreach ([
        'the overview' => route('dashboard.account.show'),
        'the team page' => route('dashboard.account.team.show'),
    ] as $label => $url) {
        $html = (string) $this->actingAs($owner)->get($url)->assertOk()->getContent();

        preg_match_all('/href="#([A-Za-z][\w:.-]*)"/', $html, $anchors);

        foreach (array_unique($anchors[1]) as $target) {
            $targets[] = $target;

            expect(str_contains($html, 'id="'.$target.'"'))->toBeTrue(
                "{$label} links to #{$target}, which nothing on that page carries",
            );
        }
    }

    expect(in_array('site-access-matrix', $targets, true))->toBeTrue('the roster rendered no link to the matrix; this guard is checking nothing');
});

test('agent can inspect visible site access from the team page', function (): void {
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
        ->get(route('dashboard.account.team.show'))
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
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSee('Support scope')
        ->assertSeeInOrder([
            'Bea Builder',
            'bea@example.test',
            'Explicit:',
            'VIP Portal',
            'Fallback:',
            'Public Docs',
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
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSeeInOrder([
            'Bea Builder',
            '3 explicit sites',
            'Explicit:',
            'Alpha Docs',
            'Beta Store',
            '+ 1 more',
            '3 fallback sites',
            'Fallback:',
            'Knowledge Base',
            'Marketing Site',
            '+ 1 more',
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
        ->get(route('dashboard.account.team.show'))
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

test('account roster hides support workloads from settings only custom roles', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManagePrivacySettings->value],
    ]);
    $privacyManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $teammate = User::factory()->for($account)->create(['name' => 'Private workload owner']);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach([$privacyManager->id, $teammate->id]);
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $teammate->id,
        'status' => 'open',
    ]);
    Ticket::factory()->for($account)->for($site)->create([
        'assignee_id' => $teammate->id,
        'status' => 'open',
    ]);

    $this->actingAs($privacyManager)
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSee('Private workload owner')
        ->assertDontSee('Workload')
        ->assertDontSee('1 open conversation')
        ->assertDontSee('1 open ticket');
});

test('the team page shows agent alert digest delivery status without raw provider errors', function (): void {
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
        ->get(route('dashboard.account.team.show'))
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
        ->get(route('dashboard.account.team.show'))
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
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertDontSee('Team alert readiness')
        ->assertDontSee('needs attention')
        ->assertDontSee('SMTP provider secret stack trace should not render');
});

test('the team page clarifies agent alert scope and quiet delivery state', function (): void {
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
        ->get(route('dashboard.account.team.show'))
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

test('agent can review recent account access activity from the team page', function (): void {
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
        ->get(route('dashboard.account.team.show'))
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

test('the team page explains when there is no account activity yet', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.account.team.show'))
        ->assertOk()
        ->assertSee('Recent account activity')
        ->assertSee('No account activity yet.');
});

test('the team page follows the reader language through populated management states', function (string $locale, array $copy): void {
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

    $response = $this->actingAs($owner)->get(route('dashboard.account.team.show'));

    $response->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('aria-label="'.$copy['sections'].'"', false)
        ->assertSee($copy['sites'])
        ->assertSee($copy['activity'])
        ->assertSee($copy['role_change'])
        ->assertSee($copy['create'])
        ->assertSee($copy['alerts'])
        ->assertSee($copy['workload'])
        ->assertDontSee('Account sections')
        ->assertDontSee('Site access matrix')
        ->assertDontSee('Recent account activity')
        ->assertDontSee('Add agent')
        ->assertDontSee('Team alert readiness');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);

    foreach ([
        'Datenpunkt Docs',
        'docs.datenpunkt.example',
        'Ada Datenpunkt',
        'ada@datenpunkt.example',
        'Bea Datenpunkt',
        'bea@datenpunkt.example',
    ] as $value) {
        expect($xpath->query('//*[@lang="" and normalize-space(.)="'.$value.'"]')->length)
            ->toBeGreaterThan(0, "{$value} is not marked as language-neutral account data");
    }

    $heading = $xpath->query('//*[@id="site-access-matrix-heading"]')->item(0);

    expect($heading)->toBeInstanceOf(DOMElement::class)
        ->and($heading->hasAttribute('lang'))->toBeFalse('translated account copy was reset to an unknown language');
})->with([
    'German' => ['de', [
        'sections' => 'Kontobereiche',
        'sites' => 'Matrix für Website-Zugriff',
        'activity' => 'Letzte Kontoaktivität',
        'role_change' => 'Rolle von Agent zu Administrator geändert',
        'create' => 'Agent hinzufügen',
        'alerts' => 'Benachrichtigungsbereitschaft des Teams',
        'workload' => 'Arbeitslast',
    ]],
    'Italian' => ['it', [
        'sections' => 'Sezioni dell’account',
        'sites' => 'Matrice di accesso ai siti',
        'activity' => 'Attività recente dell’account',
        'role_change' => 'Ruolo modificato da Agente a Admin',
        'create' => 'Aggiungi agente',
        'alerts' => 'Prontezza degli avvisi del team',
        'workload' => 'Carico di lavoro',
    ]],
]);
