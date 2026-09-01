<?php

declare(strict_types=1);

use App\Console\Commands\SeedDeskCommand;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRating;
use App\Models\ConversationReadState;
use App\Models\OperatorSetting;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\Reporting\TicketReport;
use App\Support\Visitors\VisitorPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * The measurement fixture has to be worth measuring.
 *
 * A seeder that writes fifty thousand identical rows produces timings that say
 * nothing: a `LIKE` across one repeated subject either matches everything or
 * nothing, a queue of exclusively closed conversations never exercises the lane
 * an agent actually opens, and a page whose rows all have the same shape hits
 * one branch of the view. So these assert the SPREAD, not just the count.
 *
 * Small numbers here on purpose. The command is verified at desk size by
 * running it; what a test can check is that the shape it produces is the shape
 * it claims.
 */
test('it writes a desk with the spread a measurement needs', function (): void {
    // 160 rather than 60. Tickets are one conversation in four, and fifteen
    // tickets do not cover six categories -- the mixing is deterministic, so
    // that is a fixed shortfall rather than an intermittent one, but a fixture
    // assertion that happens to be unsatisfiable is still the test's fault.
    $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 160,
        '--messages' => 4,
        '--agents' => 3,
        '--sites' => 2,
    ])->assertSuccessful();

    $account = Account::query()->where('slug', 'wayfindr-measurement-desk')->firstOrFail();

    expect(User::query()->where('account_id', $account->id)->count())->toBe(3)
        ->and(Site::query()->where('account_id', $account->id)->count())->toBe(2)
        ->and(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count())->toBe(160);

    // Both lanes populated. A queue with no open rows makes the default view --
    // the one an agent opens all day -- the cheapest query measured.
    $open = Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->where('status', 'open')->count();
    $closed = Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->where('status', 'closed')->count();

    expect($open)->toBeGreaterThan(0)
        ->and($closed)->toBeGreaterThan(0);

    // Assigned and unassigned both present WITHIN THE OPEN LANE, not merely
    // somewhere in the table. Asserting it across the whole set is what let a
    // fixture through where every open conversation was assigned: `$i % 6 === 0`
    // makes `$i` even, and the assignment rule was `$i % 2`. The unassigned-open
    // lane is the one an agent actually works from.
    $openScope = fn () => Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->where('status', 'open');

    // A PROPORTION, not a presence. `> 0` was satisfied by a fixture that
    // produced 8,309 assigned open conversations against 18 unassigned ones at
    // desk size -- technically both lanes, in practice one. A split this loose
    // still passes any reasonable mixing and fails any coupling.
    $openTotal = $openScope()->count();
    $openUnassigned = $openScope()->whereNull('assigned_agent_id')->count();

    expect($openTotal)->toBeGreaterThan(10, 'too few open rows to say anything about their split');

    expect($openUnassigned / $openTotal)
        ->toBeGreaterThan(0.2, 'almost every OPEN conversation is assigned; status and assignment are coupled')
        ->toBeLessThan(0.8, 'almost every OPEN conversation is unassigned; status and assignment are coupled');

    // Subjects vary, or a search measures a full-table match rather than a
    // search. Twelve openings against sixty rows.
    $distinctSubjects = Conversation::query()
        ->where('support_code', 'like', 'WF-DESK-%')
        ->distinct()
        ->count('subject');

    expect($distinctSubjects)->toBe(160);

    // Every presence state. `last_seen_at` alone leaves each visitor
    // `not_reported`, so the queue rendered no active or recent marker and its
    // presence filter had one value to choose between -- a filter measured
    // against a column with no variety measures nothing.
    $states = Visitor::query()
        ->get()
        ->map(fn (Visitor $visitor): string => VisitorPresence::stateFor($visitor->last_web_seen_at))
        ->unique();

    expect($states)->toHaveCount(4, 'the seeded visitors do not reach every presence state');

    // And a visitor seen on the web has a visit to measure. `Visitor::booted()`
    // starts one on the first sighting and a bulk insert bypasses it, so the
    // live board's "on site for" column had nothing to work from.
    expect(Visitor::query()->whereNotNull('last_web_seen_at')->whereNull('current_visit_started_at')->count())
        ->toBe(0, 'a visitor is present on the site with no visit start');

    // Varied, or the column is one repeated duration.
    expect(Visitor::query()->whereNotNull('current_visit_started_at')->distinct()->count('current_visit_started_at'))
        ->toBeGreaterThan(1, 'every present visitor arrived at the same moment');

    // And nothing about a visitor precedes the visitor. The web sighting and
    // the visit start can both be more recent than the historical point the
    // row was placed at, so the creation follows the earliest of them.
    expect(Visitor::query()->whereColumn('current_visit_started_at', '<', 'created_at')->count())
        ->toBe(0, 'a visit started before the visitor existed');

    expect(Visitor::query()->whereColumn('last_web_seen_at', '<', 'created_at')->count())
        ->toBe(0, 'a visitor was seen on the web before they existed');

    // Nor after their own conversation opened. The conversations shift back to
    // make room for their messages and the visitors did not, so a conversation
    // could open before the visitor who started it existed.
    $late = Conversation::query()
        ->join('visitors', 'visitors.id', '=', 'conversations.visitor_id')
        ->whereColumn('visitors.created_at', '>', 'conversations.created_at')
        ->count();

    expect($late)->toBe(0, 'a conversation opened before its visitor existed');

    // Read states exist, and in both shapes. Without them every conversation
    // reads as new activity -- `scopeWithNewActivityFor()` treats a missing row
    // as unread -- so the queue's marker was on for every row and its absence
    // never rendered.
    $readStates = Conversation::query()
        ->where('support_code', 'like', 'WF-DESK-%')
        ->withCount('readStates')
        ->get();

    expect($readStates->where('read_states_count', '>', 0)->count())
        ->toBeGreaterThan(0, 'no conversation has been read by anyone')
        ->and($readStates->where('read_states_count', 0)->count())
        ->toBeGreaterThan(0, 'every conversation has been read, so the never-opened state never renders');

    // Unseen messages from BOTH sides. `seen_at` describes whether a message
    // has been seen, and the two senders exercise different surfaces: an unseen
    // VISITOR message drives the queue's attention lanes, an unseen AGENT reply
    // is the read-receipt branch on the detail page. Requiring the sender to be
    // the visitor left every agent reply stamped, so that branch never rendered
    // in any measurement.
    $unseen = ConversationMessage::query()
        ->whereNull('seen_at')
        ->get()
        ->groupBy('sender_type');

    expect($unseen->keys())->toHaveCount(2, 'only one side ever leaves an unseen message');

    // Message counts are NOT uniform, or the detail page's cost is a constant
    // and the long conversations -- the ones worth knowing about -- never
    // render.
    $perConversation = Conversation::query()
        ->where('support_code', 'like', 'WF-DESK-%')
        ->withCount('messages')
        ->pluck('messages_count')
        ->unique();

    expect($perConversation->count())->toBeGreaterThan(1);

    // Tickets across every status, priority and category the queue filters on.
    expect(Ticket::query()->where('account_id', $account->id)->distinct()->count('status'))->toBe(3)
        ->and(Ticket::query()->where('account_id', $account->id)->distinct()->count('priority'))->toBe(4)
        ->and(Ticket::query()->where('account_id', $account->id)->distinct()->count('category'))->toBe(6);

    // And no attribute is a FUNCTION of another. Every value appearing somewhere
    // is not enough: status and assignment both cycled on `% 3` once, which made
    // every open ticket unassigned and every closed one assigned, and each
    // category permanently tied to one status. The counts above were all
    // satisfied by that.
    $openTickets = Ticket::query()->where('account_id', $account->id)->where('status', 'open');

    $openTicketTotal = (clone $openTickets)->count();
    $openTicketsAssigned = (clone $openTickets)->whereNotNull('assignee_id')->count();

    expect($openTicketTotal)->toBeGreaterThan(5, 'too few open tickets to say anything about their split');

    expect($openTicketsAssigned / $openTicketTotal)
        ->toBeGreaterThan(0.2, 'almost no OPEN ticket is assigned; status and assignment are coupled')
        ->toBeLessThan(0.95, 'every OPEN ticket is assigned; status and assignment are coupled');

    // A ticket's status is not decided by its conversation's. They shared a
    // salt, so `mix($n, 'status', 6)` chose the conversation and the same hash
    // modulo three chose the ticket -- and a ticket on an open conversation was
    // never open itself.
    $onOpen = Ticket::query()
        ->whereIn('conversation_id', Conversation::query()->where('status', 'open')->select('id'))
        ->distinct()
        ->count('status');

    expect($onOpen)->toBeGreaterThan(1, 'every ticket on an open conversation shares one status');

    // More than one category inside a single status, or category is decided by
    // status rather than varying beside it.
    expect((clone $openTickets)->distinct()->count('category'))
        ->toBeGreaterThan(1, 'every open ticket shares one category; category is tied to status');

    // Spread across the window, or a ninety-day report has one bucket.
    $span = Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->max('created_at');
    $earliest = Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->min('created_at');

    expect(Carbon::parse($earliest)->diffInDays(Carbon::parse($span)))
        ->toBeGreaterThan(300);
});

