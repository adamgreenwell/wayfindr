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

test('ticket actions warn agents before closing while the visitor is waiting', function (): void {
    [$agent, $ticket] = ticketLifecycleReadinessContext('visitor');

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Status action readiness')
        ->assertSee('Reply before closing')
        ->assertSee('Visitor replied last. Closing now may leave the customer waiting.')
        ->assertSee('Use pending or close only after an agent update or a confirmed outcome.')
        ->assertSee('href="#ticket-reply"', false);
});

test('ticket actions prioritize visitor waiting warning for unassigned tickets', function (): void {
    [$agent, $ticket] = ticketLifecycleReadinessContext('visitor', assignee: false);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Status action readiness')
        ->assertSee('Reply before closing')
        ->assertSee('Visitor replied last. Closing now may leave the customer waiting.')
        ->assertSee('href="#ticket-reply"', false);
});

test('ticket actions present calm lifecycle options after an agent reply', function (): void {
    [$agent, $ticket] = ticketLifecycleReadinessContext('agent');

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Status action readiness')
        ->assertSee('Lifecycle options are calm')
        ->assertSee('Agent replied last. Mark pending if you are waiting on the visitor, or close once the outcome is settled.')
        ->assertSee('Review status actions');
});

test('ticket actions explain reopening closed tickets', function (): void {
    [$agent, $ticket] = ticketLifecycleReadinessContext('none', 'closed');

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Status action readiness')
        ->assertSee('Closed ticket')
        ->assertSee('Reopen only if the customer comes back or the outcome changes.')
        ->assertSee('Use the reopen note to leave the next agent enough context.');
});

function ticketLifecycleReadinessContext(string $latestMessage = 'none', string $status = 'open', bool $assignee = true): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIFECYCLE',
        'status' => 'open',
    ]);

    if ($latestMessage !== 'none') {
        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => $latestMessage === 'visitor' ? Visitor::class : User::class,
            'sender_id' => $latestMessage === 'visitor' ? $visitor->id : $agent->id,
            'body' => $latestMessage === 'visitor'
                ? 'Can someone help before this closes?'
                : 'I sent the next step.',
        ]);
    }

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->for($conversation)
        ->create([
            'assignee_id' => $assignee ? $agent->id : null,
            'closed_at' => $status === 'closed' ? now()->subMinute() : null,
            'status' => $status,
            'subject' => 'Lifecycle action readiness',
        ]);

    return [$agent, $ticket];
}
