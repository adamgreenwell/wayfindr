<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ticket detail work state summarizes latest visitor activity and reply action', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'Can someone help me finish the billing export?',
        'created_at' => now(),
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $ticket = Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Billing export help',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'Work state',
            'Latest activity',
            'Visitor message',
            'Can someone help me finish the billing export?',
            'Next action',
            'Reply to visitor',
            'Support reference',
        ])
        ->assertSee('Owner')
        ->assertSee('Ada Agent')
        ->assertSee('href="#ticket-reply"', false);
});

test('ticket detail work state summarizes latest agent activity and status action', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

    ConversationMessage::factory()->for($conversation)->create([
        'body' => 'I sent the workaround and will wait for confirmation.',
        'created_at' => now(),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);

    $ticket = Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Billing export follow-up',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'Work state',
            'Agent reply',
            'I sent the workaround and will wait for confirmation.',
            'Next action',
            'Wait on customer',
            'Support reference',
        ])
        ->assertSee('Review status actions')
        ->assertSee('href="#ticket-actions-heading"', false);
});

test('ticket detail work state falls back to standalone ticket context', function (): void {
    [$agent, $site, $visitor] = ticketWorkStateContext(false);

    $ticket = Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'description' => 'Follow up with operations about the missing label.',
            'subject' => 'Missing label follow-up',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'Work state',
            'Ticket summary',
            'Follow up with operations about the missing label.',
            'Next action',
            'Add the next update',
            'Support reference',
        ]);
});

test('ticket detail work state shows linked ticket timing context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 16:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'Can someone help me finish the billing export?',
            'created_at' => now()->subHours(3),
            'sender_id' => $visitor->id,
            'sender_type' => Visitor::class,
        ]);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($conversation)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'created_at' => now()->subDays(2),
                'subject' => 'Billing export timing context',
                'updated_at' => now()->subHours(3),
            ]);

        $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Work state',
                'Timing',
                'Opened 2 days ago',
                'Waiting on reply for 3 hours',
                'Support reference',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail work state shows graceful standalone timing context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 16:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor] = ticketWorkStateContext(false);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'created_at' => now()->subDays(5),
                'description' => 'Follow up with operations about the missing label.',
                'subject' => 'Standalone timing context',
                'updated_at' => now()->subDays(5),
            ]);

        $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Work state',
                'Timing',
                'Opened 5 days ago',
                'Waiting on agent update since ticket opened',
                'Support reference',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail work state keeps internal notes out of the summary preview', function (): void {
    [$agent, $site, $visitor] = ticketWorkStateContext(false);

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

    $response = $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'Work state',
            'No activity preview yet',
            'Open the ticket to add context or send the next update.',
            'Support reference',
        ]);

    expect(Str::between($response->content(), 'Work state', 'Support reference'))
        ->not->toContain('private escalation note for agents only');
});

/**
 * @return array{0: User, 1: Site, 2: Visitor, 3?: Conversation}
 */
function ticketWorkStateContext(bool $withConversation = true): array
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
            'support_code' => 'WF-WORKSTATE',
            'status' => 'open',
        ]);

    return [$agent, $site, $visitor, $conversation];
}
