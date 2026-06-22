<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Support\ExternalIssueSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket links preserve the current ticket queue filters for detail page return navigation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'VIP',
        'slug' => 'vip',
    ]);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-QUEUERETURN',
            'status' => 'open',
        ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help with the refund?',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'category' => 'billing',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Refund support request',
        ]);
    $ticket->labels()->attach($label);
    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create([
            'sync_status' => ExternalIssueSyncStatus::FAILED,
        ]);

    $ticketQueueQuery = [
        'ticket_status' => 'all',
        'ticket_filter' => 'assigned_to_me',
        'ticket_site' => $site->id,
        'ticket_priority' => 'high',
        'ticket_category' => 'billing',
        'ticket_label' => 'vip',
        'ticket_attention' => 'needs_reply',
        'ticket_external' => 'failed',
        'ticket_search' => 'refund',
    ];

    $ticketUrl = route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQueueQuery);
    $returnUrl = route('dashboard.tickets.index', $ticketQueueQuery);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index', $ticketQueueQuery))
        ->assertOk()
        ->assertSee('Refund support request')
        ->assertSee('href="'.e($ticketUrl).'"', false);

    $this->actingAs($agent)
        ->get($ticketUrl)
        ->assertOk()
        ->assertSee('Back to ticket queue')
        ->assertSee('href="'.e($returnUrl).'"', false);
});

test('ticket queue summarizes the current workload focus', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-focus']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'VIP',
        'slug' => 'vip',
    ]);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-QUEUEFOCUS',
            'status' => 'open',
        ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help with the refund?',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'category' => 'billing',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Refund support request',
        ]);
    $ticket->labels()->attach($label);
    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create([
            'sync_status' => ExternalIssueSyncStatus::FAILED,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_filter' => 'assigned_to_me',
            'ticket_site' => $site->id,
            'ticket_priority' => 'high',
            'ticket_category' => 'billing',
            'ticket_label' => 'vip',
            'ticket_attention' => 'needs_reply',
            'ticket_external' => 'failed',
            'ticket_search' => 'refund',
        ]))
        ->assertOk()
        ->assertSee('Queue focus')
        ->assertSee('What this ticket queue is showing before you open a row.')
        ->assertSee('Status: All tickets')
        ->assertSee('Assignee: Assigned to me')
        ->assertSee('Site: Acme Docs')
        ->assertSee('Priority: High')
        ->assertSee('Category: Billing')
        ->assertSee('Label: VIP')
        ->assertSee('Next step: Needs reply')
        ->assertSee('External issue: Needs attention')
        ->assertSee('Search: refund');
});

test('ticket queue distinguishes shown tickets from broader matching filters', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-counts']);

    $needsReplyConversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-COUNTREPLY',
            'status' => 'open',
        ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help with billing?',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Needs a support reply',
        ]);

    $waitingConversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-COUNTWAIT',
            'status' => 'open',
        ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I sent the next step.',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Customer follow-up needed',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Ready for an agent update',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_filter' => 'assigned_to_me',
            'ticket_attention' => 'needs_reply',
        ]))
        ->assertOk()
        ->assertSee('1 shown of 3 matching tickets')
        ->assertSee('Showing 1 ticket after the Needs reply next-step filter. 3 tickets match the other queue filters.')
        ->assertSee('Needs a support reply')
        ->assertDontSee('Customer follow-up needed')
        ->assertDontSee('Ready for an agent update');
});
