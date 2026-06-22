<?php

use App\Broadcasting\ConversationChannel;
use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('dashboard scopes support queues to sites assigned to the agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $teammate = User::factory()->for($account)->create(['name' => 'Bea Builder']);

    $assignedSite = Site::factory()->for($account)->create(['name' => 'Assigned Docs']);
    $assignedSite->supportAgents()->attach($agent);
    $assignedVisitor = Visitor::factory()->for($assignedSite)->create(['anonymous_id' => 'anon-assigned']);
    Conversation::factory()->for($assignedSite)->for($assignedVisitor)->create([
        'support_code' => 'WF-ASSIGNED',
        'subject' => 'Assigned site conversation',
        'status' => 'open',
    ]);
    Ticket::factory()->for($account)->for($assignedSite)->create([
        'subject' => 'Assigned site ticket',
        'status' => 'open',
    ]);

    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($teammate);
    $restrictedVisitor = Visitor::factory()->for($restrictedSite)->create(['anonymous_id' => 'anon-restricted']);
    Conversation::factory()->for($restrictedSite)->for($restrictedVisitor)->create([
        'support_code' => 'WF-RESTRICT',
        'subject' => 'Restricted site conversation',
        'status' => 'open',
    ]);
    Ticket::factory()->for($account)->for($restrictedSite)->create([
        'subject' => 'Restricted site ticket',
        'status' => 'open',
    ]);

    $openSite = Site::factory()->for($account)->create(['name' => 'Unassigned Site']);
    $openVisitor = Visitor::factory()->for($openSite)->create(['anonymous_id' => 'anon-open']);
    Conversation::factory()->for($openSite)->for($openVisitor)->create([
        'support_code' => 'WF-OPEN',
        'subject' => 'Open site conversation',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('/dashboard/conversations', false)
        ->assertSee('/dashboard/tickets', false)
        ->assertSee('2 open')
        ->assertSee('1 open')
        ->assertDontSee('Assigned site conversation')
        ->assertDontSee('Assigned site ticket')
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Restricted site conversation')
        ->assertDontSee('Restricted site ticket');

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Assigned Docs')
        ->assertSee('Assigned site conversation')
        ->assertSee('Unassigned Site')
        ->assertSee('Open site conversation')
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Restricted site conversation')
        ->assertDontSee('Restricted site ticket');

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Assigned Docs')
        ->assertSee('Assigned site ticket')
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Restricted site conversation')
        ->assertDontSee('Restricted site ticket');
});

test('site index scopes management links to sites assigned to the agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);
    $teammate = User::factory()->for($account)->create(['name' => 'Bea Builder']);

    $assignedSite = Site::factory()->for($account)->create([
        'name' => 'Assigned Docs',
        'domain' => 'docs.example.test',
    ]);
    $assignedSite->supportAgents()->attach($agent);
    Visitor::factory()->for($assignedSite)->create([
        'anonymous_id' => 'anon-assigned',
        'last_seen_at' => now(),
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/account',
        ],
    ]);

    $openSite = Site::factory()->for($account)->create([
        'name' => 'Open Knowledge Base',
        'domain' => null,
    ]);

    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($teammate);

    $this->actingAs($agent)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Sites')
        ->assertSee('2 visible')
        ->assertSee('Assigned Docs')
        ->assertSee("/dashboard/sites/{$assignedSite->id}", false)
        ->assertSee('docs.example.test')
        ->assertSee('Explicit access')
        ->assertSee('1 assigned')
        ->assertSee('https://docs.example.test/account')
        ->assertSee('Open Knowledge Base')
        ->assertSee('Account-wide fallback')
        ->assertSee('Not set')
        ->assertDontSee('Restricted Store');
});

test('site index summarizes active support coverage for explicitly assigned sites', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Active',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Mara Mentor',
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Gabe Gone',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Assigned Coverage Docs',
        'domain' => 'coverage.example.test',
    ]);

    $site->supportAgents()->attach([$agent->id, $teammate->id, $deactivatedAgent->id]);

    $this->actingAs($agent)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Assigned Coverage Docs')
        ->assertSee('Explicit access')
        ->assertSee('2 assigned')
        ->assertSee('Assigned support')
        ->assertSee('Ada Active, Mara Mentor')
        ->assertDontSee('Gabe Gone');
});

