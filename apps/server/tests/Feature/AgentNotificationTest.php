<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('visitor messages notify the assigned agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-ALERT1',
        'subject' => 'Checkout trouble',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'The checkout button is still stuck.',
    ])->assertCreated();

    expect($assignedAgent->unreadNotifications)->toHaveCount(1)
        ->and($otherAgent->unreadNotifications)->toHaveCount(0);

    $notification = $assignedAgent->unreadNotifications()->firstOrFail();

    expect($notification->type)->toBe(ConversationNeedsReply::class)
        ->and($notification->data)->toMatchArray([
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
            'support_code' => 'WF-ALERT1',
            'subject' => 'Checkout trouble',
            'site_name' => 'Acme Docs',
            'visitor_anonymous_id' => 'anon-docs',
            'message_preview' => 'The checkout button is still stuck.',
        ]);
});

test('conversation alerts with digest cadence keep dashboard delivery but skip immediate mail', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assignedAgent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'alert_preferences' => [
            'mode' => 'assigned',
            'email' => true,
            'cadence' => 'digest',
        ],
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-MAIL1',
        'subject' => 'Checkout trouble',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'The checkout button is still stuck.',
    ])->assertCreated();

    $notification = $assignedAgent->fresh()->unreadNotifications()->firstOrFail();

    expect((new ConversationNeedsReply($conversation->messages()->latest('id')->firstOrFail()))->via($assignedAgent))
        ->toBe(['database'])
        ->and($notification->data)->toMatchArray([
            'support_code' => 'WF-MAIL1',
            'message_preview' => 'The checkout button is still stuck.',
        ]);
});

test('conversation alert notifications queue mail while keeping dashboard delivery synchronous', function (): void {
    config(['queue.default' => 'redis']);

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MAILQ1',
        'subject' => 'Checkout trouble',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is still stuck.',
    ]);

    $notification = new ConversationNeedsReply($message);

    expect($notification)
        ->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->viaConnections())->toBe([
            'database' => 'sync',
            'mail' => 'redis',
        ]);
});

test('conversation alert email includes searchable support context', function (): void {
    URL::useOrigin('https://wayfindr.example.test');
    URL::forceHttps();

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MAILREF',
        'subject' => 'Checkout trouble',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is still stuck.',
    ]);

    $mail = (new ConversationNeedsReply($message))->toMail($agent);

    expect($mail->subject)->toBe('Wayfindr reply needed: Checkout trouble')
        ->and($mail->introLines)->toBe([
            'Acme Docs has a visitor message waiting for you.',
            'Support code: WF-MAILREF',
            'The checkout button is still stuck.',
        ])
        ->and($mail->actionText)->toBe('Open conversation')
        ->and($mail->actionUrl)->toBe('https://wayfindr.example.test/dashboard/conversations/WF-MAILREF');
});

test('conversation alerts stay dashboard only when email alerts are disabled', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => false,
        ],
    ]);
    $site = Site::factory()->for($account)->create([
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-MAIL2',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Dashboard only, please.',
    ])->assertCreated();

    expect((new ConversationNeedsReply($conversation->messages()->latest('id')->firstOrFail()))->via($agent))
        ->toBe(['database'])
        ->and($agent->fresh()->unreadNotifications)->toHaveCount(1);
});

test('assigned conversation alerts batch repeated visitor messages', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-BATCH1',
        'subject' => 'Checkout trouble',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'The checkout button is stuck.',
    ])->assertCreated();

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Actually, now the whole cart is empty.',
    ])->assertCreated();

    expect($agent->fresh()->unreadNotifications)->toHaveCount(1);

    $notification = $agent->fresh()->unreadNotifications()->firstOrFail();
    $latestMessage = $conversation->messages()->latest('id')->firstOrFail();

    expect($notification->data)->toMatchArray([
        'conversation_id' => $conversation->id,
        'latest_message_id' => $latestMessage->id,
        'message_count' => 2,
        'message_preview' => 'Actually, now the whole cart is empty.',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('1 unread')
        ->assertSee('2 new messages')
        ->assertSee('Actually, now the whole cart is empty.');
});

test('visitor messages notify all account agents when a conversation is unassigned', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $firstAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $secondAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $otherAccountAgent = User::factory()->for(Account::factory())->create(['name' => 'Casey Elsewhere']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-UNASSIGNED',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Can someone help?',
    ])->assertCreated();

    expect($firstAgent->unreadNotifications)->toHaveCount(1)
        ->and($secondAgent->unreadNotifications)->toHaveCount(1)
        ->and($otherAccountAgent->unreadNotifications)->toHaveCount(0);
});

