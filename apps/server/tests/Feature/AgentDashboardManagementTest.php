<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OperatorReadinessConfirmation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard focuses on support work without duplicating nav shortcuts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        // The focused landing keeps the support-work content...
        ->assertSee('Support queues')
        ->assertSee('Conversation next steps')
        // ...but drops the cards that only duplicated the topbar search and nav.
        ->assertDontSee('Workspace shortcuts')
        ->assertDontSee('Open a visible conversation, ticket, or visitor profile')
        // Admin-only surfaces stay hidden for plain agents.
        ->assertDontSee('Admin command center')
        ->assertDontSee('Operator readiness')
        ->assertDontSee('/dashboard/readiness', false);
});

test('dashboard summarizes visible ticket next steps', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Tess Teammate',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Hidden Docs']);
    $hiddenSite->supportAgents()->attach($teammate);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-dashboard']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DASHREPLY',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'body' => 'Can someone reply before closing this?',
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create(['subject' => 'Customer cannot log in']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create(['subject' => 'Needs owner assignment']);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DASHWAIT',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'body' => 'I sent the next step.',
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create(['subject' => 'Waiting on customer confirmation']);

    Ticket::factory()
        ->for($account)
        ->for($hiddenSite)
        ->create(['subject' => 'Hidden site ticket']);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ticket next steps')
        ->assertSee('3 open tickets needing movement')
        ->assertSee('Reply to visitor')
        ->assertSee('1 needs reply')
        ->assertSee(route('dashboard.tickets.index', ['ticket_attention' => 'needs_reply']), false)
        ->assertSee('Assign an owner')
        ->assertSee('1 needs owner')
        ->assertSee(route('dashboard.tickets.index', ['ticket_attention' => 'needs_owner']), false)
        ->assertSee('Wait on customer')
        ->assertSee('1 waiting on customer')
        ->assertSee(route('dashboard.tickets.index', ['ticket_attention' => 'waiting_on_customer']), false)
        ->assertDontSee('Customer cannot log in')
        ->assertDontSee('Needs owner assignment')
        ->assertDontSee('Waiting on customer confirmation')
        ->assertDontSee('Hidden site ticket');
});

test('dashboard summarizes visible conversation next steps', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Tess Teammate',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Hidden Docs']);
    $hiddenSite->supportAgents()->attach($teammate);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-dashboard-chat']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->for($agent, 'assignedAgent')->create([
        'last_message_at' => now()->subMinutes(3),
        'status' => 'open',
        'subject' => 'Visitor needs login help',
        'support_code' => 'WF-DASHCHAT1',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'body' => 'Can someone help with login?',
        'created_at' => now()->subMinutes(3),
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->for($agent, 'assignedAgent')->create([
        'last_message_at' => now()->subMinutes(2),
        'status' => 'open',
        'subject' => 'Agent already replied',
        'support_code' => 'WF-DASHCHAT2',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'body' => 'Try this next step.',
        'created_at' => now()->subMinutes(2),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);
    $waitingConversation->markReadFor($agent, now());

    $unassignedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'status' => 'open',
        'subject' => 'Ready to claim',
        'support_code' => 'WF-DASHCHAT3',
    ]);
    $unassignedConversation->markReadFor($agent, now());

    Conversation::factory()->for($hiddenSite)->create([
        'status' => 'open',
        'subject' => 'Hidden conversation',
        'support_code' => 'WF-HIDDENCHAT',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Conversation next steps')
        ->assertSee('3 open conversations needing movement')
        ->assertSee('Needs attention')
        ->assertSee('1 new activity')
        ->assertSee(route('dashboard.conversations.index', ['conversation_filter' => 'new_activity']), false)
        ->assertSee('Reply to visitor')
        ->assertSee('2 need replies')
        ->assertSee(route('dashboard.conversations.index', ['conversation_filter' => 'needs_reply']), false)
        ->assertSee('Assigned to you')
        ->assertSee('2 assigned conversations')
        ->assertSee(route('dashboard.conversations.index', ['conversation_filter' => 'assigned_to_me']), false)
        ->assertSee('Claim unassigned')
        ->assertSee('1 unassigned conversation')
        ->assertSee(route('dashboard.conversations.index', ['conversation_filter' => 'unassigned']), false)
        ->assertDontSee('Visitor needs login help')
        ->assertDontSee('WF-DASHCHAT1')
        ->assertDontSee('Agent already replied')
        ->assertDontSee('WF-DASHCHAT2')
        ->assertDontSee('Ready to claim')
        ->assertDontSee('WF-DASHCHAT3')
        ->assertDontSee('Hidden conversation')
        ->assertDontSee('WF-HIDDENCHAT');
});