test('site index summarizes visible workload without exposing restricted sites', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Active',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Mara Mentor',
    ]);

    $assignedSite = Site::factory()->for($account)->create(['name' => 'Assigned Docs']);
    $assignedSite->supportAgents()->attach($agent);
    $assignedVisitor = Visitor::factory()->for($assignedSite)->create(['anonymous_id' => 'anon-assigned']);
    Conversation::factory()->for($assignedSite)->for($assignedVisitor)->count(2)->create(['status' => 'open']);
    Conversation::factory()->for($assignedSite)->for($assignedVisitor)->create(['status' => 'closed']);
    Ticket::factory()->for($account)->for($assignedSite)->create(['status' => 'open']);
    Ticket::factory()->for($account)->for($assignedSite)->count(2)->create(['status' => 'pending']);
    Ticket::factory()->for($account)->for($assignedSite)->create(['status' => 'closed']);

    $openSite = Site::factory()->for($account)->create(['name' => 'Quiet Knowledge Base']);

    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($teammate);
    $restrictedVisitor = Visitor::factory()->for($restrictedSite)->create(['anonymous_id' => 'anon-restricted']);
    Conversation::factory()->for($restrictedSite)->for($restrictedVisitor)->create([
        'status' => 'open',
        'subject' => 'Restricted site conversation',
    ]);
    Ticket::factory()->for($account)->for($restrictedSite)->create([
        'status' => 'open',
        'subject' => 'Restricted site ticket',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Workload')
        ->assertSeeInOrder([
            'Assigned Docs',
            '2 open conversations',
            '1 open ticket',
            '2 pending tickets',
        ])
        ->assertSee(route('dashboard.conversations.index', ['conversation_site' => $assignedSite->id]), false)
        ->assertSee(route('dashboard.tickets.index', ['ticket_site' => $assignedSite->id]), false)
        ->assertSee(str_replace('&', '&amp;', route('dashboard.tickets.index', [
            'ticket_status' => 'pending',
            'ticket_site' => $assignedSite->id,
        ])), false)
        ->assertSeeInOrder([
            'Quiet Knowledge Base',
            'No active support work',
        ])
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Restricted site conversation')
        ->assertDontSee('Restricted site ticket');
});

test('sites with only deactivated support assignments fall back to account-wide access', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Active',
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Dee Dormant',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Dormant Assignment Docs',
        'domain' => 'dormant.example.test',
    ]);
    $site->supportAgents()->attach($deactivatedAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-dormant']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DORMANT',
        'subject' => 'Dormant assignment conversation',
        'status' => 'open',
    ]);

    expect($site->fresh()->hasExplicitSupportAgents())->toBeFalse()
        ->and($site->fresh()->supportsAgent($agent))->toBeTrue()
        ->and($site->fresh()->eligibleSupportAgents()->pluck('users.id')->all())->toBe([]);

    $this->actingAs($agent)
        ->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Dormant Assignment Docs')
        ->assertSee('Account-wide fallback')
        ->assertSee('All account agents');

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dormant Assignment Docs')
        ->assertDontSee('Dormant assignment conversation');

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Dormant assignment conversation');
});

test('agent cannot view conversations or tickets for a site they do not support', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $teammate = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($teammate);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NOTMINE',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->create();

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-NOTMINE')
        ->assertNotFound();

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertNotFound();
});

test('agent cannot view or update site settings for a site they do not support', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $teammate = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'name' => 'Restricted Docs',
        'settings' => [
            'mask_selectors' => ['[data-original]'],
        ],
    ]);
    $site->supportAgents()->attach($teammate);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertNotFound();

    $this->actingAs($agent)
        ->put("/dashboard/sites/{$site->id}", [
            'mask_selectors' => '[data-secret]',
        ])
        ->assertNotFound();

    expect($site->fresh()->settings['mask_selectors'])->toBe(['[data-original]']);
});

test('conversation broadcast channels honor site support access', function (): void {
    $account = Account::factory()->create();
    $siteAgent = User::factory()->for($account)->create();
    $otherAccountAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($siteAgent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CHANNEL',
    ]);

    $channel = app(ConversationChannel::class);

    expect($channel->join($siteAgent, $conversation->support_code))->toBeTrue()
        ->and($channel->join($otherAccountAgent, $conversation->support_code))->toBeFalse();
});

