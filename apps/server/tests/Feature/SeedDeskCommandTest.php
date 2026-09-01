<?php

declare(strict_types=1);

use App\Console\Commands\SeedDeskCommand;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
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
    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 2])
        ->assertSuccessful();

    $other = Account::query()->create(['slug' => 'someone-elses-desk', 'name' => 'Theirs']);

    // An index this run does not want, so the only thing that touches it is a
    // rule matching the PATTERN rather than the account.
    $bystander = User::factory()->for($other)->create(['email' => 'desk-agent-9@example.test']);

    $this->artisan('wayfindr:seed-desk', ['--conversations' => 8, '--messages' => 1, '--agents' => 2, '--fresh' => true])
        ->assertFailed()
        ->expectsOutputToContain('desk-agent-9@example.test');

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
