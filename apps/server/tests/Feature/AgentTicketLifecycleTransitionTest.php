<?php

use App\Models\Account;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