test('unassigned conversation alerts batch for each account agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $firstAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $secondAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-BATCH2',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    foreach (['Can someone help?', 'I am still blocked.'] as $body) {
        $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'body' => $body,
        ])->assertCreated();
    }

    expect($firstAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($secondAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($firstAgent->fresh()->unreadNotifications()->firstOrFail()->data)->toMatchArray([
            'conversation_id' => $conversation->id,
            'message_count' => 2,
            'message_preview' => 'I am still blocked.',
        ])
        ->and($secondAgent->fresh()->unreadNotifications()->firstOrFail()->data)->toMatchArray([
            'conversation_id' => $conversation->id,
            'message_count' => 2,
            'message_preview' => 'I am still blocked.',
        ]);
});

test('unassigned conversation alerts honor agent alert preferences', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $allAlertsAgent = User::factory()->for($account)->create([
        'name' => 'Ada All',
    ]);
    $assignedOnlyAgent = User::factory()->for($account)->create([
        'name' => 'Bea Assigned',
        'alert_preferences' => ['mode' => 'assigned'],
    ]);
    $quietAgent = User::factory()->for($account)->create([
        'name' => 'Casey Quiet',
        'alert_preferences' => ['mode' => 'quiet'],
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Dee Deactivated',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-PREF1',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Can someone help?',
    ])->assertCreated();

    expect($allAlertsAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($assignedOnlyAgent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($quietAgent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($deactivatedAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('unassigned conversation alerts fall back when only deactivated agents are assigned to the site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $fallbackAgent = User::factory()->for($account)->create(['name' => 'Ada Active']);
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Dee Dormant',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $site->supportAgents()->attach($deactivatedAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-FALLBACK1',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Can an active agent still see this?',
    ])->assertCreated();

    expect($fallbackAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($deactivatedAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('assigned only agents still receive alerts for conversations assigned to them', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assignedAgent = User::factory()->for($account)->create([
        'name' => 'Ada Assigned',
        'alert_preferences' => ['mode' => 'assigned'],
    ]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-PREF2',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'This one is yours.',
    ])->assertCreated();

    expect($assignedAgent->fresh()->unreadNotifications)->toHaveCount(1);
});

test('quiet assigned agents do not fan out conversation alerts to other agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $quietAssignedAgent = User::factory()->for($account)->create([
        'name' => 'Ada Quiet',
        'alert_preferences' => ['mode' => 'quiet'],
    ]);
    $fallbackAgent = User::factory()->for($account)->create([
        'name' => 'Bea Backup',
    ]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $quietAssignedAgent->id,
        'support_code' => 'WF-PREF3',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Please do not broadcast this.',
    ])->assertCreated();

    expect($quietAssignedAgent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($fallbackAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('agent replies do not create needs reply notifications', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-QUIET1',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-QUIET1/messages', [
            'body' => 'I can help with that.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-QUIET1');

    expect($agent->notifications)->toHaveCount(0);
});

test('assigning a ticket notifies the new assignee', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'assignee_id' => null,
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
            'status' => 'open',
        ]);

    $this->actingAs($assigningAgent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => $assignedAgent->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}");

    expect($assignedAgent->fresh()->unreadNotifications)->toHaveCount(1)
        ->and($assigningAgent->fresh()->unreadNotifications)->toHaveCount(0);

    $notification = $assignedAgent->fresh()->unreadNotifications()->firstOrFail();

    expect($notification->data)->toMatchArray([
        'kind' => 'ticket_assigned',
        'ticket_id' => $ticket->id,
        'subject' => 'Escalated checkout issue',
        'priority' => 'high',
        'site_name' => 'Acme Docs',
        'assigned_by_name' => 'Ada Agent',
        'url' => "/dashboard/tickets/{$ticket->id}",
    ]);
});

test('ticket assignment alerts with digest cadence keep dashboard delivery but skip immediate mail', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create([
        'name' => 'Bea Builder',
        'alert_preferences' => [
            'mode' => 'assigned',
            'email' => true,
            'cadence' => 'digest',
        ],
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'assignee_id' => null,
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
        ]);

    $this->actingAs($assigningAgent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => $assignedAgent->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}");

    expect((new TicketAssigned($ticket->fresh(), $assigningAgent))->via($assignedAgent))
        ->toBe(['database'])
        ->and($assignedAgent->fresh()->unreadNotifications)->toHaveCount(1);
});