test('fresh removes its own desk and nothing else', function (): void {
    // The seeder owns one account. A `--fresh` that truncated tables would take
    // a real desk with it, which is the failure worth guarding on a command an
    // operator can run.
    $bystander = Account::query()->create(['slug' => 'a-real-account', 'name' => 'Real']);
    $bystanderSite = Site::factory()->for($bystander)->create();
    $bystanderVisitor = Visitor::factory()->for($bystanderSite)->create();
    Conversation::factory()->for($bystanderSite)->for($bystanderVisitor)->create(['support_code' => 'WF-REAL-1']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 2])->assertSuccessful();
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 2, '--fresh' => true])->assertSuccessful();

    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count())->toBe(10)
        ->and(Account::query()->where('slug', 'a-real-account')->exists())->toBeTrue()
        ->and(Conversation::query()->where('support_code', 'WF-REAL-1')->exists())->toBeTrue();
});

test('fresh takes the agents it created with it', function (): void {
    // `users.account_id` is `nullOnDelete()`, so deleting the account DETACHES
    // its users rather than removing them. Reseeding with fewer agents then
    // left sign-in-capable accounts behind, holding this command's known
    // password and belonging to no account at all -- a worse thing to leave on
    // a machine than the rows `--fresh` was asked to clear.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 6])
        ->assertSuccessful();

    expect(User::query()->where('email', 'like', 'desk-agent-%@example.test')->count())->toBe(6);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 1, '--fresh' => true])
        ->assertSuccessful();

    expect(User::query()->where('email', 'like', 'desk-agent-%@example.test')->count())
        ->toBe(1, 'agents from the previous desk are still able to sign in');

    // And none of them is orphaned, which is the shape the leftovers took.
    expect(User::query()->where('email', 'like', 'desk-agent-%@example.test')->whereNull('account_id')->count())
        ->toBe(0, 'a seeded agent is left with no account and a known password');
});

test('it leaves another account\'s conversation alone even when its code matches', function (): void {
    // The support-code prefix is a naming CONVENTION, not a boundary. A
    // conversation on somebody else's account carrying `WF-DESK-` -- a legacy
    // row, a hand-made one -- was picked up by the passes that read
    // conversations back, so it had synthetic messages attached to it,
    // attributed to measurement-desk agents, and a ticket raised ON the
    // measurement account pointing at it.
    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);
    $otherSite = Site::factory()->for($other)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    // A code that matches the prefix and cannot collide with a generated one.
    $theirs = Conversation::factory()->for($otherSite)->for($otherVisitor)
        ->create(['support_code' => 'WF-DESK-LEGACY-1']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 12, '--messages' => 3])
        ->assertSuccessful();

    expect($theirs->messages()->count())
        ->toBe(0, 'another account\'s conversation was given synthetic messages');

    expect(Ticket::query()->where('conversation_id', $theirs->id)->count())
        ->toBe(0, 'a ticket was raised on the measurement account for another account\'s conversation');

    // And the desk itself is intact, or this passes by seeding nothing.
    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-0%')->count())
        ->toBeGreaterThan(0, 'the desk was not seeded, so this proves nothing');
});

test('fresh refuses an account at its slug that it did not create', function (): void {
    // A slug is user-selectable -- `wayfindr:bootstrap` takes an arbitrary one
    // -- so an account carrying this one is not evidence the command made it,
    // and `--fresh` cascades through every site, visitor, conversation and
    // ticket underneath. Deleting somebody's real desk because it chose the
    // same name is the worst thing this command could do.
    $real = Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'A Real Desk']);
    $realSite = Site::factory()->for($real)->create(['name' => 'Production']);
    $realVisitor = Visitor::factory()->for($realSite)->create();
    $realConversation = Conversation::factory()->for($realSite)->for($realVisitor)->create();

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--fresh' => true])
        ->assertFailed();

    expect(Account::query()->whereKey($real->id)->exists())->toBeTrue('a real account was deleted')
        ->and(Site::query()->whereKey($realSite->id)->exists())->toBeTrue('a real site was deleted')
        ->and(Conversation::query()->whereKey($realConversation->id)->exists())->toBeTrue('a real conversation was deleted');
});

test('it refuses a real account at its slug even without --fresh', function (): void {
    // Without `--fresh` the command does not delete anything -- it REUSES the
    // account at its slug, renames it, and adds `desk-agent-0` to it as OWNER
    // with the password it prints on success. That is an account takeover, and
    // confining the provenance check to the delete path left it wide open.
    $real = Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'A Real Desk']);
    Site::factory()->for($real)->create(['name' => 'Production']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1])
        ->assertFailed();

    expect(Account::query()->whereKey($real->id)->value('name'))
        ->toBe('A Real Desk', 'a real account was renamed');

    expect(User::query()->where('account_id', $real->id)->count())
        ->toBe(0, 'an agent with a published password was added to a real account');
});

