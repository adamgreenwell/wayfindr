<?php

use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Only a TRANSITION is an event -- the rule conversation lifecycle has followed
 * since ADR 0015, and which ticket lifecycle did not. Every reporting figure
 * derived from this log inherits whatever it records, so a spurious row here is
 * a wrong number on a page somebody makes decisions from.
 */
function ticketTransitionWorld(string $status = 'open'): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'status' => $status,
        'closed_at' => $status === 'closed' ? now()->subDay() : null,
    ]);

    return compact('account', 'agent', 'site', 'ticket');
}

function ticketTransitionActions(Ticket $ticket): array
{
    return $ticket->auditEvents()->orderBy('id')->pluck('action')->all();
}

test('closing a ticket twice records one close', function (): void {
    // A double-click, a retry, or a stale page. Two rows make one resolution
    // contribute two durations to the report and inflate every close count.
    $w = ticketTransitionWorld();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($w['agent'])->post(route('dashboard.tickets.close', $w['ticket']), []);
    }

    expect(array_filter(ticketTransitionActions($w['ticket']), fn (string $a): bool => $a === 'ticket.closed'))->toHaveCount(1);
});

test('a re-submitted close does not move the moment the ticket was closed', function (): void {
    // Otherwise every duplicate submission quietly shortens the resolution time
    // of the ticket it lands on.
    $w = ticketTransitionWorld();

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.close', $w['ticket']), []);

    $closedAt = $w['ticket']->fresh()->closed_at;

    $this->travel(2)->hours();

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.close', $w['ticket']), []);

    expect($w['ticket']->fresh()->closed_at->timestamp)->toBe($closedAt->timestamp);
});

test('reopening a closed ticket is a reopen', function (): void {
    $w = ticketTransitionWorld('closed');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.reopen', $w['ticket']), []);

    expect(ticketTransitionActions($w['ticket']))->toContain('ticket.reopened')
        ->and($w['ticket']->fresh()->status)->toBe('open');
});

test('ticket update automation runs after the triggering lifecycle event', function (): void {
    $w = ticketTransitionWorld();
    $rule = AutomationRule::factory()->for($w['account'])->enabled()->create([
        'name' => 'Reopen freshly closed tickets',
        'event' => AutomationRuleEvent::TicketUpdated,
        'conditions' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'closed'],
        ],
        'actions' => [['type' => 'set_status', 'value' => 'open']],
    ]);

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.close', $w['ticket']), []);

    expect($w['ticket']->fresh()->status)->toBe('open')
        ->and(ticketTransitionActions($w['ticket']))->toBe(['ticket.closed', 'ticket.reopened'])
        ->and(AutomationRuleExecution::query()->sole()->automation_rule_id)->toBe($rule->id);
});

test('taking a pending ticket off hold is not a reopen', function (): void {
    // The same control does both, and `open -> pending -> reopen -> close` is
    // the ordinary flow rather than an edge case.
    $w = ticketTransitionWorld('pending');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.reopen', $w['ticket']), []);

    expect(ticketTransitionActions($w['ticket']))->not->toContain('ticket.reopened')
        ->and(ticketTransitionActions($w['ticket']))->toContain('ticket.unheld')
        ->and($w['ticket']->fresh()->status)->toBe('open');
});

test('taking a ticket off hold is shown in its activity, not silently dropped', function (): void {
    // A new action that no display list knows about renders as a blank row or
    // vanishes from the timeline entirely.
    $w = ticketTransitionWorld('pending');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.reopen', $w['ticket']), [
        'reopen_note' => 'Customer came back with the serial number.',
    ]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.tickets.show', $w['ticket']))
        ->assertOk()
        ->assertSee('Ticket taken off hold')
        ->assertSee('Customer came back with the serial number.');
});

