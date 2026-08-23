<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\TicketReport;
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
        'created_at' => now()->subDays(30),
    ]);

    ticketEvent($ticket, $w['agent'], TicketReport::REOPENED, now()->subDays(2));
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subDay());

    // One day, not twenty-nine.
    expect(ticketReportFor($w)->resolution()['summary']->median)->toBeLessThan(2 * 24 * 3600);
});

test('ticket history carries no recording-start caveat', function (): void {
    // reporting.lifecycle_recording_began_at exists because CONVERSATION events
    // are new. Ticket events predate every install that has tickets, so a close
    // on an old ticket is measurable and must not be dropped as unmeasurable.
    $w = ticketReportWorld();
    DB::table('operator_settings')->insert([
        'key' => 'reporting.lifecycle_recording_began_at',
        'value' => now()->subDay()->toDateTimeString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create(['created_at' => now()->subDays(20)]);
    ticketEvent($ticket, $w['agent'], TicketReport::CLOSED, now()->subHours(2));

    expect(ticketReportFor($w)->resolution()['summary']->count)->toBe(1);
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
        // The caveat that belongs to the conversation half must not be
        // repeated over figures it is not true of.
        ->assertSee('no recording-start caveat')
        // And the chart uses the classes that have CSS behind them.
        ->assertSee('chart__bar chart__bar--opened', false);
});