test('ticket assignment notifications queue mail while keeping dashboard delivery synchronous', function (): void {
    config(['queue.default' => 'redis']);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($assignedAgent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
        ]);

    $notification = new TicketAssigned($ticket, $assigningAgent);

    expect($notification)
        ->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->viaConnections())->toBe([
            'database' => 'sync',
            'mail' => 'redis',
        ]);
});

test('ticket assignment email includes searchable ticket context', function (): void {
    URL::useOrigin('https://wayfindr.example.test');
    URL::forceHttps();

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($assignedAgent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
        ]);

    $mail = (new TicketAssigned($ticket, $assigningAgent))->toMail($assignedAgent);

    expect($mail->subject)->toBe('Wayfindr ticket assigned: Escalated checkout issue')
        ->and($mail->introLines)->toBe([
            'Ada Agent assigned you a ticket on Acme Docs.',
            "Ticket: #{$ticket->id}",
            'Priority: High',
        ])
        ->and($mail->actionText)->toBe('Open ticket')
        ->and($mail->actionUrl)->toBe("https://wayfindr.example.test/dashboard/tickets/{$ticket->id}");
});

test('ticket assignment alerts honor quiet and deactivated agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $quietAgent = User::factory()->for($account)->create([
        'name' => 'Bea Quiet',
        'alert_preferences' => ['mode' => 'quiet'],
    ]);
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Casey Deactivated',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $firstTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Quiet assignment',
            'status' => 'open',
        ]);
    $secondTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Deactivated assignment',
            'status' => 'open',
        ]);

    $this->actingAs($assigningAgent)
        ->from("/dashboard/tickets/{$firstTicket->id}")
        ->put("/dashboard/tickets/{$firstTicket->id}/assignee", [
            'assignee_id' => $quietAgent->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$firstTicket->id}");

    $this->actingAs($assigningAgent)
        ->from("/dashboard/tickets/{$secondTicket->id}")
        ->put("/dashboard/tickets/{$secondTicket->id}/assignee", [
            'assignee_id' => $deactivatedAgent->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$secondTicket->id}");

    expect($quietAgent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($deactivatedAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('dashboard shows unread conversation alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-DASH1',
        'subject' => 'Install help',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'I am stuck on the install step.',
    ])->assertCreated();

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Alerts')
        ->assertSee('1 unread')
        ->assertSee('Install help')
        ->assertSee('WF-DASH1')
        ->assertSee('I am stuck on the install step.');
});

test('dashboard shows unread ticket assignment alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($assignedAgent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
            'status' => 'open',
        ]);

    $assignedAgent->notify(new TicketAssigned($ticket, $assigningAgent));

    $this->actingAs($assignedAgent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Alerts')
        ->assertSee('1 unread')
        ->assertSee('Escalated checkout issue')
        ->assertSee('Ticket assigned')
        ->assertSee('Ada Agent assigned this ticket to you.')
        ->assertSee('High priority')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false);
});

test('dashboard exposes calm alert controls and empty state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-CONTROL1',
        'subject' => 'Install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I am stuck on the install step.',
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Mark unread alerts read')
        ->assertSee('Mark read')
        ->assertSee("/dashboard/alerts/{$notification->id}/read", false)
        ->assertSee('/dashboard/alerts/read', false)
        ->assertDontSee('You are caught up.');

    $notification->markAsRead();

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('0 unread');
});

test('the alert center lists every unread alert without a visible cap', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    foreach (range(1, 7) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-OVER'.$index,
            'subject' => 'Overflow alert '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Overflow message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('7 unread')
        // The home panel used to cap at five with an overflow note; the
        // alert center shows the full list.
        ->assertDontSee('Showing 5 latest alerts')
        ->assertSee('WF-OVER1')
        ->assertSee('WF-OVER7');
});