test('a site key that only looks like ours does not count as provenance', function (): void {
    // `_` is a single-character WILDCARD in SQL, so `like 'site_desk_%'` also
    // matches `site-desk-legacy`. The pattern meant to prove an account is ours
    // would have said yes to somebody else's site and let `--fresh` delete
    // their desk.
    $real = Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'A Real Desk']);
    Site::factory()->for($real)->create(['name' => 'Legacy', 'public_key' => 'site-desk-legacy']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--fresh' => true])
        ->assertFailed();

    expect(Account::query()->whereKey($real->id)->exists())
        ->toBeTrue('a real account was deleted because its site key matched a LIKE wildcard');
});

test('running twice without --fresh refuses before it changes anything', function (): void {
    // The second run reuses the existing sites and re-inserts the same
    // `desk-visitor-` identifiers, which the `(site_id, anonymous_id)` unique
    // index refuses -- but only AFTER `desk()` has rehashed the agents'
    // passwords and replaced the sites' public keys. A forgotten flag both
    // failed and half-rewrote the fixture the operator already had.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 2])
        ->assertSuccessful();

    $keys = Site::query()
        ->whereIn('id', Site::query()->pluck('id'))
        ->orderBy('id')
        ->pluck('public_key')
        ->all();

    $passwords = User::query()->orderBy('id')->pluck('password')->all();

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 2])
        ->assertFailed();

    expect(Site::query()->orderBy('id')->pluck('public_key')->all())
        ->toBe($keys, 'the failed run replaced the existing sites\' public keys');

    expect(User::query()->orderBy('id')->pluck('password')->all())
        ->toBe($passwords, 'the failed run rehashed the existing agents\' passwords');
});

test('an address that only looks seeded does not count as provenance', function (): void {
    // The command creates `desk-agent-<integer>@example.test` and nothing else,
    // so checking the affixes alone accepted `desk-agent-owner@example.test` --
    // a real person on an account at the reserved slug, read as one of ours and
    // deleted with it.
    $real = Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'A Real Desk']);
    $person = User::factory()->for($real)->create(['email' => 'desk-agent-owner@example.test']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--fresh' => true])
        ->assertFailed();

    expect(User::query()->whereKey($person->id)->exists())
        ->toBeTrue('a real user was deleted because their address resembled a seeded one');
});

test('--messages is the average it says it is', function (): void {
    // The spread is narrowed rather than clamped, so a low request keeps its
    // average. `max(1, $n + $i % 5 - 2)` gave `--messages=1` the counts
    // 1,1,1,2,3 -- an average of 1.6 against an advertised 1, in a fixture
    // whose size is reported alongside the timings taken from it.
    foreach ([1, 2, 6] as $requested) {
        $this->artisan('wayfindr:seed-desk', [
            '--conversations' => 60,
            '--messages' => $requested,
            '--fresh' => true,
        ])->assertSuccessful();

        $conversations = Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count();
        $messages = ConversationMessage::query()->count();

        expect($messages / $conversations)
            ->toBeGreaterThan($requested - 0.2, "--messages={$requested} averaged below what was asked for")
            ->toBeLessThan($requested + 0.2, "--messages={$requested} averaged above what was asked for");
    }
});

test('--messages is exact at every conversation count', function (): void {
    // No fixed cycle of deltas sums to zero at every length, so ordering them
    // to keep prefixes near zero still left `--conversations=2 --messages=6` at
    // 6.5. The running deviation is carried and the last conversation cancels
    // it, which is exact rather than merely close.
    foreach ([1, 2, 3, 4, 5, 7, 20] as $count) {
        $this->artisan('wayfindr:seed-desk', [
            '--conversations' => $count,
            '--messages' => 6,
            '--fresh' => true,
        ])->assertSuccessful();

        expect(ConversationMessage::query()->count())
            ->toBe($count * 6, "--conversations={$count} did not write exactly six messages each on average");
    }
});

test('nothing is closed or read in the future', function (): void {
    // The newest conversation opens minutes before seeding, so a fixed offset
    // put its closure hours ahead of now -- and the closed queue reported a
    // resolution that has not happened. Same clamp on both tables.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 40, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    expect(Conversation::query()->where('closed_at', '>', now())->count())
        ->toBe(0, 'a conversation is closed at a time that has not arrived');

    expect(ConversationReadState::query()->where('last_read_at', '>', now())->count())
        ->toBe(0, 'an agent has read a conversation in the future');

    // Nor before it existed. An hour before the last message is an hour before
    // the conversation opened on anything short, and with `--messages=0` it is
    // exactly an hour before it was created.
    $early = ConversationReadState::query()
        ->join('conversations', 'conversations.id', '=', 'conversation_read_states.conversation_id')
        ->whereColumn('conversation_read_states.last_read_at', '<', 'conversations.created_at')
        ->count();

    expect($early)->toBe(0, 'an agent has read a conversation before it existed');

    // And some ARE closed, or this passes on a fixture with no closures.
    expect(Conversation::query()->whereNotNull('closed_at')->count())->toBeGreaterThan(0);
});

test('read positions follow the conversation when there are no messages', function (): void {
    // `Carbon::parse(null)` is NOW, so with `--messages=0` the read positions
    // were anchored to the moment of seeding rather than to the conversation's
    // own activity boundary -- every one of them within a second of each other,
    // months away from the conversation they belong to.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 0, '--fresh' => true])
        ->assertSuccessful();

    $states = ConversationReadState::query()->with('conversation')->get();

    expect($states)->not->toBeEmpty();

    foreach ($states as $state) {
        expect(abs($state->last_read_at->diffInHours($state->conversation->created_at)))
            ->toBeLessThan(24, 'a read position is anchored to seeding time rather than to its conversation');
    }
});

test('the same options produce the same fixture twice', function (): void {
    // Hashing the database ID looked deterministic and is not: auto-increment
    // does not restart after `--fresh`, and a desk created beside existing
    // conversations starts higher again -- so every reseed produced different
    // conversations unread, different agents holding read states, different
    // ticket priorities. A measurement fixture that changes between runs makes
    // two sets of numbers incomparable, which is the whole point of having one.
    // Identified by ADDRESS, not by id. Agents are recreated on each `--fresh`
    // and take new surrogate ids, which is expected -- capturing those would
    // make this test fail on a fixture that is perfectly reproducible.
    $shape = function (): string {
        $agents = User::query()->pluck('email', 'id');

        return Conversation::query()
            ->where('support_code', 'like', 'WF-DESK-%')
            ->orderBy('support_code')
            ->get()
            ->map(fn (Conversation $conversation): string => implode(':', [
                $conversation->support_code,
                $conversation->status,
                (string) $conversation->messages()->count(),
                // The UNSEEN count too. Without it the shape ignored the one
                // thing the id-based hash decided, so the mutation that put it
                // back passed on both drivers.
                (string) $conversation->messages()->whereNull('seen_at')->count(),
                (string) $conversation->readStates()->count(),
                $conversation->readStates()->orderBy('user_id')->pluck('user_id')
                    ->map(fn (int $id): string => (string) $agents[$id])->sort()->implode(','),
                (string) Ticket::query()->where('conversation_id', $conversation->id)->value('priority'),
                (string) Ticket::query()->where('conversation_id', $conversation->id)->value('status'),
            ]))
            ->implode('|');
    };

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 24, '--messages' => 4, '--fresh' => true])
        ->assertSuccessful();

    $first = $shape();

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 24, '--messages' => 4, '--fresh' => true])
        ->assertSuccessful();

    expect($shape())->toBe($first, 'reseeding the same options produced a different fixture');
});

