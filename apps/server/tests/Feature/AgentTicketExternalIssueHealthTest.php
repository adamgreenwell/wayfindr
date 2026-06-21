<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket detail shows healthy external issue health for linked external issues', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();

    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($ticket)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'linked',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External links',
            'External issue health',
            'Healthy',
            'Linked',
            '1 linked',
            'Sync pending',
            '0 sync pending',
            'Sync failed',
            '0 sync failed',
        ]);
});

test('ticket detail shows pending external issue health for pending external issues', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();

    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($ticket)
        ->create([
            'provider' => 'gitlab',
            'project_key' => 'acme/docs',
            'sync_status' => 'sync_pending',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External issue health',
            'Sync pending',
            '0 linked',
            '1 sync pending',
            '0 sync failed',
        ]);
});

test('ticket detail shows failed external issue health without raw provider details', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();

    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($ticket)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'sync_failed',
            'metadata' => [
                'raw_error' => 'token ghp_secret_value leaked by provider',
            ],
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External issue health',
            'Needs attention',
            'GitHub could not sync adamgreenwell/wayfindr.',
            'Provider details withheld',
        ])
        ->assertDontSee('ghp_secret_value');
});

test('ticket detail includes outbound issue creation failures in external issue health', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();

    AuditEvent::factory()
        ->for($ticket->account)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.external_sync_failed',
            'actor_id' => $agent->id,
            'actor_type' => User::class,
            'metadata' => [
                'provider' => 'github',
                'project_key' => 'adamgreenwell/wayfindr',
                'message' => 'GitHub token ghp_secret_value was rejected.',
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External issue health',
            'Needs attention',
            'GitHub could not sync adamgreenwell/wayfindr.',
            'Provider details withheld',
        ])
        ->assertDontSee('ghp_secret_value');
});

test('ticket detail shows an empty external issue health state', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External issue health',
            'No external links',
            'No external issues linked to this ticket yet.',
        ]);
});

test('ticket external issue health is scoped to the current ticket and account', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $otherTicket = Ticket::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->create();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherAccountTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create();

    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($ticket)
        ->create(['sync_status' => 'linked']);
    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($otherTicket)
        ->create(['sync_status' => 'sync_failed']);
    TicketExternalLink::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherAccountTicket)
        ->create(['sync_status' => 'sync_pending']);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSeeInOrder([
            'External issue health',
            'Healthy',
            '1 linked',
            '0 sync pending',
            '0 sync failed',
        ])
        ->assertDontSee('Needs attention');
});

/**
 * @return array{0: User, 1: Ticket}
 */
function ticketExternalIssueHealthContext(): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'External issue health',
        ]);

    return [$agent, $ticket];
}