test('agents can review visible unread and recent alerts in an alert center', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $visibleSite = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Private Docs']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Casey Current']);
    $visitor = Visitor::factory()->for($visibleSite)->create(['anonymous_id' => 'anon-docs']);
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create(['anonymous_id' => 'anon-hidden']);
    $conversation = Conversation::factory()->for($visibleSite)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-CENTER1',
        'subject' => 'Install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I am stuck on the install step.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($visibleSite)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
        ]);
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create([
        'support_code' => 'WF-HIDDEN-CENTER',
        'subject' => 'Private issue',
    ]);
    $hiddenMessage = ConversationMessage::factory()->for($hiddenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $hiddenVisitor->id,
        'body' => 'This should not be visible.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $agent->fresh()->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'kind') === 'ticket_assigned')
        ->markAsRead();
    $agent->notify(new ConversationNeedsReply($hiddenMessage));
    $hiddenSite->supportAgents()->sync([$remainingAgent->id]);

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Alert center')
        ->assertSee('Alert snapshot')
        ->assertSee('Visible alerts')
        ->assertSee('2 visible')
        ->assertSee('Unread alerts')
        ->assertSee('1 unread')
        ->assertSee('Conversation alerts')
        ->assertSee('1 conversation')
        ->assertSee('Ticket alerts')
        ->assertSee('1 ticket')
        ->assertSee('aria-label="Alert status: Unread"', false)
        ->assertSee('aria-label="Alert status: Read"', false)
        ->assertSee('Install help')
        ->assertSee('WF-CENTER1')
        ->assertSee('I am stuck on the install step.')
        ->assertSee('Why this alert')
        ->assertSee('Visitor reply is waiting on a conversation you can support.')
        ->assertSee('Open the conversation or mark this alert read once handled.')
        ->assertSee('Escalated checkout issue')
        ->assertSee('Ticket assigned')
        ->assertSee('This ticket was assigned to you.')
        ->assertSee('Open the ticket or mark this alert read once triaged.')
        ->assertSee('High priority')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false)
        ->assertDontSee('Private issue')
        ->assertDontSee('WF-HIDDEN-CENTER')
        ->assertDontSee('This should not be visible.');
});

test('alert center cards surface the next triage move', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs-next']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-NEXTMOVE',
        'subject' => 'Install next step',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help me finish the install?',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Billing handoff',
            'priority' => 'high',
        ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Next move')
        ->assertSee('Open the conversation and reply while the visitor is waiting.')
        ->assertSee('Open conversation')
        ->assertSee('/dashboard/conversations/WF-NEXTMOVE', false)
        ->assertSee('Open the assigned ticket and decide the owner, priority, or next status.')
        ->assertSee('Open ticket')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false);
});

test('alert center explains personal alert delivery context', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'assigned',
            'email' => true,
            'cadence' => 'digest',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('Alert delivery context')
        ->assertSee('Current mode')
        ->assertSee('Only conversations and tickets assigned to me')
        ->assertSee('Only assigned conversations and tickets create new alerts for you.')
        ->assertSee('Dashboard alerts remain the source of truth for support work that needs attention.')
        ->assertSee('Email delivery')
        ->assertSee('Digest preferred')
        ->assertSee('Digest delivery is preferred when the scheduler runs. Dashboard alerts still appear here immediately.')
        ->assertSee(route('dashboard.profile.show'), false)
        ->assertSee('Change alert preferences');
});

test('agents can filter the alert center to unread visible alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $visibleSite = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Private Docs']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Casey Current']);
    $visitor = Visitor::factory()->for($visibleSite)->create(['anonymous_id' => 'anon-docs']);
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create(['anonymous_id' => 'anon-hidden']);
    $conversation = Conversation::factory()->for($visibleSite)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-UNREAD1',
        'subject' => 'Unread install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This still needs attention.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($visibleSite)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Read checkout issue',
            'priority' => 'high',
        ]);
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create([
        'support_code' => 'WF-HIDDEN-UNREAD',
        'subject' => 'Hidden unread issue',
    ]);
    $hiddenMessage = ConversationMessage::factory()->for($hiddenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $hiddenVisitor->id,
        'body' => 'This unread alert should stay hidden.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $agent->fresh()->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'kind') === 'ticket_assigned')
        ->markAsRead();
    $agent->notify(new ConversationNeedsReply($hiddenMessage));
    $hiddenSite->supportAgents()->sync([$remainingAgent->id]);

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread')
        ->assertOk()
        ->assertSee('All alerts')
        ->assertSee('Unread only')
        ->assertSee('Showing unread visible alerts')
        ->assertSee('1 visible')
        ->assertSee('1 unread')
        ->assertSee('Unread install help')
        ->assertSee('WF-UNREAD1')
        ->assertSee('This still needs attention.')
        ->assertDontSee('Read checkout issue')
        ->assertDontSee('Ticket assigned')
        ->assertDontSee('Hidden unread issue')
        ->assertDontSee('WF-HIDDEN-UNREAD')
        ->assertDontSee('This unread alert should stay hidden.');
});

