<?php

use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can leave a handoff note when marking a ticket pending', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Waiting on customer details',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post(route('dashboard.tickets.pending', $ticket), [
            'pending_note' => 'Waiting for the customer to confirm their billing contact.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket marked pending.');

    $ticket->refresh();

    expect($ticket->status)->toBe('pending')
        ->and($ticket->auditEvents()->where('action', 'ticket.pending')->first()?->metadata)
        ->toMatchArray([
            'pending_note' => 'Waiting for the customer to confirm their billing contact.',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Ticket marked pending')
        ->assertSee('Waiting for the customer to confirm their billing contact.');
});

test('agent can leave a handoff note when reopening a ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'closed_at' => now()->subMinute(),
            'status' => 'closed',
            'subject' => 'Needs follow-up',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post(route('dashboard.tickets.reopen', $ticket), [
            'reopen_note' => 'Customer replied with a new invoice screenshot.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket reopened.');

    $ticket->refresh();

    expect($ticket->status)->toBe('open')
        ->and($ticket->closed_at)->toBeNull()
        ->and($ticket->auditEvents()->where('action', 'ticket.reopened')->first()?->metadata)
        ->toMatchArray([
            'reopen_note' => 'Customer replied with a new invoice screenshot.',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Ticket reopened')
        ->assertSee('Customer replied with a new invoice screenshot.');
});
