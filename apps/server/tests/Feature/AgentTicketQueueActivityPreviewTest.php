<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket queue shows the latest visitor message preview for linked tickets', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketQueuePreviewContext();

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Earlier agent setup message.',
        'created_at' => now()->subMinutes(5),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'I cannot export the June invoice after checkout.',
        'created_at' => now()->subMinute(),
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Invoice export is failing',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Latest activity')
        ->assertSee('Visitor message')
        ->assertSee('I cannot export the June invoice after checkout.');
});

test('ticket queue shows the latest agent reply preview for linked tickets', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketQueuePreviewContext();

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'The invoice export fails.',
        'created_at' => now()->subMinutes(5),
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'I sent a workaround and will confirm the export.',
        'created_at' => now()->subMinute(),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);

    Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Invoice export follow-up',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Agent reply')
        ->assertSee('I sent a workaround and will confirm the export.');
});

test('ticket queue orders linked activity previews by message timestamp before id', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketQueuePreviewContext();

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Newest visitor activity by timestamp.',
        'created_at' => now(),
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Historical message inserted during an import.',
        'created_at' => now()->subDay(),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);

    Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Imported conversation follow-up',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Visitor message')
        ->assertSee('Newest visitor activity by timestamp.')
        ->assertDontSee('Historical message inserted during an import.');
});

test('ticket queue falls back to safe standalone ticket context', function (): void {
    [$agent, $site, $visitor] = ticketQueuePreviewContext(false);

    Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'description' => 'Follow up with operations about the missing label.',
            'subject' => 'Missing label follow-up',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Ticket summary')
        ->assertSee('Follow up with operations about the missing label.');
});

test('ticket queue keeps internal notes out of standalone activity previews', function (): void {
    [$agent, $site, $visitor] = ticketQueuePreviewContext(false);

    $ticket = Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'description' => null,
            'subject' => 'Quiet standalone ticket',
        ]);

    AuditEvent::factory()
        ->for($agent->account)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.note_added',
            'actor_id' => $agent->id,
            'actor_type' => User::class,
            'metadata' => [
                'body' => 'private escalation note for agents only',
            ],
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('No activity preview yet')
        ->assertSee('Open the ticket to add context or send the next update.')
        ->assertDontSee('private escalation note for agents only');
});

/**
 * @return array{0: User, 1: Site, 2: Visitor, 3?: Conversation}
 */
function ticketQueuePreviewContext(bool $withConversation = true): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    if (! $withConversation) {
        return [$agent, $site, $visitor];
    }

    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-TICKETPREVIEW',
            'status' => 'open',
        ]);

    return [$agent, $site, $visitor, $conversation];
}
