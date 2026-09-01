<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
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
    $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 60,
        '--messages' => 4,
        '--agents' => 3,
        '--sites' => 2,
    ])->assertSuccessful();

    $account = Account::query()->where('slug', 'wayfindr-measurement-desk')->firstOrFail();

    expect(User::query()->where('account_id', $account->id)->count())->toBe(3)
        ->and(Site::query()->where('account_id', $account->id)->count())->toBe(2)
        ->and(Conversation::query()->where('support_code', 'like', 'WF-DESK-%')->count())->toBe(60);

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

    expect($openScope()->whereNull('assigned_agent_id')->count())
        ->toBeGreaterThan(0, 'no OPEN conversation is unassigned, so that lane renders nothing')
        ->and($openScope()->whereNotNull('assigned_agent_id')->count())
        ->toBeGreaterThan(0, 'no OPEN conversation is assigned, so the assignee lane renders nothing');

    // Subjects vary, or a search measures a full-table match rather than a
    // search. Twelve openings against sixty rows.
    $distinctSubjects = Conversation::query()
        ->where('support_code', 'like', 'WF-DESK-%')
        ->distinct()
        ->count('subject');

    expect($distinctSubjects)->toBe(60);

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

    expect((clone $openTickets)->whereNull('assignee_id')->count())
        ->toBeGreaterThan(0, 'no OPEN ticket is unassigned; status and assignment are related')
        ->and((clone $openTickets)->whereNotNull('assignee_id')->count())
        ->toBeGreaterThan(0, 'no OPEN ticket is assigned; status and assignment are related');

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
