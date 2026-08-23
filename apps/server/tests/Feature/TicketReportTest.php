<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\TicketReport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface as DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Reports launched covering conversations only, whose lifecycle events began on
 * 22 August. Ticket lifecycle has been audited since 24 May -- so the page had
 * its deepest data for the half it did not show.
 */
function ticketReportWorld(): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin, 'name' => 'Ada']);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    return compact('account', 'agent', 'site');
}

function ticketEvent(Ticket $ticket, User $agent, string $action, $at): void
{
    $ticket->auditEvents()->create([
        'account_id' => $ticket->account_id,
        'site_id' => $ticket->site_id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => $action,
        'metadata' => [],
        'occurred_at' => $at,
    ]);
}

function stamp(string $key, DateTimeInterface $at): void
{
    DB::table('operator_settings')->insert([
        'key' => $key,
        'value' => CarbonImmutable::instance(Carbon::instance($at))->toDateTimeString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ticketReportFor(array $world, int $days = 30): TicketReport
{
    return new TicketReport(
        ReportingScope::for($world['account'], $world['agent'], null),
        ReportingWindow::fromRequestValue((string) $days),
    );
}

test('a ticket closed three times contributes three resolutions', function (): void {
    // The payoff of recording the sequence rather than a reopen_count column:
    // a column cannot answer this, and one long span would report a ticket
    // resolved in a week that was actually resolved three times in a day.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(10),
    ]);

    foreach ([[9, 'closed'], [8, 'reopened'], [7, 'closed'], [6, 'reopened'], [5, 'closed']] as [$daysAgo, $kind]) {
        ticketEvent($ticket, $w['agent'], $kind === 'closed' ? TicketReport::CLOSED : TicketReport::REOPENED, now()->subDays($daysAgo));
    }

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['summary']->count)->toBe(3)
        ->and($resolution['reopened'])->toBe(2)
        ->and($resolution['closed'])->toBe(3);
});

test('resolution is measured from the reopen that started it, not from creation', function (): void {
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(60),
    ]);

    // The close that the reopen undid is older than the window, which is the
    // ordinary case: the episode being measured began before the range the
    // reader selected, and only its close falls inside.
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(50));
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    // One day, not fifty-nine.
    expect($resolution['summary']->median)->toBeLessThan(2 * 24 * 3600)
        // And the close outside the window is not counted as one inside it.
        ->and($resolution['closed'])->toBe(1);
});

test('taking a pending ticket off hold is not a resolution that failed', function (): void {
    // The ticket UI offers one Reopen control for a CLOSED ticket and a PENDING
    // one, so `open -> pending -> reopen -> close` recorded a reopen. Counting
    // it claims a resolution failed when none was reached, and restarts the
    // clock at the un-hold -- hiding every hour before the ticket went on hold.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(10),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['reopened'])->toBe(0)
        // Nine days: the whole time the ticket was open, hold included. Not one.
        ->and($resolution['summary']->median)->toBeGreaterThan(8 * 24 * 3600);
});

test('a close submitted twice is one resolution, not two', function (): void {
    // A double-click, a retry, or a stale page. Ticket closes had no write-time
    // guard until this change, so real installs have these in their history.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(5),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(2)->addSeconds(3));

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['closed'])->toBe(1)
        ->and($resolution['summary']->count)->toBe(1)
        // The chart and the agent table have to agree with that total, or the
        // tab contradicts itself.
        ->and(ticketReportFor($w)->volume()['closed_total'])->toBe(1)
        ->and(ticketReportFor($w)->agentActivity()[0]['closes'])->toBe(1);
});

test('the conversation boundary does not apply to tickets', function (): void {
    // Two separate keys, deliberately. The conversation boundary is recent and
    // the ticket one is usually much older, so reading the wrong one would drop
    // months of measurable ticket work.
    $w = ticketReportWorld();
    stamp('reporting.lifecycle_recording_began_at', now()->subDay());

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(20)]);
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subHours(2));

    expect(ticketReportFor($w)->resolution()['summary']->count)->toBe(1);
});

test('a ticket older than this install\'s ticket recording is counted, not measured', function (): void {
    // The first version of this report claimed tickets needed no boundary at
    // all. False on an upgraded install: this ticket may have been closed and
    // reopened while nothing was writing ticket.reopened, and measuring from
    // creation charges the close with work that was already finished.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(10));

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(60)]);
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subHours(2));

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['summary']->count)->toBe(0)
        ->and($resolution['unmeasurable'])->toBe(1)
        // Still a close. Dropping it from the total would understate the work.
        ->and($resolution['closed'])->toBe(1);
});