test('agents can narrow alert center alerts by kind and reference search', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $visibleSite = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Private Docs']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Casey Current']);
    $visitor = Visitor::factory()->for($visibleSite)->create(['anonymous_id' => 'anon-docs-filter']);
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create(['anonymous_id' => 'anon-hidden-filter']);
    $conversation = Conversation::factory()->for($visibleSite)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-FILTER1',
        'subject' => 'Filterable install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The installer needs attention.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($visibleSite)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Filterable checkout ticket',
            'priority' => 'high',
        ]);
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create([
        'support_code' => 'WF-HIDDEN-FILTER',
        'subject' => 'Hidden filter issue',
    ]);
    $hiddenMessage = ConversationMessage::factory()->for($hiddenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $hiddenVisitor->id,
        'body' => 'This hidden alert should stay hidden.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $agent->notify(new ConversationNeedsReply($hiddenMessage));
    $hiddenSite->supportAgents()->sync([$remainingAgent->id]);

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=WF-FILTER1')
        ->assertOk()
        ->assertSee('Alert type')
        ->assertSee('Search alerts')
        ->assertSee('Active alert filters')
        ->assertSee('Type: Conversation alerts')
        ->assertSee('Search: WF-FILTER1')
        ->assertSee('/dashboard/alerts?alert_filter=unread&amp;alert_search=WF-FILTER1', false)
        ->assertSee('/dashboard/alerts?alert_filter=unread&amp;alert_kind=conversation', false)
        ->assertSee('/dashboard/alerts?alert_filter=unread', false)
        ->assertSee('Filterable install help')
        ->assertSee('WF-FILTER1')
        ->assertSee('Showing 1 matching conversation alert.')
        ->assertDontSee('Filterable checkout ticket')
        ->assertDontSee('Hidden filter issue')
        ->assertDontSee('WF-HIDDEN-FILTER');

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_kind=ticket&alert_search=Ticket+%23'.$ticket->id)
        ->assertOk()
        ->assertSee('Filterable checkout ticket')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Showing 1 matching ticket alert.')
        ->assertDontSee('Filterable install help')
        ->assertDontSee('WF-FILTER1')
        ->assertDontSee('Hidden filter issue');
});

test('alert center shows focus context for the current filters', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-alert-focus']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-ALERTFOCUS',
        'subject' => 'Alert focus install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The alert focus view needs context.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=WF-ALERTFOCUS')
        ->assertOk()
        ->assertSee('Alert focus')
        ->assertSee('What this alert center is showing before you triage items.')
        ->assertSee('View: Unread only')
        ->assertSee('Type: Conversation alerts')
        ->assertSee('Search: WF-ALERTFOCUS')
        ->assertSee('Visible: 1 visible')
        ->assertSee('Unread: 1 unread')
        ->assertSee('Showing 1 matching conversation alert.');
});

test('alert center gives agents a clearer empty alert search state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs-filter']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-ALERT-MATCH',
        'subject' => 'Filterable install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The installer needs attention.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_kind=conversation&alert_search=missing')
        ->assertOk()
        ->assertSee('No alerts match &quot;missing&quot;.', false)
        ->assertSee('Search checks support codes, ticket numbers, subjects, sites, visitors, and message previews you can still access.')
        ->assertSee('Clear search')
        ->assertSee('Clear all alert filters')
        ->assertDontSee('Filterable install help')
        ->assertDontSee('WF-ALERT-MATCH');
});

test('alert center gives agents a clearer empty unread state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-READ-EMPTY',
        'subject' => 'Handled install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This has already been handled.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->unreadNotifications()->firstOrFail()->markAsRead();

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread')
        ->assertOk()
        ->assertSee('You are caught up.')
        ->assertSee('New eligible visitor replies and ticket assignments will appear here when they need attention.')
        ->assertSee('Show recent alerts')
        ->assertDontSee('Handled install help')
        ->assertDontSee('WF-READ-EMPTY');
});

test('alert center snapshot explains zero count states', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('0 visible')
        ->assertSee('Nothing currently needs attention in this alert view.')
        ->assertSee('0 unread')
        ->assertSee('No unread alerts are waiting for review.')
        ->assertSee('0 conversations')
        ->assertSee('No visitor reply alerts in this view.')
        ->assertSee('0 tickets')
        ->assertSee('No ticket assignment alerts in this view.')
        ->assertSee('No visible alerts yet.');
});