test('a conversation does not close before its last message', function (): void {
    // The closure was a fixed four hours after opening while messages are a
    // minute apart -- so a high `--messages`, exactly what somebody measuring a
    // long conversation detail reaches for, put most of them after it.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 6, '--messages' => 400, '--fresh' => true])
        ->assertSuccessful();

    $closed = Conversation::query()->whereNotNull('closed_at')->get();

    expect($closed)->not->toBeEmpty('nothing is closed, so this proves nothing');

    foreach ($closed as $conversation) {
        $last = $conversation->messages()->max('created_at');

        expect($conversation->closed_at->greaterThanOrEqualTo(Carbon::parse($last)))
            ->toBeTrue('a conversation closed before its own last message');
    }
});

test('a ticket is not closed in the future', function (): void {
    // A conversation raised minutes ago was getting `closed_at` two days out,
    // so the ticket queue reported a resolution that has not happened.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 40, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    expect(Ticket::query()->where('closed_at', '>', now())->count())
        ->toBe(0, 'a ticket is closed at a time that has not arrived');

    // And some ARE closed, or this passes on a fixture with no closures.
    expect(Ticket::query()->whereNotNull('closed_at')->count())->toBeGreaterThan(0);
});

test('a closed ticket was touched when it was closed', function (): void {
    // The ticket queue orders by `updated_at`, and a real closure goes through
    // an Eloquent `update()` that advances it. Leaving it at the raise time
    // filed every closure under the day the ticket was opened, so the measured
    // queue ordered recently closed work as if it were stale.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 40, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    $closed = Ticket::query()->whereNotNull('closed_at')->get();
    expect($closed)->not->toBeEmpty();

    foreach ($closed as $ticket) {
        expect($ticket->updated_at->greaterThanOrEqualTo($ticket->closed_at))
            ->toBeTrue('a closed ticket was last touched before it was closed');
    }

    // And an OPEN ticket still sits at its raise time, so the fix did not just
    // push every row's `updated_at` forward and flatten the ordering.
    $open = Ticket::query()->whereNull('closed_at')->get();
    expect($open)->not->toBeEmpty();

    foreach ($open as $ticket) {
        expect($ticket->updated_at->equalTo($ticket->created_at))
            ->toBeTrue('an open ticket reports activity it never had');
    }
});

test('a reseed writes the same words, not just the same shapes', function (): void {
    // The baseline calls response sizes exact, and the queue renders a message
    // body for every row -- so any drift in that text moves the figure by
    // kilobytes. Bodies carried the conversation's DATABASE ID, and deleting the
    // desk does not reset a PostgreSQL sequence: the same conversation came back
    // with a different id, and a wider one as the install aged.
    //
    // Asserted on the CONTENT rather than on rendered bytes, because a byte
    // comparison at a small fixture size hides this: a one-character shift
    // across two dozen rows disappears into any tolerance loose enough to allow
    // for the ids the application puts in its own URLs.
    $seed = fn () => $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 12,
        '--messages' => 2,
        '--fresh' => true,
    ])->assertSuccessful();

    $bodies = fn (): array => ConversationMessage::query()->orderBy('body')->pluck('body')->all();
    $descriptions = fn (): array => Ticket::query()->orderBy('description')->pluck('description')->all();

    $seed();
    $firstBodies = $bodies();
    $firstDescriptions = $descriptions();

    $seed();

    // The sequence really did advance, or a reseed onto identical ids would
    // agree for the wrong reason and this would pass on the broken code.
    expect(ConversationMessage::query()->min('id'))
        ->toBeGreaterThan(count($firstBodies), 'the ids did not advance, so a reseed cannot show this');

    expect($bodies())->toBe($firstBodies, 'a reseed wrote different message bodies for the same fixture');
    expect($descriptions())->toBe($firstDescriptions, 'a reseed wrote different ticket descriptions');
});

test('a reseed writes the same lifecycle history', function (): void {
    // The report figures are computed from these events, so a fixture whose
    // history changes shape between rebuilds cannot be compared against its own
    // earlier measurement -- which is the entire point of a baseline.
    //
    // The ticket half was keyed on `tickets.id`, and `--fresh` does not reset a
    // PostgreSQL sequence: the same ticket came back with a different id, so a
    // different set of tickets got reopen episodes and different agents acted
    // on them. Keyed on the conversation's support code now, like everything
    // else here.
    // The clock is FROZEN across both seeds. Every timestamp here is derived
    // from `now()`, so two runs seconds apart legitimately differ -- and this
    // test is about whether the ids churn, not about how long it takes to seed
    // twice. Without the freeze it passed on SQLite, where both seeds landed in
    // the same second, and failed on PostgreSQL, where they did not.
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $seed = fn () => $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 40,
        '--messages' => 2,
        '--fresh' => true,
    ])->assertSuccessful();

    // By SUPPORT CODE, never by id -- comparing ids would fail on correct data
    // for the very reason this test exists.
    //
    // BOTH subject types. Written for conversations alone it passed with the
    // ticket half still keyed on `tickets.id`, which is the only place the bug
    // was: an assertion that looks everywhere except where the defect lives is
    // not a guard.
    $codeFor = function (AuditEvent $event): string {
        if ($event->subject_type === (new Ticket)->getMorphClass()) {
            return (string) Ticket::query()->find($event->subject_id)?->conversation?->support_code;
        }

        return (string) Conversation::query()->find($event->subject_id)?->support_code;
    };

    $shape = fn (): array => AuditEvent::query()
        ->orderBy('subject_id')
        ->orderBy('occurred_at')
        ->get()
        ->map(fn (AuditEvent $e): string => $codeFor($e)
            .'|'.$e->action.'|'.$e->occurred_at->toIso8601String()
            .'|'.($e->metadata['actor'] ?? '')
            .'|actor:'.($e->actor_id === null ? 'none' : 'set'))
        ->sort()
        ->values()
        ->all();

    $seed();
    $first = $shape();

    expect($first)->not->toBeEmpty();

    $seed();

    expect(AuditEvent::query()->min('id'))
        ->toBeGreaterThan(count($first), 'the ids did not advance, so a reseed cannot show this');

    expect($shape())->toBe($first, 'a reseed wrote a different lifecycle history');

    Carbon::setTestNow();
});

