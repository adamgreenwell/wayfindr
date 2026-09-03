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
            ->assertSeeText('GitHub sync failed')
            ->assertSeeText('acme/api needs attention. Provider details withheld.')
            ->assertSee('5 minutes ago')
            ->assertSeeText('GitLab sync pending')
            ->assertSeeText('acme/status is waiting for provider confirmation.')
            ->assertSee('3 minutes ago')
            ->assertSeeText('GitHub issue created')
            ->assertSeeText('acme/docs is linked to #456.')
            ->assertSee('2 minutes ago')
            ->assertSeeText('GitHub link removed')
            ->assertSeeText('acme/removed is no longer linked to #789.')
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

test('the ticket detail page still finds an attempt that is only an audit event', function (): void {
    // The regression the eager-load fix nearly caused, and the reason the queue
    // hands its collections over explicitly instead of the helper picking up
    // whatever relation happens to be loaded.
    //
    // This page eager-loads `auditEvents` constrained to `ticket.note_added` for
    // its timeline. That is not a tracked external-issue action, so a helper
    // that reused the relation because it was present would filter it to
    // nothing and report "No external attempt yet" -- on a ticket whose only
    // attempt is an event, which is exactly the ticket this is about.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

    // A note, so the page's own constrained eager load is NOT empty -- an empty
    // one would fall through and pass whether the bug is present or not.
    AuditEvent::factory()->for($account)->for($ticket, 'subject')->create([
        'action' => 'ticket.note_added',
        'metadata' => ['note' => 'Looking into this.'],
        'occurred_at' => now()->subMinutes(20),
    ]);

    // The attempt itself, with no external link behind it.
    AuditEvent::factory()->for($account)->for($ticket, 'subject')->create([
        'action' => 'ticket.external_issue_created',
        'metadata' => ['provider' => 'github', 'project_key' => 'acme/api'],
        'occurred_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('acme/api')
        ->assertDontSee(__('tickets.external_attempt.none_label'));
});

test('the attention chips do not advertise tickets the external filter removes', function (): void {
    // The two refinements interact. Counts have to come from the SQL query
    // after external state narrows it and before attention state does, or a
    // chip advertises tickets the linked queue will remove.
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    // Unassigned, so both are `needs_owner`, and both open.
    $withFailure = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Has a failed export',
        'status' => 'open',
        'assignee_id' => null,
    ]);

    AuditEvent::factory()->for($account)->for($withFailure, 'subject')->create([
        'action' => 'ticket.external_sync_failed',
        'metadata' => ['provider' => 'github', 'project_key' => 'acme/api'],
        'occurred_at' => now()->subMinutes(5),
    ]);

    Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Has no external issue at all',
        'status' => 'open',
        'assignee_id' => null,
    ]);

    // Filtered to tickets whose external issue FAILED: one of the two.
    $response = $this->actingAs($agent)->get('/dashboard/tickets?ticket_external=failed');

    $response->assertOk()
        ->assertSee('Has a failed export')
        ->assertDontSee('Has no external issue at all');

    $chips = collect($response->viewData('ticketQueueSummary'))
        ->mapWithKeys(fn (array $row): array => [$row['state'] => $row['count']]);

    expect($chips['needs_owner'])
        ->toBe(1, 'the needs-owner chip counted a ticket the external filter removes');
});

test('the attention filter still applies when the external filter is active', function (): void {
    // Both refinements run in SQL now: attention counts are taken after the
    // external filter, and the attention predicate is applied to what is left.
    // Two failed exports in different attention states, and a needs-owner
    // ticket with no export, make each ordering mistake visible.
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    $failedNeedsOwner = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Failed export, nobody owns it',
        'status' => 'open',
        'assignee_id' => null,
    ]);

    $failedNeedsAgent = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Failed export, owned and silent',
        'status' => 'open',
        'assignee_id' => $agent->id,
        'conversation_id' => null,
    ]);

    foreach ([$failedNeedsOwner, $failedNeedsAgent] as $ticket) {
        AuditEvent::factory()->for($account)->for($ticket, 'subject')->create([
            'action' => 'ticket.external_sync_failed',
            'metadata' => ['provider' => 'github', 'project_key' => 'acme/api'],
            'occurred_at' => now()->subMinutes(5),
        ]);
    }

    Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'No export, nobody owns it',
        'status' => 'open',
        'assignee_id' => null,
    ]);

    $response = $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_external=failed&ticket_attention=needs_owner');

    $response->assertOk()
        ->assertSee('Failed export, nobody owns it')
        ->assertDontSee('Failed export, owned and silent')
        ->assertDontSee('No export, nobody owns it');

    // Counted after the external filter and before the attention one: the
    // other failed ticket's state is still advertised, the no-export ticket's
    // is not.
    $chips = collect($response->viewData('ticketQueueSummary'))
        ->mapWithKeys(fn (array $row): array => [$row['state'] => $row['count']]);

    expect($chips['needs_owner'])->toBe(1, 'the needs-owner chip counted a ticket the external filter removes')
        ->and($chips['needs_agent'])->toBe(1, 'the needs-agent chip lost the failed ticket the attention filter hides');
});

test('external issue filtering happens before the ticket row cap', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    // Newer local-only tickets can fill the whole page. If the cap runs before
    // the external-state predicate, the older failed ticket disappears and a
    // refined queue lies by looking empty.
    Ticket::factory()
        ->count(Ticket::QUEUE_DISPLAY_LIMIT)
        ->for($account)
        ->for($site)
        ->create([
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $failed = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Older failed export still belongs in this lane',
        'status' => 'open',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($failed)
        ->create(['sync_status' => ExternalIssueSyncStatus::FAILED]);

    $response = $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_external=failed');

    $response->assertOk()
        ->assertSee('Older failed export still belongs in this lane');

    expect($response->viewData('tickets'))->toHaveCount(1)
        ->and($response->viewData('ticketQueueShownOf'))->toBe(1);
});