test('alert center mark read controls preserve active filters', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Filtered assignment',
            'priority' => 'high',
        ]);

    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_kind=ticket&alert_search=Ticket+%23'.$ticket->id)
        ->assertOk()
        ->assertSee('name="alert_kind" value="ticket"', false)
        ->assertSee('name="alert_search" value="Ticket #'.$ticket->id.'"', false);

    $this->actingAs($agent)
        ->post("/dashboard/alerts/{$notification->id}/read", [
            'return_to' => 'alerts',
            'alert_kind' => 'ticket',
            'alert_search' => 'Ticket #'.$ticket->id,
        ])
        ->assertRedirect('/dashboard/alerts?alert_kind=ticket&alert_search=Ticket%20%23'.$ticket->id);
});

test('alert center bulk read action explains the current visible scope', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $visibleSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $hiddenSite = Site::factory()->for($account)->create(['name' => 'Private Docs']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Casey Current']);
    $visitor = Visitor::factory()->for($visibleSite)->create(['anonymous_id' => 'anon-docs']);
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create(['anonymous_id' => 'anon-hidden']);
    $conversation = Conversation::factory()->for($visibleSite)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-SCOPED1',
        'subject' => 'Scoped install help',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This filtered conversation should be marked read.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($visibleSite)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Keep this ticket unread',
            'priority' => 'high',
        ]);
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create([
        'support_code' => 'WF-HIDDEN-SCOPE',
        'subject' => 'Hidden scoped issue',
    ]);
    $hiddenMessage = ConversationMessage::factory()->for($hiddenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $hiddenVisitor->id,
        'body' => 'This hidden alert should stay unread.',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new TicketAssigned($ticket, $assigningAgent));
    $agent->notify(new ConversationNeedsReply($hiddenMessage));
    $hiddenSite->supportAgents()->sync([$remainingAgent->id]);

    $conversationNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-SCOPED1');
    $ticketNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'ticket_id') === $ticket->id);
    $hiddenNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-HIDDEN-SCOPE');

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=WF-SCOPED1')
        ->assertOk()
        ->assertSee('Mark matching read')
        ->assertSee('All unread alerts matching this view, including alerts outside the current display, will be marked read.')
        ->assertDontSee('Mark all read');

    $this->actingAs($agent)
        ->post('/dashboard/alerts/read', [
            'return_to' => 'alerts',
            'alert_filter' => 'unread',
            'alert_kind' => 'conversation',
            'alert_search' => 'WF-SCOPED1',
        ])
        ->assertRedirect('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=WF-SCOPED1');

    expect($conversationNotification->fresh()->read())->toBeTrue()
        ->and($ticketNotification->fresh()->unread())->toBeTrue()
        ->and($hiddenNotification->fresh()->unread())->toBeTrue();
});

test('alert center bulk read includes matching unread alerts outside the display cap', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    foreach (range(1, 31) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'assigned_agent_id' => $agent->id,
            'support_code' => 'WF-BULK'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'subject' => 'Bulk scope help '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Bulk scope message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=Bulk+scope')
        ->assertOk()
        ->assertSee('30 visible')
        ->assertSee('31 unread')
        ->assertSee('All unread alerts matching this view, including alerts outside the current display, will be marked read.');

    $this->actingAs($agent)
        ->post('/dashboard/alerts/read', [
            'return_to' => 'alerts',
            'alert_filter' => 'unread',
            'alert_kind' => 'conversation',
            'alert_search' => 'Bulk scope',
        ])
        ->assertRedirect('/dashboard/alerts?alert_filter=unread&alert_kind=conversation&alert_search=Bulk%20scope');

    expect($agent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('alert center keeps older unread alerts visible before capping recent read alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    $olderConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-OLDER-UNREAD',
        'subject' => 'Older unread install blocker',
    ]);
    $olderMessage = ConversationMessage::factory()->for($olderConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This unread alert should stay visible.',
    ]);

    $agent->notify(new ConversationNeedsReply($olderMessage));
    $olderNotification = $agent->unreadNotifications()->firstOrFail();
    $olderNotification->forceFill([
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ])->save();

    foreach (range(1, 35) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'assigned_agent_id' => $agent->id,
            'support_code' => 'WF-READ-'.$index,
            'subject' => 'Handled alert '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Handled message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
        $readAt = now()->subMinutes(35 - $index);
        $agent->unreadNotifications()
            ->latest()
            ->firstOrFail()
            ->forceFill([
                'read_at' => $readAt,
                'created_at' => $readAt,
                'updated_at' => $readAt,
            ])
            ->save();
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts')
        ->assertOk()
        ->assertSee('1 unread')
        ->assertSee('Older unread install blocker')
        ->assertSee('WF-OLDER-UNREAD')
        ->assertSee('This unread alert should stay visible.');
});