test('a reseed writes the same satisfaction answers', function (): void {
    // The third place a surrogate id was mistaken for a stable key. Ratings
    // decided which closes were answered, what score they carried and how long
    // the visitor took, all from `conversations.id` -- which moves on every
    // `--fresh` against a sequence. The satisfaction figures moved with it.
    //
    // Its own test because the lifecycle one could not see this: it compares
    // `audit_events`, and ratings live in another table. An assertion that
    // stops at the table it was written for is how the same mistake reached
    // three passes.
    Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

    $seed = fn () => $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 60,
        '--messages' => 2,
        '--fresh' => true,
    ])->assertSuccessful();

    $shape = fn (): array => ConversationRating::query()
        ->get()
        ->map(fn (ConversationRating $r): string => (string) Conversation::query()->find($r->conversation_id)?->support_code
            .'|'.$r->score
            .'|'.$r->rated_at->toIso8601String())
        ->sort()
        ->values()
        ->all();

    $seed();
    $first = $shape();

    expect($first)->not->toBeEmpty();

    $seed();

    expect(ConversationRating::query()->min('id'))
        ->toBeGreaterThan(count($first), 'the ids did not advance, so a reseed cannot show this');

    expect($shape())->toBe($first, 'a reseed answered a different set of closes');

    Carbon::setTestNow();
});

test('a visitor was seen no earlier than the last thing they said', function (): void {
    // `last_seen_at` means the latest contact by ANY channel, and the visitor
    // directory orders by it. Visitors are written before their conversations
    // exist, so the value was fixed before the messages that follow it -- the
    // directory showed people as last seen minutes before a message they went
    // on to send.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 40, '--messages' => 4, '--fresh' => true])
        ->assertSuccessful();

    $newest = ConversationMessage::query()
        ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
        ->where('conversation_messages.sender_type', (new Visitor)->getMorphClass())
        ->groupBy('conversations.visitor_id')
        ->select('conversations.visitor_id')
        ->selectRaw('max(conversation_messages.created_at) as newest')
        ->get()
        ->mapWithKeys(fn ($row): array => [(int) $row->visitor_id => (string) $row->newest]);

    expect($newest)->not->toBeEmpty('no visitor-authored messages, so this asserts nothing');

    foreach ($newest as $visitorId => $at) {
        $visitor = Visitor::query()->findOrFail($visitorId);

        expect($visitor->last_seen_at->greaterThanOrEqualTo(Carbon::parse($at)))
            ->toBeTrue('a visitor was last seen before a message they sent');
    }

    // And the WEB sighting is untouched. It means a website sighting, not a
    // message, and the live board's presence lanes are computed from it --
    // moving it would report visitors as on-site because they once wrote in.
    // All four presence states must still be represented.
    $webStates = Visitor::query()->get()->map(fn (Visitor $v): string => match (true) {
        $v->last_web_seen_at === null => 'absent',
        $v->last_web_seen_at->greaterThan(now()->subMinutes(2)) => 'active',
        $v->last_web_seen_at->greaterThan(now()->subMinutes(15)) => 'recent',
        default => 'quiet',
    })->unique()->values()->all();

    expect(count($webStates))
        ->toBe(4, 'the web presence spread collapsed: '.implode(', ', $webStates));
});

test('the reports have something to report', function (): void {
    // The reason this history exists. Conversations and tickets are inserted at
    // their final status rather than driven through the application, so none of
    // the events a real close leaves behind existed -- and the report tabs are
    // computed from exactly those. Measuring them against the old fixture would
    // have timed a query over an empty table and called the page fast, which is
    // worse than not measuring it at all.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 200, '--messages' => 3, '--fresh' => true])
        ->assertSuccessful();

    $agent = User::query()->where('email', 'desk-agent-0@example.test')->firstOrFail();
    $scope = ReportingScope::for($agent->account, $agent);
    $window = ReportingWindow::ofDays(90);

    $support = new SupportReport($scope, $window);

    $resolution = $support->resolution();
    $satisfaction = $support->satisfaction();

    // Both branches of the reopen split. A fixture where every reopen is an
    // agent leaves `reopened_by_visitor` reading zero on a page built to show
    // it, and zero is indistinguishable from broken.
    expect($resolution['reopened'])->toBeGreaterThan(0)
        ->and($resolution['reopened_by_visitor'])->toBeGreaterThan(0)
        ->and($resolution['reopened_by_visitor'])->toBeLessThan($resolution['reopened']);

    // All three scores, and answers that are a SUBSET of closes -- the ratio is
    // one of the figures the tab exists for, and it means nothing at 100%.
    expect($satisfaction['good'])->toBeGreaterThan(0)
        ->and($satisfaction['ok'])->toBeGreaterThan(0)
        ->and($satisfaction['bad'])->toBeGreaterThan(0)
        ->and($satisfaction['closed'])->toBeGreaterThan(0)
        ->and($satisfaction['answered'])->toBeGreaterThan(0)
        ->and($satisfaction['answered'])->toBeLessThan($satisfaction['closed']);

    // A reopened conversation contributes TWO resolutions, not one long one.
    // This is what the raw counters cannot see: `ResolutionEpisodes::walk()`
    // starts every conversation in OPEN and ignores a reopen from OPEN, so
    // reopens with no earlier close inflated `reopened` above while producing
    // no second episode at all -- and the assertion on that counter passed
    // anyway. Episodes must outnumber the conversations that produced them.
    // Counted in the SAME window the report used, or this compares a 90-day
    // figure against twelve months of history and fails on correct data.
    $closedConversations = AuditEvent::query()
        ->where('action', 'conversation.closed')
        ->whereBetween('occurred_at', [$window->start, $window->end])
        ->distinct()
        ->count('subject_id');

    expect($resolution['summary']->count)
        ->toBeGreaterThan($closedConversations,
            'no conversation resolved twice, so the reopens started no episode');

    // The ticket half walks its OWN actions, so seeding the conversation half
    // gives it nothing.
    $ticketReport = new TicketReport($scope, $window);
    $tickets = $ticketReport->resolution();

    expect($tickets['summary']->count)->toBeGreaterThan(0, 'the ticket report resolved nothing');

    // The agent activity table counts replies from `ticket.reply_sent` alone,
    // which nothing else in the fixture writes. Without them every agent read
    // zero and the aggregation was measured against an empty result at every
    // desk size -- the same shape as ratings that carried no comment.
    $activity = collect($ticketReport->agentActivity());

    expect($activity)->not->toBeEmpty('no agent appears in the ticket activity table');

    expect($activity->sum(fn (array $row): int => (int) ($row['replies'] ?? 0)))
        ->toBeGreaterThan(0, 'every agent replied to no tickets, so the replies column measures nothing');

    // More than one agent is credited, or the column is a single row wearing a
    // table's clothes.
    expect($activity->filter(fn (array $row): bool => (int) ($row['replies'] ?? 0) > 0))
        ->toHaveCount($activity->count(), 'some agents replied to nothing');
});