test('dashboard hides unread alerts for sites the agent can no longer support', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $teammate = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Restricted Docs']);
    $site->supportAgents()->attach($teammate);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-restricted']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STALEALERT',
        'subject' => 'Restricted conversation',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This should not leak.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Restricted ticket',
            'status' => 'open',
        ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $teammate));

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('0 unread')
        ->assertSee('You’re caught up.')
        ->assertDontSee('Restricted conversation')
        ->assertDontSee('Restricted ticket')
        ->assertDontSee('Restricted Docs')
        ->assertDontSee('This should not leak.');
});

test('ticket assignment is limited to agents assigned to the ticket site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $eligibleAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $ineligibleAgent = User::factory()->for($account)->create(['name' => 'Casey Catalog']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $otherSite = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $site->supportAgents()->attach([$agent->id, $eligibleAgent->id]);
    $otherSite->supportAgents()->attach($ineligibleAgent);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Ada Agent')
        ->assertSee('Bea Builder')
        ->assertDontSee('Casey Catalog');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => $ineligibleAgent->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBe($agent->id);
});

test('invalid cross-account site agent links are ignored for support alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $accountAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $crossAccountAgent = User::factory()->for($otherAccount)->create(['name' => 'Otto Outside']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $site->supportAgents()->attach($crossAccountAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-CROSSPIVOT',
    ]);

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Can someone in the account help?',
    ])->assertCreated();

    expect($accountAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($crossAccountAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('unassigned visitor messages notify only agents assigned to that site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $siteAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAccountAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $site->supportAgents()->attach($siteAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-SITEALERT',
    ]);

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Can someone help?',
    ])->assertCreated();

    expect($siteAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($otherAccountAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('visitor messages fall back to site agents when the assigned agent no longer supports the site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $siteAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $formerSiteAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $site->supportAgents()->attach($siteAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $formerSiteAgent->id,
        'support_code' => 'WF-STALEASSIGN',
    ]);

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Is anyone still here?',
    ])->assertCreated();

    expect($siteAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($formerSiteAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('site creation attaches the creating agent as a support agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->post('/dashboard/sites', [
            'name' => 'Wayfindr Public Site',
            'domain' => 'wayfindr.cc',
        ])
        ->assertRedirect();

    $createdSite = Site::query()
        ->where('name', 'Wayfindr Public Site')
        ->firstOrFail();

    expect($createdSite->supportAgents()->whereKey($agent->id)->exists())->toBeTrue();
});

test('bootstrap attaches the first agent to the first site', function (): void {
    $this->artisan('wayfindr:bootstrap', [
        '--account' => 'Demo Support',
        '--name' => 'Demo Agent',
        '--email' => 'demo@example.com',
        '--password' => 'correct-horse-battery-staple',
        '--site' => 'Demo Site',
        '--site-public-key' => 'site_demo_public_key',
    ])->assertExitCode(0);

    $bootstrapAgent = User::query()->where('email', 'demo@example.com')->firstOrFail();
    $bootstrapSite = Site::query()->where('public_key', 'site_demo_public_key')->firstOrFail();

    expect(Hash::check('correct-horse-battery-staple', $bootstrapAgent->password))->toBeTrue()
        ->and($bootstrapSite->supportAgents()->whereKey($bootstrapAgent->id)->exists())->toBeTrue();
});

test('account admins can manage support agents assigned to a site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
        'email' => 'ada@example.test',
    ]);
    $currentAgent = User::factory()->for($account)->create([
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);
    $newAgent = User::factory()->for($account)->create([
        'name' => 'Casey Catalog',
        'email' => 'casey@example.test',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$admin->id, $currentAgent->id]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Support access')
        ->assertSee('2 assigned')
        ->assertSee('Ada Admin')
        ->assertSee('Bea Builder')
        ->assertSee('Casey Catalog')
        ->assertSee('Save site access');

    $this->actingAs($admin)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [$admin->id, $newAgent->id],
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHas('status', 'Site access saved.');

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->sort()->values()->all())
        ->toBe([$admin->id, $newAgent->id]);

    $auditEvent = AuditEvent::query()
        ->where('action', 'site_access.updated')
        ->firstOrFail();

    expect($auditEvent->account_id)->toBe($account->id)
        ->and($auditEvent->site_id)->toBe($site->id)
        ->and($auditEvent->actor->is($admin))->toBeTrue()
        ->and($auditEvent->subject->is($site))->toBeTrue()
        ->and($auditEvent->metadata)->toMatchArray([
            'added_agent_ids' => [$newAgent->id],
            'removed_agent_ids' => [$currentAgent->id],
        ]);
});