test('reopening an already-open ticket records nothing', function (): void {
    // A retried submit or a stale form. The close path has a guard for exactly
    // this and the reopen path did not -- it wrote an un-hold for a hold that
    // never happened, which is the same duplicate-event bug one branch over.
    $w = ticketTransitionWorld('open');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.reopen', $w['ticket']), []);

    expect(ticketTransitionActions($w['ticket']))->not->toContain('ticket.unheld')
        ->and(ticketTransitionActions($w['ticket']))->not->toContain('ticket.reopened');
});

test('reopening a closed ticket twice records one reopen', function (): void {
    $w = ticketTransitionWorld('closed');

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($w['agent'])->post(route('dashboard.tickets.reopen', $w['ticket']), []);
    }

    expect(array_filter(ticketTransitionActions($w['ticket']), fn (string $a): bool => $a === 'ticket.reopened'))
        ->toHaveCount(1);
});

test('marking a closed ticket pending records the reopen it performs', function (): void {
    // The form is only offered for open tickets, so this is a stale or crafted
    // submit -- but it still un-closes the ticket, and recording only the hold
    // would leave the resolution looking like it held.
    $w = ticketTransitionWorld('closed');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.pending', $w['ticket']), []);

    expect(ticketTransitionActions($w['ticket']))->toContain('ticket.reopened')
        ->and(ticketTransitionActions($w['ticket']))->toContain('ticket.pending')
        ->and($w['ticket']->fresh()->status)->toBe('pending')
        ->and($w['ticket']->fresh()->closed_at)->toBeNull();
});

test('marking an open ticket pending records only the hold', function (): void {
    $w = ticketTransitionWorld('open');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.pending', $w['ticket']), []);

    expect(ticketTransitionActions($w['ticket']))->not->toContain('ticket.reopened')
        ->and(ticketTransitionActions($w['ticket']))->toContain('ticket.pending');
});

test('marking a pending ticket pending again records nothing', function (): void {
    $w = ticketTransitionWorld('pending');

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.pending', $w['ticket']), []);

    expect(array_filter(ticketTransitionActions($w['ticket']), fn (string $a): bool => $a === 'ticket.pending'))
        ->toHaveCount(0);
});

test('a status change and its event are one transaction', function (): void {
    // Recorded while the lock is still held. Committing the status first and
    // logging after lets the next writer take the lock and insert its event
    // ahead -- a reopen before the close that preceded it, for a ticket that
    // ended up open. SQLite compiles `lockForUpdate` to nothing, so what is
    // provable here is the transaction; the lock itself is guarded structurally.
    $w = ticketTransitionWorld();

    $levels = [];

    AuditEvent::creating(function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $this->actingAs($w['agent'])->post(route('dashboard.tickets.close', $w['ticket']), []);

    expect($levels)->not->toBeEmpty()
        ->and(array_filter($levels, fn (int $level): bool => $level < 1))->toBeEmpty();
});

test('every ticket status write goes through the locking transition', function (): void {
    // Structural, in the style of the repository's other invariant checks: the
    // guards decide what the reports count, so a future edit that writes
    // `status` directly would reintroduce the read-check-write race without
    // failing a behavioural test -- SQLite cannot show the race at all.
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/AgentTicketController.php');

    expect($source)->not->toBeFalse();

    // Comments stripped first: the docblock explaining this rule names the very
    // things it looks for.
    $code = '';

    foreach (token_get_all((string) $source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->toContain('lockForUpdate()');

    // Each lifecycle action routes through the helper rather than writing the
    // status itself. Counting `'status' => ...` across the file would be
    // simpler and wrong: this controller also writes a CONVERSATION status when
    // an agent replies from the ticket view.
    foreach (['close', 'reopen', 'pending'] as $action) {
        $start = strpos($code, 'public function '.$action.'(');

        expect($start)->not->toBeFalse();

        $next = strpos($code, 'public function ', $start + 1);
        $body = substr($code, $start, $next === false ? null : $next - $start);

        expect($body)->toContain('$this->transitionTicketStatus(')
            ->and($body)->not->toContain("->forceFill(['status'");
    }
});