test('a reopen after the boundary makes an old ticket measurable again', function (): void {
    // The reopen IS the episode start, so however old the ticket is, everything
    // needed to measure this close is on record.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(10));

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(60)]);
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['summary']->count)->toBe(1)
        ->and($resolution['unmeasurable'])->toBe(0)
        ->and($resolution['summary']->median)->toBeLessThan(2 * 24 * 3600);
});

test('a ticket opened after the boundary is measured normally', function (): void {
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(30));

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(3)]);
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(2));

    expect(ticketReportFor($w)->resolution()['unmeasurable'])->toBe(0)
        ->and(ticketReportFor($w)->resolution()['summary']->count)->toBe(1);
});

test('volume counts what opened and what closed in the window', function (): void {
    $w = ticketReportWorld();

    Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(3), 'status' => 'open']);
    Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(200), 'status' => 'open']);

    $closed = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(5), 'status' => 'closed']);
    ticketEvent($closed, $w['agent'], TicketReport::CLOSED, now()->subDays(4));

    $volume = ticketReportFor($w)->volume();

    expect($volume['opened_total'])->toBe(2, 'the 200-day-old one is outside the window')
        ->and($volume['closed_total'])->toBe(1)
        ->and($volume['open_now'])->toBe(2);
});

test('another account’s tickets are never counted', function (): void {
    $w = ticketReportWorld();
    $stranger = Account::factory()->create();
    $strangerSite = Site::factory()->for($stranger)->create();
    Ticket::factory()->for($stranger)->for($strangerSite)->create(['created_at' => now()->subDay()]);

    expect(ticketReportFor($w)->volume()['opened_total'])->toBe(0);
});

test('the agent table names who carried the ticket work', function (): void {
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(3)]);

    ticketEvent($ticket, $w['agent'], TicketReport::REPLY_SENT, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::REPLY_SENT, now()->subDay());
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subHours(6));

    $rows = ticketReportFor($w)->agentActivity();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Ada')
        ->and($rows[0]['replies'])->toBe(2)
        ->and($rows[0]['closes'])->toBe(1);
});

test('the reports page shows the ticket half it used to ignore', function (): void {
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(3),
        'subject' => 'A ticket',
    ]);
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $this->actingAs($w['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('Tickets')
        ->assertSee('Ticket volume')
        ->assertSee('Ticket resolution')
        ->assertSee('Who carried the ticket work')
        // The ticket half states its OWN boundary. It must not repeat the
        // conversation date, and it must not claim to have no boundary.
        ->assertDontSee('no recording-start caveat')
        // And the chart uses the classes that have CSS behind them.
        ->assertSee('chart__bar chart__bar--opened', false);
});

test('no query binds more ids than the chunk size, however many tickets closed', function (): void {
    // The invariant the 500-chunk loop exists for. It has no visible effect on
    // a small install -- a report that loads every creation time up front
    // returns the same numbers -- so it survived a refactor that hoisted the
    // creation lookup out of the loop and only failed on a busy quarter, where
    // the driver refuses the statement and the page 500s.
    $w = ticketReportWorld();

    $tickets = [];

    for ($i = 0; $i < 1200; $i++) {
        $tickets[] = [
            'account_id' => $w['account']->id,
            'site_id' => $w['site']->id,
            'status' => 'closed',
            'priority' => 'normal',
            'subject' => 'Ticket '.$i,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ];
    }

    DB::table('tickets')->insert($tickets);

    $events = [];

    foreach (DB::table('tickets')->pluck('id') as $id) {
        $events[] = [
            'account_id' => $w['account']->id,
            'site_id' => $w['site']->id,
            'actor_type' => User::class,
            'actor_id' => $w['agent']->id,
            'subject_type' => (new Ticket)->getMorphClass(),
            'subject_id' => $id,
            'action' => TicketReport::CLOSED,
            'occurred_at' => now()->subDays(4),
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ];
    }

    DB::table('audit_events')->insert($events);

    $widest = 0;

    DB::listen(function ($query) use (&$widest): void {
        $widest = max($widest, count($query->bindings));
    });

    $resolution = ticketReportFor($w)->resolution();

    // Every ticket is still measured -- the chunking must not lose any.
    expect($resolution['summary']->count)->toBe(1200)
        ->and($widest)->toBeLessThanOrEqual(510);
});

test('an empty ticket history does not claim nothing was closed when it cannot know', function (): void {
    // The caveat used to be nested under the branch that had a measurable
    // close, so precisely the case that needed it -- an upgraded install whose
    // window reaches back past its boundary, with nothing on record -- got the
    // flat assertion that no ticket was closed.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(3));

    Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(90)]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('Ticket closes and reopens have been recorded since long before')
        ->assertSee('No ticket close is on record in this period')
        ->assertDontSee('No ticket was closed in this period.');
});