test('plain agents can see site access context but cannot manage it', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);
    $teammate = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $teammate->id]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Support access')
        ->assertSee('Ada Agent')
        ->assertSee('Bea Builder')
        ->assertSee('Account owners and admins manage site support access.')
        ->assertDontSee('Save site access')
        ->assertDontSee('Post-install smoke path');

    $this->actingAs($agent)
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [$agent->id],
        ])
        ->assertForbidden();

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->sort()->values()->all())
        ->toBe([$agent->id, $teammate->id]);
});

test('site detail summarizes support load for the selected site only', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Mara Mentor',
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Gabe Gone',
        'deactivated_at' => now(),
    ]);

    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'domain' => 'docs.example.test',
    ]);
    $site->supportAgents()->attach([$admin->id, $teammate->id, $deactivatedAgent->id]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'status' => 'open',
        'subject' => 'Open docs question',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'status' => 'closed',
        'subject' => 'Closed docs question',
    ]);
    Ticket::factory()->for($account)->for($site)->create([
        'status' => 'open',
        'subject' => 'Open docs ticket',
    ]);
    Ticket::factory()->for($account)->for($site)->create([
        'status' => 'pending',
        'subject' => 'Pending docs ticket',
    ]);
    Ticket::factory()->for($account)->for($site)->create([
        'status' => 'closed',
        'subject' => 'Closed docs ticket',
    ]);

    $otherSite = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-store']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'status' => 'open',
        'subject' => 'Store question',
    ]);
    Ticket::factory()->for($account)->for($otherSite)->create([
        'status' => 'open',
        'subject' => 'Store ticket',
    ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Site map')
        ->assertSee('What this site workspace can help with before you change settings.')
        ->assertSee('href="#site-support-readiness-heading"', false)
        ->assertSee('href="#site-support-load-heading"', false)
        ->assertSee('href="#site-external-issue-readiness-heading"', false)
        ->assertSee('href="#site-context-heading"', false)
        ->assertSee('href="#install-verification-heading"', false)
        ->assertSee('href="#install-snippet-heading"', false)
        ->assertSee('href="#support-access-heading"', false)
        ->assertSee('href="#site-access-activity-heading"', false)
        ->assertSee('href="#external-issue-routing-heading"', false)
        ->assertSee('href="#data-responsibility-heading"', false)
        ->assertSee('href="#privacy-settings-heading"', false)
        ->assertSee('Support load')
        ->assertSeeInOrder([
            'Open conversations',
            '1 conversation',
            'Open tickets',
            '1 ticket',
            'Pending tickets',
            '1 ticket',
            'Support coverage',
            '2 agents',
        ])
        ->assertSee(route('dashboard.conversations.index', ['conversation_site' => $site->id]), false)
        ->assertSee(route('dashboard.tickets.index', ['ticket_site' => $site->id]), false)
        ->assertSee(str_replace('&', '&amp;', route('dashboard.tickets.index', [
            'ticket_status' => 'pending',
            'ticket_site' => $site->id,
        ])), false)
        ->assertDontSee('2 conversations')
        ->assertDontSee('3 tickets')
        ->assertDontSee('Gabe Gone');
});

test('site detail map includes setup attention when the widget needs attention', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'domain' => 'docs.example.test',
    ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Site map')
        ->assertSee('href="#setup-attention-heading"', false)
        ->assertSeeInOrder([
            'Setup attention',
            'Not installed',
            'Wayfindr has not seen this widget check in yet.',
        ]);
});

test('site assigned platform operators see the operator smoke path', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach($operator->id);

    $this->actingAs($operator)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Open operator console')
        ->assertSee('Post-install smoke path')
        ->assertSee('Confirm background workers')
        ->assertSee('php artisan queue:failed');
});

