<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

    // Tickets whose `updated_at` and `created_at` orders are OPPOSITE, sharing
    // one attention state. The desk seeder sets the two almost in lockstep, so
    // without these, dropping the `updated_at` tie-break left the sequence
    // unchanged and the guard passed over a queue ordered by the wrong column.
    //
    // Unassigned and open with no messages, so all three are `needs_owner` and
    // the state cannot be what separates them.
    $site = Site::query()->firstOrFail();
    $account = Account::query()->findOrFail($site->account_id);

    // Every ticket built by hand below, so the guard at the end can ask what
    // the DESK produced without them.
    $handBuilt = [];

    foreach ([[3, 1], [2, 2], [1, 3]] as [$createdDaysAgo, $updatedHoursAgo]) {
        $handBuilt[] = Ticket::factory()->for($account)->for($site)->create([
            'status' => 'open',
            'assignee_id' => null,
            'conversation_id' => null,
            'created_at' => now()->subDays($createdDaysAgo),
            'updated_at' => now()->subHours($updatedHoursAgo),
        ])->id;
    }

    // A message whose SENDER IS NULL, which is not exotic: it is what the
    // message factory produces by default. The desk seeder always sets a
    // sender, so the fixture held 400 conversations and not one of these, and
    // the parity comparison agreed about a case it never reached.
    //
    // It separates two rules that look identical until you have one.
    // `attentionState()` asks whether a message EXISTS and then who sent it, so
    // this is `needs_reply`. SQL that reads the selected sender as its own
    // existence check sees null for both this and a ticket with no messages at
    // all, and answers `needs_agent`.
    //
    // Assigned and open, so the earlier branches cannot decide it first.
    $assignee = User::query()->firstOrFail();

    $ticketOnItsOwnConversation = fn (string $status, ?int $assigneeId): Ticket => Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'status' => $status,
            'assignee_id' => $assigneeId,
            'conversation_id' => Conversation::factory()->for($site)->create()->id,
        ]);

    $withNullSender = $ticketOnItsOwnConversation('open', $assignee->id);
    $handBuilt[] = $withNullSender->id;

    ConversationMessage::factory()->create([
        'conversation_id' => $withNullSender->conversation_id,
        'sender_type' => null,
        'sender_id' => null,
        'created_at' => now()->subMinute(),
    ]);

    expect($withNullSender->fresh()->attentionState())->toBe('needs_reply',
        'the fixture no longer produces the null-sender case this guard exists for');

    // Messages with a NULL `created_at`, which the column allows -- and which
    // separate `ofMany(max)` from an `order by ... desc limit 1`. A MAX skips
    // them; a descending sort puts them FIRST on PostgreSQL. Nothing in a
    // seeded desk is null-dated, so the fixture agreed about a case it never
    // built, on the one driver where the two disagree.
    $undate = fn (ConversationMessage $message) => DB::table('conversation_messages')
        ->where('id', $message->id)
        ->update(['created_at' => null]);

    // A dated visitor message and a null-dated agent one. The dated visitor
    // message is the latest, so this is `needs_reply`. Read the other way the
    // agent message wins and it becomes `waiting_on_customer` -- the wrong
    // lane, from the wrong message.
    $mixedDates = $ticketOnItsOwnConversation('open', $assignee->id);
    $handBuilt[] = $mixedDates->id;

    ConversationMessage::factory()->create([
        'conversation_id' => $mixedDates->conversation_id,
        'sender_type' => (new Visitor)->getMorphClass(),
        'sender_id' => Visitor::query()->firstOrFail()->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $undate(ConversationMessage::factory()->create([
        'conversation_id' => $mixedDates->conversation_id,
        'sender_type' => (new User)->getMorphClass(),
        'sender_id' => $assignee->id,
    ]));

    // Every message null-dated. `latestMessage` is null here, so the ticket has
    // no latest message at all and lands on `needs_agent` -- not `needs_reply`,
    // which is what an existence check that counts undated rows would say.
    $allUndated = $ticketOnItsOwnConversation('open', $assignee->id);
    $handBuilt[] = $allUndated->id;

    $undate(ConversationMessage::factory()->create([
        'conversation_id' => $allUndated->conversation_id,
        'sender_type' => (new Visitor)->getMorphClass(),
        'sender_id' => Visitor::query()->firstOrFail()->id,
    ]));

    // The PENDING branch, in every direction it can go. The desk seeds pending
    // tickets, but which side of the branch each lands on depends on who
    // happened to speak last, and a fixture can produce one side only and
    // agree about the other without ever reaching it. So all four, by hand:
    // pending with a visitor last (falls through to needs_reply), with an
    // integration last (also needs a human reply), with an agent last, with no
    // message at all, and with a senderless message -- the last two are the
    // `?->` in the PHP treating both nulls alike, which is the `coalesce` in
    // the SQL.
    $pendingAfterVisitor = $ticketOnItsOwnConversation('pending', $assignee->id);
    $pendingAfterIntegration = $ticketOnItsOwnConversation('pending', $assignee->id);
    $pendingAfterAgent = $ticketOnItsOwnConversation('pending', $assignee->id);
    $pendingSilent = $ticketOnItsOwnConversation('pending', $assignee->id);
    $pendingSenderless = $ticketOnItsOwnConversation('pending', $assignee->id);
    array_push($handBuilt, $pendingAfterVisitor->id, $pendingAfterIntegration->id, $pendingAfterAgent->id, $pendingSilent->id, $pendingSenderless->id);

    ConversationMessage::factory()->create([
        'conversation_id' => $pendingAfterVisitor->conversation_id,
        'sender_type' => (new Visitor)->getMorphClass(),
        'sender_id' => Visitor::query()->firstOrFail()->id,
        'created_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $pendingAfterIntegration->conversation_id,
        'sender_type' => ApiToken::class,
        'sender_id' => ApiToken::factory()->for($account)->create()->id,
        'created_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $pendingAfterAgent->conversation_id,
        'sender_type' => (new User)->getMorphClass(),
        'sender_id' => $assignee->id,
        'created_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::factory()->create([
        'conversation_id' => $pendingSenderless->conversation_id,
        'sender_type' => null,
        'sender_id' => null,
        'created_at' => now()->subMinutes(2),
    ]);

    expect($pendingAfterVisitor->fresh()->attentionState())->toBe('needs_reply', 'pending after a visitor should fall through')
        ->and($pendingAfterIntegration->fresh()->attentionState())->toBe('needs_reply', 'pending after an integration still needs a human reply')
        ->and($pendingAfterAgent->fresh()->attentionState())->toBe('waiting_on_customer')
        ->and($pendingSilent->fresh()->attentionState())->toBe('waiting_on_customer', 'pending with no message at all')
        ->and($pendingSenderless->fresh()->attentionState())->toBe('waiting_on_customer', 'pending with a senderless message');

    // An integration is the latest ACTIVITY, but the prior participant still
    // decides who owes the next human reply. Cover both directions so the SQL
    // expression cannot silently regress to the latest message again.
    $integrationAfterVisitor = $ticketOnItsOwnConversation('open', $assignee->id);
    $integrationAfterAgent = $ticketOnItsOwnConversation('open', $assignee->id);
    array_push($handBuilt, $integrationAfterVisitor->id, $integrationAfterAgent->id);

    foreach ([
        [$integrationAfterVisitor, Visitor::class, Visitor::query()->firstOrFail()->id],
        [$integrationAfterAgent, User::class, $assignee->id],
    ] as [$ticket, $senderType, $senderId]) {
        ConversationMessage::factory()->create([
            'conversation_id' => $ticket->conversation_id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'created_at' => now()->subMinutes(5),
        ]);
        ConversationMessage::factory()->create([
            'conversation_id' => $ticket->conversation_id,
            'sender_type' => ApiToken::class,
            'sender_id' => ApiToken::factory()->for($account)->create()->id,
            'created_at' => now()->subMinute(),
        ]);
    }

    expect($integrationAfterVisitor->fresh()->attentionState())->toBe('needs_reply')
        ->and($integrationAfterAgent->fresh()->attentionState())->toBe('waiting_on_customer');

    expect($mixedDates->fresh()->attentionState())->toBe('needs_reply',
        'the fixture no longer separates a MAX from a descending sort')
        ->and($allUndated->fresh()->attentionState())->toBe('needs_agent',
            'the fixture no longer covers a conversation whose messages are all undated');

    $inSql = Ticket::query()
        ->selectAttentionState()
        ->select(['tickets.*'])
        ->selectAttentionState()
        ->get()
        ->mapWithKeys(fn (Ticket $ticket): array => [$ticket->id => $ticket->attention_state]);

    expect($inSql)->not->toBeEmpty();

    $tickets = Ticket::query()
        ->with(['conversation.latestMessage', 'conversation.latestParticipantMessage', 'latestEscalationEvent'])
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

    // And the DESK actually exercised more than one state, or agreement is
    // agreement about nothing. Counted without the hand-built rows: those are
    // chosen to land in particular states, so they satisfy any such guard on
    // their own and would hide a seeder that had stopped producing variety.
    $deskStates = $inSql->except($handBuilt)->values()->unique();

    expect($deskStates->count())
        ->toBeGreaterThan(2, 'the desk produced too few attention states to compare meaningfully: '.$deskStates->implode(', '));

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

    // Compared as SORT-KEY sequences rather than id sequences: tickets tied on
    // every key may come back in either order, and that is not a disagreement.
    //
    // The whole key, not just the state. Comparing states alone tolerated a
    // reversed or missing `updated_at` tie-break entirely -- every lane would
    // hold the same tickets in the wrong order and the sequence of states would
    // be identical, so the guard would pass while the queue reshuffled under
    // agents who have learned where things sit.
    $byId = $tickets->keyBy('id');

    $keysOf = fn (array $ids): array => collect($ids)
        ->map(function (int $id) use ($byId, $inSql): string {
            $ticket = $byId->get($id);

            return implode('|', [
                (string) $inSql->get($id),
                $ticket->updated_at->toDateTimeString(),
                $ticket->created_at->toDateTimeString(),
            ]);
        })
        ->all();

    expect($keysOf($sqlOrder))->toBe($keysOf($phpOrder),
        'the SQL ordering does not order the queue the way the PHP sort did');

    // The `created_at` tie-break is NOT mutation-proven, and cannot be: it is
    // the last key, so removing it leaves tickets tied on everything before it
    // in an order the database may choose either way -- and the PHP sort would
    // be equally free. A test comparing the two would fail at random rather
    // than on the defect. The `updated_at` one above it is proven, in both
    // directions.
});
