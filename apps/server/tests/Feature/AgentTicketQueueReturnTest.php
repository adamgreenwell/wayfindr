<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
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

    $ticketQueueQuery = [
        'ticket_status' => 'all',
        'ticket_filter' => 'assigned_to_me',
        'ticket_site' => $site->id,
        'ticket_priority' => 'high',
        'ticket_category' => 'billing',
        'ticket_label' => 'vip',
        'ticket_attention' => 'needs_reply',
        'ticket_search' => 'refund',
    ];

    $ticketUrl = route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQueueQuery);
    $returnUrl = route('dashboard', $ticketQueueQuery).'#tickets';

    $this->actingAs($agent)
        ->get(route('dashboard', $ticketQueueQuery))
        ->assertOk()
        ->assertSee('Refund support request')
        ->assertSee('href="'.e($ticketUrl).'"', false);

    $this->actingAs($agent)
        ->get($ticketUrl)
        ->assertOk()
        ->assertSee('Back to ticket queue')
        ->assertSee('href="'.e($returnUrl).'"', false);
});