test('admins can review recent site access activity from the site settings page', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $agent = User::factory()->for($account)->create([
        'name' => 'Bea Builder',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'domain' => 'docs.example.test',
    ]);
    $site->supportAgents()->attach([$admin->id, $agent->id]);

    AuditEvent::factory()->for($account)->for($site)->create([
        'actor_type' => $admin->getMorphClass(),
        'actor_id' => $admin->id,
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'action' => 'site_access.updated',
        'metadata' => [
            'before_agent_ids' => [$admin->id],
            'after_agent_ids' => [$admin->id, $agent->id],
            'added_agent_ids' => [$agent->id],
            'removed_agent_ids' => [],
            'api_token' => 'should-not-render',
        ],
        'occurred_at' => now()->subMinutes(3),
    ]);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Recent site access activity')
        ->assertSee('1 shown')
        ->assertSee('Site access updated')
        ->assertSee('Updated support access')
        ->assertSee('Ada Admin')
        ->assertSee('Acme Docs')
        ->assertSee(route('dashboard.account.audit.index', [
            'audit_action' => 'site_access.updated',
            'audit_site' => $site->id,
        ]))
        ->assertDontSee('should-not-render');
});

test('plain agents do not see site access activity audit affordances', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$admin->id, $agent->id]);

    AuditEvent::factory()->for($account)->for($site)->create([
        'actor_type' => $admin->getMorphClass(),
        'actor_id' => $admin->id,
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertDontSee('Recent site access activity')
        ->assertDontSee('href="#site-access-activity-heading"', false)
        ->assertDontSee('View full audit log')
        ->assertDontSee('/dashboard/account/audit', false);
});

test('site access management rejects agents from another account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $crossAccountAgent = User::factory()->for($otherAccount)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($admin);

    $this->actingAs($admin)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [$admin->id, $crossAccountAgent->id],
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHasErrors('support_agent_ids.1');

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->all())->toBe([$admin->id])
        ->and(AuditEvent::query()->where('action', 'site_access.updated')->exists())->toBeFalse();
});

test('site access management rejects deactivated same-account agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $activeAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $deactivatedAdmin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Doug Dormant',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach($admin);

    $this->actingAs($admin)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Ada Admin')
        ->assertSee('Bea Builder')
        ->assertDontSee('Doug Dormant');

    $this->actingAs($admin)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [$deactivatedAdmin->id],
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHasErrors('support_agent_ids.0');

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->all())->toBe([$admin->id])
        ->and(AuditEvent::query()->where('action', 'site_access.updated')->exists())->toBeFalse();

    $this->actingAs($activeAgent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertNotFound();
});

test('site access management requires at least one assigned support agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($admin);

    $this->actingAs($admin)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [],
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHasErrors('support_agent_ids');

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->all())->toBe([$admin->id])
        ->and(AuditEvent::query()->where('action', 'site_access.updated')->exists())->toBeFalse();
});

test('site access management requires at least one assigned owner or admin', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $supportAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $anotherSupportAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach([$admin->id, $supportAgent->id]);

    $this->actingAs($admin)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}/support-agents", [
            'support_agent_ids' => [$supportAgent->id, $anotherSupportAgent->id],
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHasErrors('support_agent_ids');

    expect($site->fresh()->eligibleSupportAgents()->pluck('users.id')->sort()->values()->all())
        ->toBe([$admin->id, $supportAgent->id])
        ->and(AuditEvent::query()->where('action', 'site_access.updated')->exists())->toBeFalse();
});

test('account and role roadmap documents the boundary between site access and account authority', function (): void {
    $path = base_path('../../docs/product/accounts-and-roles.md');

    expect($path)->toBeFile()
        ->and(file_get_contents($path))->toContain('Site access')
        ->and(file_get_contents($path))->toContain('Account roles')
        ->and(file_get_contents($path))->toContain('owner')
        ->and(file_get_contents($path))->toContain('admin')
        ->and(file_get_contents($path))->toContain('agent')
        ->and(file_get_contents($path))->toContain('owners can change another same-account agent')
        ->and(file_get_contents($path))->toContain('Role changes start owner-only');
});