test('a rating answers a close that actually happened', function (): void {
    // `conversation_ratings.episode_event_id` points at the audit event that
    // closed the episode, and the report counts answers by `episode_closed_at`
    // rather than by when the answer arrived -- so the two have to agree or the
    // page reports things like "1 of 0 closes answered".
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 60, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    $ratings = ConversationRating::query()->get();

    expect($ratings)->not->toBeEmpty();

    foreach ($ratings as $rating) {
        $event = AuditEvent::query()->find($rating->episode_event_id);

        expect($event)->not->toBeNull('a rating answers a close that does not exist')
            ->and($event->action)->toBe('conversation.closed')
            ->and((int) $event->subject_id)->toBe((int) $rating->conversation_id)
            // `episode_closed_at` has no datetime cast on the model, so it
            // arrives as a string and has to be parsed rather than compared.
            ->and(Carbon::parse($rating->episode_closed_at)->equalTo($event->occurred_at))
            ->toBeTrue('the rating and the close it answers disagree about when it happened')
            ->and($rating->rated_at->greaterThanOrEqualTo(Carbon::parse($rating->episode_closed_at)))
            ->toBeTrue('a visitor answered before the conversation closed');
    }
});

test('a lifecycle event never happens before or after it could have', function (): void {
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 80, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    $events = AuditEvent::query()->whereIn('action', [
        'conversation.closed', 'conversation.reopened',
        'ticket.closed', 'ticket.reopened', 'ticket.pending',
    ])->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->occurred_at->lessThanOrEqualTo(now()))
            ->toBeTrue("a {$event->action} is recorded in the future");
    }

    // Every reopen sits BETWEEN two closes. The one before it is what makes it
    // a reopen at all -- the walk ignores a reopen from OPEN -- and the one
    // after it is what closes the episode it began. Asserted as both, because
    // checking only "a close exists" passed on a fixture where the reopen came
    // first and no episode was ever started.
    foreach ($events->where('action', 'conversation.reopened') as $reopen) {
        $closes = $events->filter(fn (AuditEvent $e): bool => $e->action === 'conversation.closed'
            && (int) $e->subject_id === (int) $reopen->subject_id);

        expect($closes->filter(fn (AuditEvent $e): bool => $e->occurred_at->lessThanOrEqualTo($reopen->occurred_at)))
            ->not->toBeEmpty('a conversation was reopened without having been closed first');

        expect($closes->filter(fn (AuditEvent $e): bool => $e->occurred_at->greaterThanOrEqualTo($reopen->occurred_at)))
            ->not->toBeEmpty('a conversation was reopened and never closed again');
    }
});

test('it says when this install will report the desk as partial', function (): void {
    // Both recording boundaries are INSTALLATION-WIDE and belong to every
    // account, so this command will not move them: doing so would tell real
    // accounts' reports to trust unaudited history. On a clean measurement
    // install they are absent, which means "always trustworthy".
    //
    // On an upgraded install they are set and recent, and a desk backdated
    // twelve months mostly predates them -- the report marks itself partial and
    // reports resolution durations as unmeasurable. That is the report being
    // honest, not broken, but it is not what somebody measuring report
    // performance expects, so it is said out loud rather than left to be
    // discovered in a figure.
    OperatorSetting::query()->create([
        'key' => 'reporting.ticket_lifecycle_recording_began_at',
        'value' => now()->subDays(3)->toIso8601String(),
    ]);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1, '--fresh' => true])
        ->expectsOutputToContain('records lifecycle history only from')
        ->assertSuccessful();

    // And the boundary is UNTOUCHED. The warning exists because moving it is
    // the thing this command must not do.
    expect(OperatorSetting::query()
        ->where('key', 'reporting.ticket_lifecycle_recording_began_at')
        ->value('value'))
        ->not->toBeNull('the seeder moved an installation-wide reporting boundary');
});

test('it says nothing about a boundary no report can reach', function (): void {
    // The false positive this warning invited. A boundary six months back sits
    // inside the twelve months the desk covers, so comparing against the seeded
    // span warned about it -- but `historyIsPartial()` measures the boundary
    // against the SELECTED window, and the choices stop at 90 days. Every
    // available report is complete in that case, and the warning was wrong.
    //
    // It gets truer as installs age, which is the worst shape for a warning:
    // eventually it fires on every run and teaches people to skip the output.
    OperatorSetting::query()->create([
        'key' => 'reporting.ticket_lifecycle_recording_began_at',
        'value' => now()->subMonths(6)->toIso8601String(),
    ]);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1, '--fresh' => true])
        ->doesntExpectOutputToContain('records lifecycle history only from')
        ->assertSuccessful();
});

test('it says nothing about boundaries a measurement install does not have', function (): void {
    // The documented case: no history recorded before the desk existed, so
    // nothing to warn about. A warning on every run is noise that teaches
    // operators to skip the output.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1, '--fresh' => true])
        ->doesntExpectOutputToContain('records lifecycle history only from')
        ->assertSuccessful();
});

test('nothing is said to a conversation while it is closed', function (): void {
    // In the product a message on a closed conversation REOPENS it, so a
    // fixture with messages arriving during a closed period depicts a state no
    // install can reach -- and `ResolutionEpisodes` starts the second episode
    // at the wrong moment because of it.
    //
    // Dense enough to REACH the bug. Messages are a minute apart and a close is
    // four hours out, so at sixty messages the old one-third/two-third window
    // contained none of them and the broken fixture looked correct -- which is
    // exactly why this shipped. It needs messages running past the 80-minute
    // mark, so 200.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 12, '--messages' => 200, '--fresh' => true])
        ->assertSuccessful();

    $reopens = AuditEvent::query()->where('action', 'conversation.reopened')->get();

    expect($reopens)->not->toBeEmpty();

    foreach ($reopens as $reopen) {
        $closedAt = AuditEvent::query()
            ->where('action', 'conversation.closed')
            ->where('subject_id', $reopen->subject_id)
            ->where('occurred_at', '<', $reopen->occurred_at)
            ->max('occurred_at');

        expect($closedAt)->not->toBeNull();

        $during = ConversationMessage::query()
            ->where('conversation_id', $reopen->subject_id)
            ->where('created_at', '>', $closedAt)
            ->where('created_at', '<', $reopen->occurred_at)
            ->count();

        expect($during)->toBe(0, 'a message arrived while the conversation was closed, which would have reopened it');
    }
});

test('whoever wrote the message is who reopened the conversation', function (): void {
    // The reopen sits on a message, so it was caused by that message -- and
    // attributing it to anybody else describes history no install can produce.
    // `reopened_by_visitor` and the actor activity table are both computed from
    // this, and it was an independent mix that disagreed with the sender about
    // a quarter of the time.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 120, '--messages' => 5, '--fresh' => true])
        ->assertSuccessful();

    $reopens = AuditEvent::query()->where('action', 'conversation.reopened')->get();

    expect($reopens)->not->toBeEmpty();

    foreach ($reopens as $reopen) {
        $message = ConversationMessage::query()
            ->where('conversation_id', $reopen->subject_id)
            ->where('created_at', $reopen->occurred_at)
            ->first();

        expect($message)->not->toBeNull('a reopen happened at a moment no message did');

        expect($message->sender_type)->toBe($reopen->actor_type,
            'the reopen is credited to a different kind of actor than the message that caused it');
        expect((int) $message->sender_id)->toBe((int) $reopen->actor_id,
            'the reopen is credited to a different person than the one who wrote the message');

        // And the metadata the report splits on agrees with both.
        expect($reopen->metadata['actor'] ?? null)
            ->toBe($message->sender_type === (new Visitor)->getMorphClass() ? 'visitor' : 'agent');
    }

    // Both kinds still occur, or taking the actor from the message has
    // collapsed the split the report exists to show.
    $actors = $reopens->map(fn (AuditEvent $e): string => (string) ($e->metadata['actor'] ?? ''))->unique();

    expect($actors->sort()->values()->all())->toBe(['agent', 'visitor']);
});

