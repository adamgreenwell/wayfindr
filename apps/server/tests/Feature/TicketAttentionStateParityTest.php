<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the SQL attention state agrees with the PHP one, ticket by ticket', function (): void {
    // #847 needs the ticket queue to filter, order and cap in the database,
    // which means the attention state has to exist in SQL. That is a second
    // implementation of a rule that already exists in PHP, and two
    // implementations of one rule drift.
    //
    // This is the guard against that: every ticket on a seeded desk, both ways,
    // compared. It is written before the queue uses the SQL version, because
    // the risk here is not that the query is slow -- it is that it silently
    // disagrees and reorders a page agents have learned.
    $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 400,
        '--messages' => 4,
        '--fresh' => true,
    ])->assertSuccessful();

    // ESCALATIONS, which the seeder does not write. Without these the whole
    // escalated branch of the SQL is never evaluated and the comparison agrees
    // about a case it never reached -- the fixture producing zero of the state
    // a branch exists for is how a guard passes while covering nothing.
    //
    // One recent enough to count, one just outside the day, and one on a closed
    // ticket, because `latestRecentEscalationEvent()` returns null for a closed
    // ticket before it looks at the clock at all.
    $open = Ticket::query()->where('status', 'open')->take(2)->get();
    $closed = Ticket::query()->where('status', 'closed')->first();

    expect($open)->toHaveCount(2)->and($closed)->not->toBeNull();

    $escalate = function (Ticket $ticket, string $at): void {
        AuditEvent::factory()
            ->for($ticket->account_id ? Account::query()->find($ticket->account_id) : Account::factory()->create())
            ->for($ticket, 'subject')
            ->create(['action' => 'ticket.escalated', 'occurred_at' => $at, 'metadata' => []]);
    };

    $escalate($open[0], now()->subHours(2)->toDateTimeString());
    $escalate($open[1], now()->subDays(3)->toDateTimeString());
    $escalate($closed, now()->subHour()->toDateTimeString());

    $inSql = Ticket::query()
        ->selectAttentionState()
        ->select(['tickets.*'])
        ->selectAttentionState()
        ->get()
        ->mapWithKeys(fn (Ticket $ticket): array => [$ticket->id => $ticket->attention_state]);

    expect($inSql)->not->toBeEmpty();

    $tickets = Ticket::query()
        ->with(['conversation.latestMessage', 'latestEscalationEvent'])
        ->get();

    $disagreements = [];

    foreach ($tickets as $ticket) {
        $php = $ticket->hasRecentEscalation() ? 'escalated' : $ticket->attentionState();
        $sql = $inSql->get($ticket->id);

        if ($php !== $sql) {
            $disagreements[] = "ticket {$ticket->id} ({$ticket->status}): PHP said {$php}, SQL said {$sql}";
        }
    }

    expect($disagreements)->toBe([], implode('; ', array_slice($disagreements, 0, 5)));

    // And the fixture actually exercised more than one state, or agreement is
    // agreement about nothing.
    expect($inSql->values()->unique()->count())
        ->toBeGreaterThan(2, 'the desk produced too few attention states to compare meaningfully');

    // And the escalated branch was actually reached, in both directions: the
    // recent one counts, the three-day-old one does not, and the closed ticket
    // is resolved whatever its escalation says.
    expect($inSql->get($open[0]->id))->toBe('escalated');
    expect($inSql->get($open[1]->id))->not->toBe('escalated');
    expect($inSql->get($closed->id))->toBe('resolved');

    // And the ORDER the queue will use agrees with the PHP sort it replaces.
    // The rank is derived from the same alias, so this is really asking whether
    // the mapping and the tie-breaks match -- which is where a reordered queue
    // would come from, and agents have learned where things sit.
    $sqlOrder = Ticket::query()
        ->select(['tickets.*'])
        ->selectAttentionState()
        ->orderByAttention()
        ->pluck('id')
        ->all();

    $phpOrder = $tickets
        ->sortBy(fn (Ticket $ticket): array => [
            $ticket->hasRecentEscalation() ? 5 : $ticket->attentionSortRank(),
            -$ticket->updated_at->getTimestamp(),
            -$ticket->created_at->getTimestamp(),
        ])
        ->pluck('id')
        ->all();

    // Compared as RANK SEQUENCES rather than id sequences: tickets tied on all
    // three keys may come back in either order, and that is not a disagreement
    // about ordering.
    $rankOf = fn (array $ids): array => collect($ids)
        ->map(fn (int $id): string => (string) $inSql->get($id))
        ->all();

    expect($rankOf($sqlOrder))->toBe($rankOf($phpOrder),
        'the SQL ordering does not group the queue the way the PHP sort did');
});
