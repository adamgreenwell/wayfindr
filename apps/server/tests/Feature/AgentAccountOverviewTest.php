<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
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