test('the account page is the management hub; the home stays queue-focused', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    // The home leads with the work and no longer duplicates management
    // shortcuts or at-a-glance panels (#555).
    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Support queues')
        ->assertDontSee('Admin command center')
        ->assertDontSee('Data responsibility')
        ->assertDontSee('id="team-heading"', false)
        ->assertDontSee('id="realtime-heading"', false);

    // Everything the command center pointed at is reachable from the
    // Account management hub instead.
    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Management')
        ->assertSee('Integrations')
        ->assertSee('Sites')
        ->assertSee('Reply templates')
        ->assertSee('Ticket labels')
        ->assertDontSee('Readiness checks')
        ->assertDontSee('/dashboard/readiness', false)
        ->assertSee('Audit log')
        ->assertSee('/dashboard/account/audit', false)
        ->assertSee('Data responsibility');
});

test('dashboard shows a visitor support readiness checklist', function (): void {
    config([
        'broadcasting.default' => 'log',
        'queue.default' => 'sync',
    ]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $site = Site::factory()->for($account)->create([
        'settings' => ['mask_selectors' => []],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Visitor support readiness')
        ->assertSee('Connect a site')
        ->assertSee('Ready')
        ->assertSee('Confirm widget check-in')
        ->assertSee('Needs attention')
        ->assertSee('Configure privacy masking')
        ->assertSee('Set up realtime delivery')
        ->assertSee('Move queues out of sync mode')
        // Every instance row addresses THIS reader. Leaving the operator-facing
        // instruction in place ("Reverb credentials and the public host setting
        // must be complete") tells an account owner to do something they have
        // no way to do, which is the defect one layer down.
        ->assertSee('Ask your operator to finish the realtime configuration.')
        ->assertSee('Ask your operator to move queues onto a durable worker.')
        ->assertDontSee('Reverb credentials and the public host setting must be complete')
        ->assertDontSee('Queues must run on database or redis with a worker process')
        ->assertSee('Confirm scheduler job')
        ->assertSee('Confirm this')
        ->assertSee('Run a first test conversation')
        ->assertSee('Open tester')
        ->assertSee('Ask an account owner or admin to add mask selectors before cobrowse is used with real visitors.')
        ->assertDontSee('Add selectors such as input[type="password"] and [data-wayfindr-mask].');

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-dashboard-ready',
        'last_seen_at' => now(),
    ]);

    $testVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'tester-site-ready',
    ]);

    Conversation::factory()->for($site)->for($testVisitor)->create([
        'subject' => 'Dashboard smoke test',
        'metadata' => ['started_page_url' => '/dashboard/sites/tester'],
    ]);

    $site->forceFill([
        'settings' => ['mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]']],
    ])->save();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'wayfindr.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'queue.default' => 'database',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Visitor support readiness')
        // Four account checks, and the headline counts only those: this agent
        // is not a platform operator, so Reverb, the queue driver and cron are
        // not theirs to resolve and must not sit under a verdict they cannot
        // move. The scheduler is still unconfirmed -- it just says so in the
        // instance group now, where the person who can act on it is named.
        ->assertSee('4 ready')
        ->assertSee('0 need attention')
        ->assertSee('0 to confirm')
        ->assertSee('Ready for visitors')
        ->assertSee("Instance checks — your operator's to resolve", false)
        ->assertSee('Ask your operator to confirm one thing')
        ->assertSee('Ask your operator to confirm the Laravel scheduler runs every minute.')
        ->assertSee('Widget check-in is fresh.')
        ->assertSee('Privacy masking has selectors configured.')
        ->assertSee('Realtime delivery is configured.')
        ->assertSee('Queue driver is database.');
});