test('alert center search can find visible alerts beyond the default display cap', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    $targetConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-DEEPMATCH',
        'subject' => 'Deep matched support trail',
    ]);
    $targetMessage = ConversationMessage::factory()->for($targetConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This older alert is still searchable.',
    ]);

    $agent->notify(new ConversationNeedsReply($targetMessage));
    $targetReadAt = now()->subHours(2);
    $agent->unreadNotifications()
        ->firstOrFail()
        ->forceFill([
            'read_at' => $targetReadAt,
            'created_at' => $targetReadAt,
            'updated_at' => $targetReadAt,
        ])
        ->save();

    foreach (range(1, 35) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'assigned_agent_id' => $agent->id,
            'support_code' => 'WF-RECENT-'.$index,
            'subject' => 'Recent handled alert '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Recent handled message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
        $readAt = now()->subMinutes(35 - $index);
        $agent->unreadNotifications()
            ->latest()
            ->firstOrFail()
            ->forceFill([
                'read_at' => $readAt,
                'created_at' => $readAt,
                'updated_at' => $readAt,
            ])
            ->save();
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_search=WF-DEEPMATCH')
        ->assertOk()
        ->assertSee('Deep matched support trail')
        ->assertSee('WF-DEEPMATCH')
        ->assertSee('Showing 1 matching alert.')
        ->assertDontSee('Recent handled alert 35');
});

test('alert center counts filtered unread alerts before applying the display cap', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    foreach (range(1, 35) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'assigned_agent_id' => $agent->id,
            'support_code' => 'WF-UNREAD-FILTER-'.$index,
            'subject' => 'Unread filtered alert '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Unread filtered message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_kind=conversation')
        ->assertOk()
        ->assertSee('30 visible')
        ->assertSee('35 unread')
        ->assertSee('30 shown of 35 matching conversation alerts.')
        ->assertSee('Showing 30 alerts after the current display cap. 35 conversation alerts match this view.')
        ->assertDontSee('Showing 30 matching conversation alerts.')
        ->assertDontSee('No visible alerts match those filters.');
});

test('alert center clarifies unread only alerts beyond the display cap', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    foreach (range(1, 35) as $index) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'assigned_agent_id' => $agent->id,
            'support_code' => 'WF-UNREAD-ONLY-'.$index,
            'subject' => 'Unread only alert '.$index,
        ]);
        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Unread only message '.$index,
        ]);

        $agent->notify(new ConversationNeedsReply($message));
    }

    $this->actingAs($agent)
        ->get('/dashboard/alerts?alert_filter=unread')
        ->assertOk()
        ->assertSee('30 visible')
        ->assertSee('35 unread')
        ->assertSee('30 shown of 35 matching unread alerts.')
        ->assertSee('Showing 30 alerts after the current display cap. 35 unread alerts match this view.')
        ->assertDontSee('Showing unread visible alerts.');
});

test('alert center mark read controls return agents to the alert center', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-CENTER2',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Could you look at this?',
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $this->actingAs($agent)
        ->post("/dashboard/alerts/{$notification->id}/read", [
            'return_to' => 'alerts',
            'alert_filter' => 'unread',
        ])
        ->assertRedirect('/dashboard/alerts?alert_filter=unread');

    expect($notification->fresh()->read())->toBeTrue();
});

test('agent can mark one alert as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);

    $firstConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ONE1',
        'subject' => 'First issue',
    ]);
    $secondConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ONE2',
        'subject' => 'Second issue',
    ]);

    $firstMessage = ConversationMessage::factory()->for($firstConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'First thing is stuck.',
    ]);
    $secondMessage = ConversationMessage::factory()->for($secondConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Second thing is stuck.',
    ]);

    $agent->notify(new ConversationNeedsReply($firstMessage));
    $agent->notify(new ConversationNeedsReply($secondMessage));
    $firstNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-ONE1');
    $secondNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-ONE2');

    $this->actingAs($agent)
        ->post("/dashboard/alerts/{$firstNotification->id}/read")
        ->assertRedirect('/dashboard#alerts');

    expect($firstNotification->fresh()->read())->toBeTrue()
        ->and($secondNotification->fresh()->unread())->toBeTrue();
});

