<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Support\ExternalIssueSyncStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ticket queue shows sanitized latest external issue attempt context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-22 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

        $failedTicket = Ticket::factory()
            ->for($account)
            ->for($site)
            ->create([
                'subject' => 'GitHub export failed',
                'status' => 'open',
            ]);
        AuditEvent::factory()
            ->for($account)
            ->for($failedTicket, 'subject')
            ->create([
                'action' => 'ticket.external_sync_failed',
                'metadata' => [
                    'provider' => 'github',
                    'project_key' => 'acme/api',
                    'message' => 'GitHub token ghp_secret_value was rejected.',
                ],
                'occurred_at' => now()->subMinutes(5),
                'site_id' => $site->id,
            ]);

        $pendingTicket = Ticket::factory()
            ->for($account)
            ->for($site)
            ->create([
                'subject' => 'GitLab export pending',
                'status' => 'open',
            ]);
        TicketExternalLink::factory()
            ->for($account)
            ->for($site)
            ->for($pendingTicket)
            ->create([
                'provider' => 'gitlab',
                'project_key' => 'acme/status',
                'sync_status' => ExternalIssueSyncStatus::PENDING,
                'updated_at' => now()->subMinutes(3),
            ]);

        $linkedTicket = Ticket::factory()
            ->for($account)
            ->for($site)
            ->create([
                'subject' => 'GitHub export linked',
                'status' => 'open',
            ]);
        AuditEvent::factory()
            ->for($account)
            ->for($linkedTicket, 'subject')
            ->create([
                'action' => 'ticket.external_issue_created',
                'metadata' => [
                    'provider' => 'github',
                    'project_key' => 'acme/docs',
                    'external_key' => '#456',
                ],
                'occurred_at' => now()->subMinutes(2),
                'site_id' => $site->id,
            ]);

        $removedTicket = Ticket::factory()
            ->for($account)
            ->for($site)
            ->create([
                'subject' => 'GitHub export removed',
                'status' => 'open',
            ]);
        AuditEvent::factory()
            ->for($account)
            ->for($removedTicket, 'subject')
            ->create([
                'action' => 'ticket.external_link_removed',
                'metadata' => [
                    'provider' => 'github',
                    'project_key' => 'acme/removed',
                    'external_key' => '#789',
                ],
                'occurred_at' => now()->subMinute(),
                'site_id' => $site->id,
            ]);

        $this->actingAs($agent)
            ->get(route('dashboard.tickets.index', ['ticket_status' => 'all']))
            ->assertOk()
            ->assertSee('Latest attempt')
            ->assertSee('GitHub sync failed')
            ->assertSee('acme/api needs attention. Provider details withheld.')
            ->assertSee('5 minutes ago')
            ->assertSee('GitLab sync pending')
            ->assertSee('acme/status is waiting for provider confirmation.')
            ->assertSee('3 minutes ago')
            ->assertSee('GitHub issue created')
            ->assertSee('acme/docs is linked to #456.')
            ->assertSee('2 minutes ago')
            ->assertSee('GitHub link removed')
            ->assertSee('acme/removed is no longer linked to #789.')
            ->assertSee('1 minute ago')
            ->assertDontSee('ghp_secret_value');
    } finally {
        Carbon::setTestNow();
    }
});