test('an account owner who is not the platform operator is not judged on instance checks', function (): void {
    // The case the split exists for, and the one a self-hosted install cannot
    // produce: the bootstrap makes its first user both roles, so the defect was
    // invisible to every reader it had. In a hosted Wayfindr this is EVERY
    // customer, permanently.
    config([
        'broadcasting.default' => 'log',
        'queue.default' => 'sync',
    ]);

    $account = Account::factory()->create(['name' => 'Client Of An Agency']);
    $owner = User::factory()->for($account)->create([
        // Null, not a second enum case: PlatformRole has only `Operator`, and
        // "not the operator" is the absence of it.
        'account_role' => AccountRole::Owner,
        'platform_role' => null,
    ]);

    $site = Site::factory()->for($account)->create([
        'settings' => ['mask_selectors' => ['input[type="password"]']],
    ]);

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-owner-ready',
        'last_seen_at' => now(),
    ]);

    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'tester-owner-ready']);
    Conversation::factory()->for($site)->for($visitor)->create(['subject' => 'Owner smoke test']);

    // Reverb is unconfigured and queues are in sync mode -- both genuinely
    // broken, both invisible to this owner's control. The account is
    // nonetheless fully set up, and the headline has to be able to say so.
    $this->actingAs($owner)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ready for visitors')
        ->assertSee('4 ready')
        ->assertSee('0 need attention')
        // Visible, attributed, and NOT folded into the verdict above it.
        ->assertSee("Instance checks — your operator's to resolve", false)
        ->assertSee('Ask your operator to look at this')
        ->assertSee('Set up realtime delivery')
        ->assertSee('Move queues out of sync mode')
        // Every instance row points at the operator rather than at /operator,
        // which this reader cannot open.
        ->assertDontSee('/operator', false);
});

test('a platform operator is told the instance checks are theirs', function (): void {
    config([
        'broadcasting.default' => 'log',
        'queue.default' => 'sync',
    ]);

    $account = Account::factory()->create(['name' => 'Self Hosted']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'platform_role' => PlatformRole::Operator,
    ]);

    Site::factory()->for($account)->create(['settings' => ['mask_selectors' => ['input']]]);

    $this->actingAs($operator)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Instance checks — yours as operator')
        ->assertSee('Instance needs attention')
        ->assertSee(route('operator.dashboard'), false)
        // The control for the pair above: an operator keeps the instruction,
        // because they are the person who carries it out.
        ->assertSee('Reverb credentials and the public host setting must be complete')
        ->assertDontSee('Ask your operator to finish the realtime configuration.');
});

test('a recorded scheduler confirmation clears the check, and a stale one does not', function (): void {
    // The other half of the defect: /operator has recorded the attestation
    // since the confirmation model shipped, and this panel ignored it and
    // hard-returned `manual`. The single verdict therefore topped out at
    // "Nearly ready" forever, by construction -- a check nobody can ever clear
    // teaches its reader to stop reading the panel.
    config([
        'broadcasting.default' => 'log',
        'queue.default' => 'sync',
    ]);

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    Site::factory()->for($account)->create(['settings' => ['mask_selectors' => ['input']]]);

    $this->actingAs($agent)->get('/dashboard')->assertOk()
        ->assertSee('Scheduler has not been confirmed.');

    $confirmation = OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_at' => now()->subDay(),
    ]);

    $this->actingAs($agent)->get('/dashboard')->assertOk()
        ->assertSee('Scheduler was confirmed')
        ->assertDontSee('Scheduler has not been confirmed.')
        // The confirming operator works for the platform, not for this account.
        ->assertDontSee('confirmed by');

    // One window, shared with /operator. Eight days is past it.
    $confirmation->forceFill([
        'confirmed_at' => now()->subDays(OperatorReadiness::CONFIRMATION_STALE_AFTER_DAYS['scheduler'] + 1),
    ])->save();

    $this->actingAs($agent)->get('/dashboard')->assertOk()
        ->assertSee('Scheduler has not been confirmed.');
});

test('first-run queue empty states point at what creates the work', function (): void {
    $account = Account::factory()->create(['name' => 'Fresh Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    // No conversations exist at all — not a filter artifact — so the queue
    // points at the widget installs that create them.
    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Conversations begin when a visitor opens the widget on a connected site.')
        ->assertSee('Check widget installs')
        ->assertSee('/dashboard/sites', false);

    // Same for tickets: they come from conversations.
    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Tickets are created from conversations')
        ->assertSee('Open conversations')
        ->assertSee('/dashboard/conversations', false);
});

test('status-only empty ticket views do not show first-run guidance', function (): void {
    $account = Account::factory()->create(['name' => 'Busy Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Busy Docs']);
    $visitor = Visitor::factory()->for($site)->create();
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create(['status' => 'open', 'subject' => 'Open work exists']);

    // Tickets exist; the closed view is empty because nothing is closed —
    // not because the agent should go create tickets.
    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=closed')
        ->assertOk()
        ->assertDontSee('Tickets are created from conversations')
        ->assertSee('Show all tickets');
});
