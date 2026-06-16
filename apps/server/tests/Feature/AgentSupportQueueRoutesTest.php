<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents use a dedicated conversations queue instead of a dashboard anchor', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-conv-route']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-QUEUE1',
        'subject' => 'Widget handoff',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Conversations')
        ->assertSee('Widget handoff')
        ->assertSee('WF-QUEUE1')
        ->assertSee('/dashboard/conversations?conversation_filter=needs_reply', false)
        ->assertDontSee('id="tickets"', false);
});

test('agents use a dedicated tickets queue instead of a dashboard anchor', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-ticket-route']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETQUEUE',
        'status' => 'open',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Billing handoff',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_filter=assigned_to_me')
        ->assertOk()
        ->assertSee('Tickets')
        ->assertSee('Assigned to me')
        ->assertSee('Billing handoff')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false)
        ->assertSee('WF-TICKETQUEUE')
        ->assertSee('/dashboard/tickets?ticket_filter=unassigned', false)
        ->assertDontSee('id="conversations"', false);
});

test('dashboard sends agents to dedicated support queues without rendering the queues inline', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-dashboard-route']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DASHCONV',
        'subject' => 'Dashboard should not inline me',
        'status' => 'open',
    ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Dashboard should not inline this ticket',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('/dashboard/conversations', false)
        ->assertSee('/dashboard/tickets', false)
        ->assertDontSee('/dashboard#conversations', false)
        ->assertDontSee('/dashboard#tickets', false)
        ->assertDontSee('id="conversations"', false)
        ->assertDontSee('id="tickets"', false)
        ->assertDontSee('Dashboard should not inline me')
        ->assertDontSee('Dashboard should not inline this ticket');
});

test('legacy dashboard queue filters redirect to the dedicated queue routes', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard?conversation_filter=needs_reply')
        ->assertRedirect(route('dashboard.conversations.index', ['conversation_filter' => 'needs_reply']));

    $this->actingAs($agent)
        ->get('/dashboard?ticket_status=pending&ticket_filter=unassigned')
        ->assertRedirect(route('dashboard.tickets.index', [
            'ticket_status' => 'pending',
            'ticket_filter' => 'unassigned',
        ]));
});