test('a window entirely inside the recorded history says plainly that nothing closed', function (): void {
    // The caveat is a statement about what this install cannot know. Showing it
    // when the whole window is on record would make an honest empty period look
    // like missing data.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(200));

    Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(5)]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('No ticket was closed in this period.')
        ->assertDontSee('No ticket close is on record in this period');
});

test('a reopen still open at the end of the range is counted', function (): void {
    // The regression the normalised walk introduced: reopens used to come from
    // their own query and now come from the walk, which was seeded only from
    // in-window CLOSES. A ticket closed before the range, reopened inside it,
    // and still open at the end has no in-window close -- so it never entered
    // the walk and the most interesting event the report has was reported as
    // zero.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(90),
        'status' => 'open',
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(60));
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(3));

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['reopened'])->toBe(1)
        // Still open, so nothing resolved -- the reopen is the whole story.
        ->and($resolution['closed'])->toBe(0)
        ->and($resolution['summary']->count)->toBe(0);
});

test('a ticket selected only by its reopen still has duplicate closes normalised', function (): void {
    // Widening the selection must not widen what counts. The walk decides from
    // history, not from why the ticket was selected.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(20),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(10));
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(5));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(4));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(4)->addSeconds(2));

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['reopened'])->toBe(1)
        ->and($resolution['closed'])->toBe(2)
        ->and(ticketReportFor($w)->volume()['closed_total'])->toBe(2);
});

test('a historical un-hold recorded as a reopen is not counted as one', function (): void {
    // Before the write-path guard, taking a PENDING ticket off hold wrote
    // `ticket.reopened`. On an upgraded install that history exists, and for a
    // ticket older than the recording boundary the walk starts in UNKNOWN --
    // where a reopen looks exactly like a genuine one.
    //
    // The `ticket.pending` that preceded it is on record and settles it.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(10));

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(90),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::PENDING, now()->subDays(6));
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(5));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['reopened'])->toBe(0)
        // The close still counts, and is still unmeasurable: the ticket
        // predates the boundary and nothing since has established a start.
        ->and($resolution['closed'])->toBe(1)
        ->and($resolution['unmeasurable'])->toBe(1)
        ->and($resolution['summary']->count)->toBe(0);
});

test('a genuine reopen from unknown state is still counted', function (): void {
    // The other half: without a pending on record, a reopen from UNKNOWN does
    // prove the ticket had been closed, and narrowing must not swallow it.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(10));

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(90),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(5));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['reopened'])->toBe(1)
        // And the reopen established a start, so this close IS measurable.
        ->and($resolution['summary']->count)->toBe(1)
        ->and($resolution['unmeasurable'])->toBe(0);
});

test('a ticket closed straight out of pending is a normal resolution', function (): void {
    // Pending is not closed. A ticket resolved while on hold, without being
    // reopened first, resolves normally.
    $w = ticketReportWorld();

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(5),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::PENDING, now()->subDays(3));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    $resolution = ticketReportFor($w)->resolution();

    expect($resolution['closed'])->toBe(1)
        ->and($resolution['summary']->count)->toBe(1)
        ->and($resolution['reopened'])->toBe(0);
});

test('a reopen with no close in the range is visible on the page, not just in the figures', function (): void {
    // The backend counted this correctly and the page did not render it: with
    // no measured close the view showed only the "nothing closed" notice and
    // skipped the table the Reopened row lives in. The test that proved the
    // count asked the report object and never rendered anything.
    $w = ticketReportWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(90),
        'status' => 'open',
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDays(60));
    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(3));

    $this->actingAs($w['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('Reopened')
        ->assertSee('A resolution that did not hold. Nothing closed in this period');
});

test('reopens are named even when every close in range is unmeasurable', function (): void {
    // The other branch with the same gap: closes exist, none can be measured,
    // and a reopen is still countable.
    $w = ticketReportWorld();
    stamp('reporting.ticket_lifecycle_recording_began_at', now()->subDays(10));

    $old = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(90)]);
    ticketEvent($old, $w['agent'], TicketReport::CLOSED, now()->subDays(2));

    $other = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'created_at' => now()->subDays(90),
        'status' => 'open',
    ]);
    ticketEvent($other, $w['agent'], TicketReport::CLOSED, now()->subDays(40));
    ticketEvent($other, $w['agent'], TicketReport::REOPENED, now()->subDays(3));

    $this->actingAs($w['agent'])
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('reopened in this period')
        ->assertSee('countable even where the durations are not');
});