test('an answer never arrives after the episode it answers was reopened', function (): void {
    // `ConversationRatingController` rejects a stale episode token, so a rating
    // timestamped after its episode reopened is a row the product cannot
    // produce -- and it would give the report comments impossible times.
    //
    // The delay was added to the close without looking at what followed it, and
    // a close and its reopen can be minutes apart.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 80, '--messages' => 4, '--fresh' => true])
        ->assertSuccessful();

    $ratings = ConversationRating::query()->get();

    expect($ratings)->not->toBeEmpty('no ratings at all, so this asserts nothing');

    foreach ($ratings as $rating) {
        $reopenedAt = AuditEvent::query()
            ->where('action', 'conversation.reopened')
            ->where('subject_id', $rating->conversation_id)
            ->where('occurred_at', '>', $rating->episode_closed_at)
            ->min('occurred_at');

        if ($reopenedAt === null) {
            continue;
        }

        expect($rating->rated_at->lessThan(Carbon::parse($reopenedAt)))
            ->toBeTrue('a visitor answered an episode that had already been reopened');
    }
});

test('a visitor sometimes says why', function (): void {
    // `SupportReport::comments()` filters on `whereNotNull`, so a fixture with
    // every comment null returned an empty list and the report tab's comment
    // rows were never rendered at any desk size -- the section would have been
    // measured as free.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 120, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    $withComment = ConversationRating::query()->whereNotNull('comment')->count();
    $total = ConversationRating::query()->count();

    expect($withComment)->toBeGreaterThan(0, 'no rating carries a comment, so the comments section renders nothing')
        ->and($withComment)->toBeLessThan($total, 'every rating carries a comment, which no real desk produces');

    // Varied, because the report renders these: a fixture of one repeated
    // string measures a narrower row than a real desk returns.
    expect(ConversationRating::query()->whereNotNull('comment')->distinct()->count('comment'))
        ->toBeGreaterThan(1, 'every comment is the same string');
});

test('--messages holds even for one conversation', function (): void {
    // The deltas are ordered so any PREFIX stays near zero, not just a whole
    // cycle. Running `($index % 5) - 2` kept only a low-valued prefix when the
    // count was not a multiple of five, so a single conversation asking for six
    // messages got four.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 1, '--messages' => 6, '--fresh' => true])
        ->assertSuccessful();

    expect(ConversationMessage::query()->count())
        ->toBe(6, 'one conversation did not get the number of messages asked for');
});

test('a visitor is not active on one surface and long gone on another', function (): void {
    // `Visitor::saving()` advances `last_seen_at` whenever `last_web_seen_at`
    // does, and a bulk insert bypasses it -- so a visitor showed as active on
    // the live board while the directory said they were last seen months ago.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 40, '--messages' => 2, '--fresh' => true])
        ->assertSuccessful();

    $contradictory = Visitor::query()
        ->whereNotNull('last_web_seen_at')
        ->whereColumn('last_web_seen_at', '>', 'last_seen_at')
        ->count();

    expect($contradictory)->toBe(0, 'a visitor was seen on the web after the last time they were seen at all');
});

test('--messages=0 claims no activity it did not create', function (): void {
    // Every conversation was stamped `last_message_at` thirty minutes after it
    // opened whether or not a message existed -- phantom activity that the
    // queue, the reports and the seeded read states all read, and which for a
    // recent conversation sat in the future.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 0, '--fresh' => true])
        ->assertSuccessful();

    expect(ConversationMessage::query()->count())->toBe(0);

    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->whereNotNull('last_message_at')->count())
        ->toBe(0, 'a conversation with no messages claims a last message');
});

test('last_message_at is the last message that exists', function (): void {
    // Not a fixed offset from when the conversation opened. The offset put it
    // in the future for a recent conversation, and disagreed with the messages
    // for every other one.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 20, '--messages' => 4, '--fresh' => true])
        ->assertSuccessful();

    $wrong = Conversation::query()
        ->where('support_code', 'like', 'WF-DESK-%')
        ->get()
        ->filter(function (Conversation $conversation): bool {
            $last = $conversation->messages()->max('created_at');

            return $last === null
                ? $conversation->last_message_at !== null
                : $conversation->last_message_at?->toJSON() !== Carbon::parse($last)->toJSON();
        });

    expect($wrong)->toHaveCount(0, 'a conversation disagrees with its own messages about when the last one arrived');

    // And none of it is in the future.
    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->where('last_message_at', '>', now())->count())
        ->toBe(0, 'a conversation reports activity that has not happened yet');
});

test('fresh refuses an empty account that is not named as ours', function (): void {
    // Allowing ANY empty account through was meant to unblock an interrupted
    // first run, and it let a legitimate but not-yet-configured account at this
    // slug be renamed and adopted -- the same failure the provenance check
    // exists to prevent, wearing the shape of a kindness.
    $theirs = Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'Acme Support']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--fresh' => true])
        ->assertFailed();

    expect(Account::query()->whereKey($theirs->id)->exists())->toBeTrue('an empty real account was deleted')
        ->and(Account::query()->whereKey($theirs->id)->value('name'))->toBe('Acme Support', 'an empty real account was renamed');
});

test('fresh still cleans up a half-made desk', function (): void {
    // The provenance check must not strand an operator whose first run was
    // interrupted. That leaves an account with no sites and no users AND this
    // command's name, because the name is written before anything else happens.
    Account::query()->create(['slug' => 'wayfindr-measurement-desk', 'name' => 'Measurement Desk']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--fresh' => true])
        ->assertSuccessful();

    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count())->toBe(5);
});

test('it refuses a support code another account already holds', function (): void {
    // `conversations.support_code` is globally unique, so a real or legacy row
    // holding one of the codes this run generates aborts the insert -- after
    // the account, agents, sites and visitors are written, and with `--fresh`
    // unable to help, because the conflicting row is not this command's to
    // delete.
    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);
    $otherSite = Site::factory()->for($other)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)
        ->create(['support_code' => 'WF-DESK-0000003']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1])
        ->assertFailed()
        ->expectsOutputToContain('WF-DESK-0000003');

    // And it stopped BEFORE building anything.
    expect(Account::query()->where('slug', 'wayfindr-measurement-desk')->exists())
        ->toBeFalse('the run created its account before discovering it could not finish');
});

test('a code that only starts like ours is not a collision', function (): void {
    // The lexicographic range also contains `WF-DESK-0000003-LEGACY`, which
    // this command will never insert and which therefore cannot collide.
    // Refusing on the range alone rejected a fixture that was fine.
    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);
    $otherSite = Site::factory()->for($other)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)
        ->create(['support_code' => 'WF-DESK-0000003-LEGACY']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1])
        ->assertSuccessful();

    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-0%')->count())
        ->toBeGreaterThan(0);
});

