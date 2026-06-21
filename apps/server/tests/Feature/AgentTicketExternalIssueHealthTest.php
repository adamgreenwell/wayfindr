<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
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

test('ticket detail offers retry for retryable outbound issue creation failures', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $connection = ExternalIssueProviderConnection::factory()
        ->for($ticket->account)
        ->create([
            'provider' => 'github',
            'is_enabled' => true,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $project = SiteExternalIssueProject::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
        ]);

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
                'site_external_issue_project_id' => $project->id,
                'message' => 'GitHub token ghp_secret_value was rejected.',
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('GitHub could not sync adamgreenwell/wayfindr.')
        ->assertSee('Provider details withheld')
        ->assertSee('Retry GitHub issue')
        ->assertSee('Retry uses the current site project mapping and the conservative export payload.')
        ->assertSee(route('dashboard.tickets.external-issues.github.store', $ticket), false)
        ->assertSee('value="'.$project->id.'"', false)
        ->assertDontSee('ghp_secret_value');
});

test('ticket detail offers retry for retryable gitlab issue creation failures', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $connection = ExternalIssueProviderConnection::factory()
        ->for($ticket->account)
        ->create([
            'provider' => 'gitlab',
            'is_enabled' => true,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $project = SiteExternalIssueProject::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'acme/docs',
        ]);

    AuditEvent::factory()
        ->for($ticket->account)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.external_sync_failed',
            'actor_id' => $agent->id,
            'actor_type' => User::class,
            'metadata' => [
                'provider' => 'gitlab',
                'project_key' => 'acme/docs',
                'site_external_issue_project_id' => $project->id,
                'message' => 'GitLab token glpat_secret_value was rejected.',
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('GitLab could not sync acme/docs.')
        ->assertSee('Retry GitLab issue')
        ->assertSee(route('dashboard.tickets.external-issues.gitlab.store', $ticket), false)
        ->assertSee('value="'.$project->id.'"', false)
        ->assertDontSee('glpat_secret_value');
});

test('ticket detail hides retry when the failed external issue project is no longer retryable', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $connection = ExternalIssueProviderConnection::factory()
        ->for($ticket->account)
        ->create([
            'provider' => 'github',
            'is_enabled' => true,
            'capabilities' => [
                'create_issue' => false,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $project = SiteExternalIssueProject::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
        ]);

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
                'site_external_issue_project_id' => $project->id,
                'message' => 'GitHub token ghp_secret_value was rejected.',
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('GitHub could not sync adamgreenwell/wayfindr.')
        ->assertSee('Retry unavailable')
        ->assertSee('Check the site external issue settings before retrying.')
        ->assertDontSee('Retry GitHub issue')
        ->assertDontSee('ghp_secret_value');
});

test('ticket detail hides retry when the failed external issue project belongs to another account', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()
        ->for($otherAccount)
        ->create();
    $otherConnection = ExternalIssueProviderConnection::factory()
        ->for($otherAccount)
        ->create([
            'provider' => 'github',
            'is_enabled' => true,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $otherProject = SiteExternalIssueProject::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherConnection, 'providerConnection')
        ->create([
            'project_key' => 'other/account',
        ]);

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
                'site_external_issue_project_id' => $otherProject->id,
                'message' => 'GitHub token ghp_secret_value was rejected.',
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('GitHub could not sync adamgreenwell/wayfindr.')
        ->assertSee('Retry unavailable')
        ->assertDontSee('Retry GitHub issue')
        ->assertDontSee('other/account')
        ->assertDontSee('ghp_secret_value');
});

test('ticket detail hides retry when a later external issue creation succeeded for the failed project', function (): void {
    [$agent, $ticket] = ticketExternalIssueHealthContext();
    $connection = ExternalIssueProviderConnection::factory()
        ->for($ticket->account)
        ->create([
            'provider' => 'github',
            'is_enabled' => true,
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ]);
    $project = SiteExternalIssueProject::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($connection, 'providerConnection')
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
        ]);

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
                'site_external_issue_project_id' => $project->id,
                'message' => 'GitHub token ghp_secret_value was rejected.',
            ],
            'occurred_at' => now()->subMinute(),
            'site_id' => $ticket->site_id,
        ]);
    AuditEvent::factory()
        ->for($ticket->account)
        ->for($ticket, 'subject')
        ->create([
            'action' => 'ticket.external_issue_created',
            'actor_id' => $agent->id,
            'actor_type' => User::class,
            'metadata' => [
                'provider' => 'github',
                'project_key' => 'adamgreenwell/wayfindr',
                'external_key' => '#123',
                'site_external_issue_project_id' => $project->id,
            ],
            'occurred_at' => now(),
            'site_id' => $ticket->site_id,
        ]);
    TicketExternalLink::factory()
        ->for($ticket->account)
        ->for($ticket->site)
        ->for($ticket)
        ->create([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'sync_status' => 'linked',
            'metadata' => [
                'site_external_issue_project_id' => $project->id,
            ],
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Healthy')
        ->assertSee('1 linked')
        ->assertSee('External issue links are not reporting failures for this ticket.')
        ->assertDontSee('GitHub could not sync adamgreenwell/wayfindr.')
        ->assertDontSee('Retry GitHub issue')
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
