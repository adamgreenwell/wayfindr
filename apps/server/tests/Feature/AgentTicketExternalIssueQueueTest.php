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
use Illuminate\Support\Facades\DB;

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

test('the ticket queue does not query audit events once per ticket', function (): void {
    // `TicketExternalIssueAttempt::auditEventsForTicket()` went straight to the
    // relation, so the queue's eager load was thrown away and every ticket cost
    // its own query. Its two siblings -- `externalLinksForTicket()` on the same
    // class and `TicketExternalIssueState::forTicket()` -- both check
    // `relationLoaded()` first; this one did not, and that single missing check
    // was the whole N+1.
    //
    // Counted rather than timed: the query COUNT is deterministic, and it is the
    // figure that tells the two shapes apart. Asserted as "does not grow with
    // the number of tickets", not as an absolute, so an unrelated query added to
    // this page later does not fail this test for the wrong reason.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    $countFor = function (int $tickets) use ($account, $agent, $site): int {
        Ticket::query()->delete();

        foreach (range(1, $tickets) as $i) {
            $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

            // A tracked event on each, so the collection this reads is not
            // empty -- an N+1 over nothing still costs a query, but a fixture
            // that never populates the relation cannot show the eager load
            // being used either.
            AuditEvent::factory()
                ->for($account)
                ->for($ticket, 'subject')
                ->create([
                    'action' => 'ticket.external_sync_failed',
                    'metadata' => ['provider' => 'github', 'project_key' => 'acme/api'],
                    'occurred_at' => now()->subMinutes(5),
                ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->actingAs($agent)->get('/dashboard/tickets')->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    $few = $countFor(2);
    $many = $countFor(12);

    expect($many)->toBeLessThanOrEqual($few + 2,
        "the ticket queue issued {$few} queries for 2 tickets and {$many} for 12: it is querying per ticket");
});