test('agent can mark all conversation alerts as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ALL1',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Could someone help?',
    ]);

    $agent->notify(new ConversationNeedsReply($message));
    $agent->notify(new ConversationNeedsReply($message));
    $otherAgent->notify(new ConversationNeedsReply($message));

    $this->actingAs($agent)
        ->post('/dashboard/alerts/read')
        ->assertRedirect('/dashboard#alerts');

    expect($agent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($agent->fresh()->readNotifications)->toHaveCount(2)
        ->and($otherAgent->fresh()->unreadNotifications)->toHaveCount(1);
});

test('agent cannot mark another agent alert as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BOUNDARY1',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This alert belongs to Bea.',
    ]);
    $otherAgent->notify(new ConversationNeedsReply($message));
    $notification = $otherAgent->unreadNotifications()->firstOrFail();

    $this->actingAs($agent)
        ->post("/dashboard/alerts/{$notification->id}/read")
        ->assertNotFound();

    expect($notification->fresh()->unread())->toBeTrue();
});

test('agent cannot mark a stale conversation alert read after losing site access', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STALE1',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This should no longer be visible.',
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    $site->supportAgents()->sync([$remainingAgent->id]);

    $this->actingAs($agent)
        ->post("/dashboard/alerts/{$notification->id}/read")
        ->assertNotFound();

    expect($notification->fresh()->unread())->toBeTrue();
});

test('deactivated agents cannot view stale support alerts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-STALE2',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This alert should be stale after deactivation.',
    ]);
    $agent->notify(new ConversationNeedsReply($message));
    $notification = $agent->unreadNotifications()->firstOrFail();

    expect(Gate::forUser($agent)->allows('view', $notification))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('markRead', $notification))->toBeFalse();
});

test('agent mark all read skips stale alerts after losing site access', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $remainingAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $visibleSite = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $hiddenSite = Site::factory()->for($account)->create(['public_key' => 'site_hidden_docs']);
    $visibleVisitor = Visitor::factory()->for($visibleSite)->create(['anonymous_id' => 'anon-visible']);
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create(['anonymous_id' => 'anon-hidden']);
    $visibleConversation = Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create([
        'support_code' => 'WF-VISIBLE1',
    ]);
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create([
        'support_code' => 'WF-HIDDEN1',
    ]);
    $visibleMessage = ConversationMessage::factory()->for($visibleConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visibleVisitor->id,
        'body' => 'This one is still visible.',
    ]);
    $hiddenMessage = ConversationMessage::factory()->for($hiddenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $hiddenVisitor->id,
        'body' => 'This one should stay unread.',
    ]);
    $agent->notify(new ConversationNeedsReply($visibleMessage));
    $agent->notify(new ConversationNeedsReply($hiddenMessage));
    $visibleNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-VISIBLE1');
    $hiddenNotification = $agent->unreadNotifications()
        ->get()
        ->firstOrFail(fn ($notification): bool => data_get($notification->data, 'support_code') === 'WF-HIDDEN1');

    $hiddenSite->supportAgents()->sync([$remainingAgent->id]);

    $this->actingAs($agent)
        ->post('/dashboard/alerts/read')
        ->assertRedirect('/dashboard#alerts');

    expect($visibleNotification->fresh()->read())->toBeTrue()
        ->and($hiddenNotification->fresh()->unread())->toBeTrue();
});

test('opening a conversation marks its unread alerts as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-READ1',
    ]);
    $token = notificationVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Could you look at this?',
    ])->assertCreated();

    expect($agent->unreadNotifications)->toHaveCount(1);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-READ1')
        ->assertOk();

    expect($agent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($agent->fresh()->readNotifications)->toHaveCount(1);
});

test('opening an assigned ticket marks its unread assignment alerts as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($assignedAgent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $assignedAgent->notify(new TicketAssigned($ticket, $assigningAgent));

    expect($assignedAgent->unreadNotifications)->toHaveCount(1);

    $this->actingAs($assignedAgent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk();

    expect($assignedAgent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($assignedAgent->fresh()->readNotifications)->toHaveCount(1);
});

test('replying to a conversation marks its unread alerts as read', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-READ2',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can I get an update?',
    ]);
    $agent->notify(new ConversationNeedsReply($message));

    expect($agent->unreadNotifications)->toHaveCount(1);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-READ2/messages', [
            'body' => 'Yes, I am on it.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-READ2');

    expect($agent->fresh()->unreadNotifications)->toHaveCount(0)
        ->and($agent->fresh()->readNotifications)->toHaveCount(1);
});

function notificationVisitorToken($test, string $sitePublicKey, string $anonymousId): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $sitePublicKey,
        'anonymous_id' => $anonymousId,
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');
}
