<?php

use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can manually escalate a ticket to an eligible site agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $targetAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $targetAgent->id]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Customer needs billing help',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/escalations", [
            'target_agent_id' => $targetAgent->id,
            'reason' => 'Customer has an enterprise billing question.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket escalated.');

    $ticket->refresh();
    $event = $ticket->auditEvents()->where('action', 'ticket.escalated')->firstOrFail();

    expect($ticket->assignee_id)->toBe($targetAgent->id)
        ->and($event->actor_id)->toBe($agent->id)
        ->and($event->metadata)->toMatchArray([
            'old_assignee_name' => 'Ada Agent',
            'new_assignee_name' => 'Bea Builder',
            'target_agent_id' => $targetAgent->id,
            'target_agent_name' => 'Bea Builder',
            'reason' => 'Customer has an enterprise billing question.',
        ]);

    $notification = $targetAgent->fresh()->unreadNotifications()->firstOrFail();

    expect($notification->type)->toBe(TicketAssigned::class)
        ->and($notification->data)->toMatchArray([
            'kind' => 'ticket_assigned',
            'ticket_id' => $ticket->id,
            'assigned_by_name' => 'Ada Agent',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Ticket escalated from Ada Agent to Bea Builder')
        ->assertSee('Customer has an enterprise billing question.');
});

test('agent cannot escalate a ticket to an agent outside the ticket site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $targetAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach($agent);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/escalations", [
            'target_agent_id' => $targetAgent->id,
            'reason' => 'Needs a specialist.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors([
            'target_agent_id' => 'Choose an agent assigned to this site.',
        ]);

    expect($ticket->fresh()->assignee_id)->toBe($agent->id)
        ->and($ticket->auditEvents()->where('action', 'ticket.escalated')->exists())->toBeFalse()
        ->and($targetAgent->fresh()->unreadNotifications)->toHaveCount(0);
});

test('manual escalation notifies the target even when they already own the ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $targetAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $targetAgent->id]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($targetAgent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Needs a specialist nudge',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/escalations", [
            'target_agent_id' => $targetAgent->id,
            'reason' => 'Customer is waiting on the existing owner.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket escalated.');

    expect($ticket->fresh()->assignee_id)->toBe($targetAgent->id)
        ->and($ticket->auditEvents()->where('action', 'ticket.escalated')->exists())->toBeTrue()
        ->and($targetAgent->fresh()->unreadNotifications)->toHaveCount(1);
});

test('escalation choices omit deactivated agents when site access falls back to all account agents', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $targetAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $deactivatedAgent = User::factory()->for($account)->create([
        'name' => 'Dana Deactivated',
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Escalate ticket')
        ->assertSee('Bea Builder')
        ->assertDontSee('Dana Deactivated');

    expect($site->supportsAgent($targetAgent))->toBeTrue()
        ->and($site->supportsAgent($deactivatedAgent))->toBeFalse();
});

test('ticket detail highlights the latest recent escalation reason', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $escalatingAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $escalatingAgent->id]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Customer needs billing help',
        ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $escalatingAgent->id,
        'action' => 'ticket.escalated',
        'metadata' => [
            'old_assignee_name' => 'Ada Agent',
            'target_agent_id' => $agent->id,
            'target_agent_name' => 'Bea Builder',
            'reason' => 'Customer has an enterprise billing question.',
        ],
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Escalation')
        ->assertSee('Ada Agent escalated this ticket to Bea Builder')
        ->assertSee('Customer has an enterprise billing question.')
        ->assertSee('Escalated to you');
});

test('latest escalation event breaks timestamp ties by newest audit row', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Charlie Closer']);
    $escalatingAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $otherAgent->id, $escalatingAgent->id]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'status' => 'open',
            'subject' => 'Customer needs billing help',
        ]);
    $timestamp = now()->setMicrosecond(0);

    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $escalatingAgent->id,
        'action' => 'ticket.escalated',
        'metadata' => [
            'target_agent_id' => $agent->id,
            'target_agent_name' => 'Bea Builder',
            'reason' => 'First escalation reason.',
        ],
        'occurred_at' => $timestamp,
    ]);
    $newerEvent = $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $escalatingAgent->id,
        'action' => 'ticket.escalated',
        'metadata' => [
            'target_agent_id' => $otherAgent->id,
            'target_agent_name' => 'Charlie Closer',
            'reason' => 'Second escalation reason.',
        ],
        'occurred_at' => $timestamp,
    ]);

    $ticket = $ticket->fresh();

    expect($ticket->latestRecentEscalationEvent()?->is($newerEvent))->toBeTrue()
        ->and($ticket->latestRecentEscalationEvent()?->metadata)->toMatchArray([
            'target_agent_id' => $otherAgent->id,
            'target_agent_name' => 'Charlie Closer',
            'reason' => 'Second escalation reason.',
        ])
        ->and($ticket->escalationAudienceLabelFor($agent))->toBe('Recently escalated')
        ->and($ticket->escalationAudienceLabelFor($otherAgent))->toBe('Escalated to you');
});
