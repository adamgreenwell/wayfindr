<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket detail recommends the next action for the ticket state', function (array $case): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $conversation = null;

    if (in_array($case['state'], ['needs_reply', 'waiting_on_customer'], true)) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-NEXTACTION',
            'status' => 'open',
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => $case['state'] === 'needs_reply' ? Visitor::class : User::class,
            'sender_id' => $case['state'] === 'needs_reply' ? $visitor->id : $agent->id,
            'body' => $case['state'] === 'needs_reply' ? 'Can someone help?' : 'I sent the next step.',
        ]);
    }

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->when($conversation, fn ($factory) => $factory->for($conversation))
        ->when($case['assignee'] ?? false, fn ($factory) => $factory->for($agent, 'assignee'))
        ->create([
            'closed_at' => $case['status'] === 'closed' ? now()->subMinute() : null,
            'status' => $case['status'],
            'subject' => $case['subject'],
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Next action')
        ->assertSee($case['title'])
        ->assertSee($case['body'])
        ->assertSee($case['cta'])
        ->assertSee('href="'.$case['href'].'"', false);
})->with([
    'needs reply' => [[
        'assignee' => true,
        'body' => 'Visitor replied last. Send a clear response, then mark the ticket pending or close it when the outcome is settled.',
        'cta' => 'Jump to reply',
        'href' => '#ticket-reply',
        'state' => 'needs_reply',
        'status' => 'open',
        'subject' => 'Visitor needs a reply',
        'title' => 'Reply to visitor',
    ]],
    'needs owner' => [[
        'body' => 'No agent owns this ticket yet. Assign someone before work gets lost.',
        'cta' => 'Assign ticket',
        'href' => '#ticket-actions-heading',
        'state' => 'needs_owner',
        'status' => 'open',
        'subject' => 'Needs an owner',
        'title' => 'Assign an owner',
    ]],
    'needs agent' => [[
        'assignee' => true,
        'body' => 'This ticket is assigned and ready for an agent update. Add a reply, internal note, or status change.',
        'cta' => 'Review actions',
        'href' => '#ticket-actions-heading',
        'state' => 'needs_agent',
        'status' => 'open',
        'subject' => 'Ready for agent update',
        'title' => 'Add the next update',
    ]],
    'waiting on customer' => [[
        'assignee' => true,
        'body' => 'Agent replied last. Keep the ticket visible, then reopen the loop when the visitor answers.',
        'cta' => 'Review status actions',
        'href' => '#ticket-actions-heading',
        'state' => 'waiting_on_customer',
        'status' => 'open',
        'subject' => 'Waiting on customer',
        'title' => 'Wait on customer',
    ]],
    'resolved' => [[
        'assignee' => true,
        'body' => 'This ticket is closed. Reopen it only if the customer comes back or the outcome changes.',
        'cta' => 'Review status actions',
        'href' => '#ticket-actions-heading',
        'state' => 'resolved',
        'status' => 'closed',
        'subject' => 'Resolved ticket',
        'title' => 'Review resolution',
    ]],
]);
