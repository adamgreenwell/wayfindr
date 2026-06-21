<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ticket detail summarizes support artifact coverage for agent confidence', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-artifacts']);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-ARTIFACTS',
            'subject' => 'Artifact coverage',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Coverage ticket',
        ]);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'VIP']);
    $ticket->labels()->attach($label);

    AuditEvent::factory()
        ->for($account)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.note_added',
            'actor_id' => $agent->id,
            'actor_type' => User::class,
            'metadata' => [
                'body' => 'private note should not appear in the coverage summary',
            ],
        ]);

    $response = $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'Support artifacts',
            'Conversation',
            'Linked',
            'Visitor',
            'Linked',
            'Labels',
            '1 label',
            'Internal notes',
            '1 note',
            'External issues',
            'Not linked',
            'Support reference',
        ]);

    expect(Str::between($response->content(), 'Support artifacts', 'Support reference'))
        ->not->toContain('private note should not appear in the coverage summary');
});