test('it refuses rather than take over an address somebody else holds', function (): void {
    // Two ways this command reached outside its own account, both found by
    // asking what happens when a real user holds a `desk-agent-` address:
    //
    //   - `--fresh` deleted them, because the cleanup matched on the ADDRESS,
    //     which is global. That was the fix for the orphan problem breaking a
    //     different promise in the course of keeping one.
    //   - Seeding then MOVED them. `users.email` is globally unique, so
    //     `updateOrCreate` keyed on the address does not create a second user;
    //     it reassigns the existing one -- onto a desk whose password this
    //     command prints on success.
    //
    // Refusing is the right answer to "somebody already holds the address I
    // need". It is recoverable; taking over their account is not.
    // A desk exists FIRST, so `--fresh` has something to clean and the global
    // delete is actually reached. Without that the cleanup is a no-op and the
    // scoping cannot be observed at all.
    // ONE agent, so index 1 is still free for somebody else to hold.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 1])
        ->assertSuccessful();

    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);

    // An index the next run DOES want, so seeding would reassign them.
    $bystander = User::factory()->for($other)->create(['email' => 'desk-agent-1@example.test']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 2, '--fresh' => true])
        ->assertFailed()
        ->expectsOutputToContain('desk-agent-1@example.test');

    // And the desk that was already there is INTACT. The refusals run before
    // `--fresh` deletes anything, so a run that could never have finished no
    // longer costs the operator the fixture they had.
    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count())
        ->toBe(8, 'a refused run deleted the desk it was going to replace');

    expect(User::query()->whereKey($bystander->id)->exists())
        ->toBeTrue('a user on another account was deleted for holding a matching address');

    expect(User::query()->whereKey($bystander->id)->value('account_id'))
        ->toBe($other->id, 'a user on another account was moved onto the measurement desk');
});

test('it does not refuse over an address it would never hand out', function (): void {
    // The refusal matched the PATTERN, so an unrelated account holding a
    // valid-looking high index blocked every default seed -- permanently, and
    // for an address the run could not have taken: eight agents never reach
    // index 999. The check asks for the addresses this run actually plans to
    // create now.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 2])
        ->assertSuccessful();

    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);
    $bystander = User::factory()->for($other)->create(['email' => 'desk-agent-999@example.test']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 2, '--fresh' => true])
        ->assertSuccessful();

    // And `--fresh` still did not take them with it. The delete is scoped to
    // the account, not to the address shape, which is the promise the narrower
    // refusal now leans on.
    expect(User::query()->whereKey($bystander->id)->exists())
        ->toBeTrue('a user on another account was deleted for holding a matching address');

    expect(User::query()->whereKey($bystander->id)->value('account_id'))
        ->toBe($other->id, 'a user on another account was moved onto the measurement desk');
});

test('it refuses to run in production without being told twice', function (): void {
    // Restored in a `finally`, because `app()['env']` is process-global and
    // `RefreshDatabase` does not touch it. Leaving it set leaked `production`
    // into every test that ran afterwards in the same process -- which surfaced
    // as an unrelated cobrowse translation failing on PostgreSQL and passing on
    // SQLite, purely because the two drivers order the suite differently.
    $environment = app()['env'];

    try {
        app()['env'] = 'production';

        $this->artisan('wayfindr:seed-desk', ['--conversations' => 5])->assertFailed();

        expect(Account::query()->where('slug', 'wayfindr-measurement-desk')->exists())->toBeFalse();
    } finally {
        app()['env'] = $environment;
    }
});

test('--force gets past the production refusal', function (): void {
    // Or the guard above would be satisfied by a command that never runs at
    // all, and the escape hatch it names would not exist.
    $environment = app()['env'];

    try {
        app()['env'] = 'production';

        $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1, '--force' => true])
            ->assertSuccessful();

        expect(Account::query()->where('slug', 'wayfindr-measurement-desk')->exists())->toBeTrue();
    } finally {
        app()['env'] = $environment;
    }
});

test('the mixer keeps its attributes independent at desk size', function (): void {
    // Asserted on the FUNCTION, not through the database. The skew this catches
    // only appears at scale: with CRC32 in place the open lane came out 8,309
    // assigned against 18 unassigned at fifty thousand rows, and a fixture test
    // seeding a hundred and sixty passed it happily.
    //
    // Fifty thousand iterations of two hashes costs milliseconds, so the size
    // that matters is affordable here in a way it is not through a seeder.
    $mix = SeedDeskCommand::mix(...);

    $openAssigned = 0;
    $openUnassigned = 0;

    for ($i = 0; $i < 50000; $i++) {
        if ($mix($i, 'status', 6) !== 0) {
            continue;
        }

        $mix($i, 'assignee', 2) === 0 ? $openAssigned++ : $openUnassigned++;
    }

    $open = $openAssigned + $openUnassigned;

    expect($open)->toBeGreaterThan(5000, 'the status mixer is not producing an open lane at all');

    expect($openUnassigned / $open)
        ->toBeGreaterThan(0.3, "status and assignment are correlated: {$openAssigned} assigned against {$openUnassigned} unassigned")
        ->toBeLessThan(0.7, "status and assignment are correlated: {$openAssigned} assigned against {$openUnassigned} unassigned");

    // And each attribute is itself uniform, or one value dominates a filter
    // that the measurement is meant to exercise evenly.
    foreach ([['priority', 4], ['category', 6], ['status', 3]] as [$attribute, $of]) {
        $buckets = array_fill(0, $of, 0);

        for ($i = 0; $i < 50000; $i++) {
            $buckets[$mix($i, $attribute, $of)]++;
        }

        $expected = 50000 / $of;

        foreach ($buckets as $value => $count) {
            expect($count)->toBeGreaterThan($expected * 0.8, "{$attribute} value {$value} is under-represented")
                ->toBeLessThan($expected * 1.2, "{$attribute} value {$value} is over-represented");
        }
    }
});

test('a refused run leaves the desk it was going to replace', function (): void {
    // `--fresh` deleted the existing desk before the collision check, so a run
    // that could never have finished -- a support code held by another account,
    // in a range only the LARGER replacement reaches -- cost the operator the
    // fixture they already had on the way to failing.
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 5, '--messages' => 1])
        ->assertSuccessful();

    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);
    $otherSite = Site::factory()->for($other)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    // Outside the old desk's range of 0-4, inside the new run's range of 0-9.
    Conversation::factory()->for($otherSite)->for($otherVisitor)
        ->create(['support_code' => 'WF-DESK-0000007']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 10, '--messages' => 1, '--fresh' => true])
        ->assertFailed();

    expect(Conversation::query()->where('support_code', 'like', 'WF-DESK-000000%')->whereIn('support_code', [
        'WF-DESK-0000000', 'WF-DESK-0000001', 'WF-DESK-0000002', 'WF-DESK-0000003', 'WF-DESK-0000004',
    ])->count())->toBe(5, 'the refused run deleted the desk it was replacing');
});
