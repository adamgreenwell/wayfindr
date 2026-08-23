<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\SitePurge;
use App\Support\Sites\SiteRatingPrompt;
use Carbon\CarbonInterface as DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every other figure on the reports page describes how FAST support moved. A
 * desk can improve all of them while getting worse at helping people, and until
 * now nothing in the product would have noticed.
 */
function ratingWorld(): array
{
    $site = Site::factory()->for(Account::factory())->create([
        'public_key' => 'site_public_rate',
        // The desk is asking. Every test below posts an answer, and an answer
        // to a question nobody asked is now refused.
        'settings' => ['rating' => ['enabled' => true]],
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-rate']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RATE1',
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    return compact('site', 'visitor', 'conversation');
}

function postRating($test, string $token, string $score, ?string $comment = null)
{
    return $test->postJson(route('conversations.rating.store', 'WF-RATE1'), array_filter([
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'anon-rate',
        'visitor_token' => $token,
        'score' => $score,
        'comment' => $comment,
    ], fn (mixed $value): bool => $value !== null));
}

/**
 * Reopen and close again, the way the desk records it.
 */
function reopenAndCloseConversation(Conversation $conversation, DateTimeInterface $at): void
{
    app(ConversationLifecycleLog::class)->reopened($conversation->fresh(), null, 'closed');

    $conversation->forceFill(['status' => 'open', 'closed_at' => null])->save();

    app(ConversationLifecycleLog::class)->closed($conversation->fresh(), null, 'open');

    $conversation->forceFill(['status' => 'closed', 'closed_at' => $at])->save();

    AuditEvent::query()
        ->where('subject_id', $conversation->id)
        ->where('action', ConversationLifecycleLog::CLOSED)
        ->latest('id')
        ->limit(1)
        ->update(['occurred_at' => $at]);
}

function ratingToken($test): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'anon-rate',
    ])->assertSuccessful()->json('data.visitor.token');
}

test('a visitor says how it went', function (): void {
    $w = ratingWorld();
    $token = ratingToken($this);

    $this->postJson(route('conversations.rating.store', 'WF-RATE1'), [
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'anon-rate',
        'visitor_token' => $token,
        'score' => 'bad',
        'comment' => '  Took three days.  ',
    ])->assertCreated();

    $rating = ConversationRating::query()->firstOrFail();

    expect($rating->score)->toBe('bad')
        ->and($rating->comment)->toBe('Took three days.')
        ->and($rating->conversation_id)->toBe($w['conversation']->id)
        ->and($rating->site_id)->toBe($w['site']->id);
});

test('a stranger cannot score somebody else’s conversation', function (): void {
    // A support code appears in emails and in a visitor's own transcript, so an
    // endpoint that took only the code would let anybody rate anything.
    ratingWorld();

    $this->postJson(route('conversations.rating.store', 'WF-RATE1'), [
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'someone-else',
        'visitor_token' => 'not-a-real-token',
        'score' => 'good',
        // 401 rather than 403: the token is what identifies the visitor, so a bad
        // one is unauthenticated rather than forbidden -- and it must not confirm
        // that the support code exists.
    ])->assertStatus(401);

    expect(ConversationRating::query()->count())->toBe(0);
});

test('a score nobody offered is refused', function (): void {
    ratingWorld();
    $token = ratingToken($this);

    $this->postJson(route('conversations.rating.store', 'WF-RATE1'), [
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'anon-rate',
        'visitor_token' => $token,
        'score' => 'five-stars',
    ])->assertStatus(422);

    expect(ConversationRating::query()->count())->toBe(0);
});

test('a reopened conversation can be rated again without erasing the first answer', function (): void {
    // A row per rating rather than a column: after a REOPEN the second answer
    // is a second data point, not a correction. The same conversation going
    // well and later badly is the signal worth keeping.
    //
    // The first version of this test posted twice and never reopened anything,
    // so it passed against an endpoint that accepted unlimited ratings -- it
    // was describing a property nothing implemented.
    $w = ratingWorld();
    $token = ratingToken($this);

    postRating($this, $token, 'bad')->assertCreated();

    // The reopen and the close that follows it, exactly as the desk records
    // them (ADR 0015).
    reopenAndCloseConversation($w['conversation'], now()->addMinutes(10));

    postRating($this, $token, 'good')->assertCreated();

    expect(ConversationRating::query()->orderBy('rated_at')->pluck('score')->all())->toBe(['bad', 'good']);
});

test('answering twice about the same close replaces the answer rather than stuffing the ballot', function (): void {
    // CSAT response rates are low everywhere, so the denominator is small and
    // a small denominator is cheap to swamp. Somebody holding a visitor token
    // -- the visitor themselves, or a script that got hold of it -- must not be
    // able to post the same score two hundred times and move the aggregate two
    // hundred times.
    $w = ratingWorld();
    $token = ratingToken($this);

    postRating($this, $token, 'bad')->assertCreated();

    // Changing your mind is allowed, and is what this is: one person, one
    // answer, the latest one.
    postRating($this, $token, 'good', 'Sorted in the end.')->assertOk();

    for ($i = 0; $i < 20; $i++) {
        postRating($this, $token, 'good')->assertOk();
    }

    expect(ConversationRating::query()->count())->toBe(1)
        ->and(ConversationRating::query()->value('score'))->toBe('good');
});

test('the site asks nothing unless an operator turned it on', function (): void {
    $off = Site::factory()->create(['settings' => []]);
    $on = Site::factory()->create(['settings' => ['rating' => ['enabled' => true, 'intro' => '  How did we do?  ']]]);

    expect(SiteRatingPrompt::for($off)->enabled)->toBeFalse()
        ->and(SiteRatingPrompt::for($on)->enabled)->toBeTrue()
        ->and(SiteRatingPrompt::for($on)->intro)->toBe('How did we do?');
});

test('the report never averages over people who did not answer', function (): void {
    // A response rate on CSAT is low everywhere, and a non-response is not a
    // neutral score. Reporting a percentage without saying how few people
    // answered is how a satisfied-looking number gets built out of four replies.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    // Three people, three conversations. One conversation cannot carry three
    // answers about the same close any more -- that is the ballot-box bound,
    // and a test that models it would be describing something impossible.
    foreach (['good', 'good', 'bad'] as $score) {
        $conversation = Conversation::factory()->for($site)->for(Visitor::factory()->for($site))->create();

        ConversationRating::factory()->for($conversation)->for($site)->create([
            'score' => $score,
            'rated_at' => now()->subDay(),
            'episode_closed_at' => now()->subDay(),
        ]);
    }

    $report = new SupportReport(
        ReportingScope::for($account, $agent, null),
        ReportingWindow::fromRequestValue('30'),
    );

    $satisfaction = $report->satisfaction();

    expect($satisfaction['answered'])->toBe(3)
        ->and($satisfaction['good'])->toBe(2)
        ->and($satisfaction['bad'])->toBe(1)
        ->and($satisfaction['positive'])->toBe(66.7);
});

test('no answers means no percentage, rather than zero', function (): void {
    // A zero would read as "everybody was unhappy" when it means "nobody said".
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    Site::factory()->for($account)->create()->supportAgents()->syncWithoutDetaching($agent->id);

    $report = new SupportReport(
        ReportingScope::for($account, $agent, null),
        ReportingWindow::fromRequestValue('30'),
    );

    expect($report->satisfaction()['positive'])->toBeNull()
        ->and($report->satisfaction()['answered'])->toBe(0);
});

test('an admin turns the prompt on and off from the site page', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.rating.update', $site), [
            'rating_enabled' => '1',
            'rating_intro' => '  How did we do?  ',
        ])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect(SiteRatingPrompt::for($site->fresh())->enabled)->toBeTrue()
        ->and(SiteRatingPrompt::for($site->fresh())->intro)->toBe('How did we do?');

    // Clearing the box has to actually clear it. An unchecked checkbox sends
    // nothing at all, so the form posts a hidden 0 alongside it -- without
    // that, turning the prompt off silently leaves it on.
    $this->actingAs($admin)
        ->put(route('dashboard.sites.rating.update', $site), ['rating_enabled' => '0'])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect(SiteRatingPrompt::for($site->fresh())->enabled)->toBeFalse();
});

test('the site page offers the toggle, and the form posts what the controller reads', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('Asking how it went')
        // The hidden companion, without which the box cannot be cleared.
        ->assertSee('<input type="hidden" name="rating_enabled" value="0">', false)
        ->assertSee('name="rating_intro"', false);
});

test('an agent who cannot change the site is not shown the toggle', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('Only an account admin can change whether visitors are asked.');

    $this->actingAs($agent)
        ->put(route('dashboard.sites.rating.update', $site), ['rating_enabled' => '1'])
        ->assertForbidden();

    expect(SiteRatingPrompt::for($site->fresh())->enabled)->toBeFalse();
});

test('the reports page never shows a percentage nobody answered for', function (): void {
    // The failure this guards is a 0% sitting where a score goes, read by
    // somebody skimming as "everybody said it went badly". Closes with no
    // answers must say so in words.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();

    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'status' => 'closed',
        'closed_at' => now()->subDay(),
    ]);

    app(ConversationLifecycleLog::class)->closed($conversation->fresh(), $agent, 'open');

    $this->actingAs($agent)
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('Satisfaction')
        ->assertSee('Whether it helped')
        ->assertSee('Nobody answered in this period')
        ->assertDontSee('0% of the people who answered');
});

test('the reports page reports a share of the people who answered, not of the closes', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();

    // Four closes, two answers, one of them good. The honest figure is 50% of
    // the two who answered -- not 25% of the four who were asked.
    foreach (range(1, 4) as $i) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'status' => 'closed',
            'closed_at' => now()->subDay(),
        ]);

        app(ConversationLifecycleLog::class)->closed($conversation->fresh(), $agent, 'open');

        if ($i <= 2) {
            ConversationRating::factory()->for($conversation)->create([
                'site_id' => $site->id,
                'score' => $i === 1 ? 'good' : 'bad',
                'rated_at' => now()->subDay(),
            ]);
        }
    }

    $this->actingAs($agent)
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('50% of the people who answered')
        ->assertDontSee('25% of the people who answered');
});

test('purging a site takes its ratings with it', function (): void {
    // Claimed in docs/privacy/data-inventory.md, so it is worth proving rather
    // than trusting the cascade to have been declared correctly. A comment is
    // whatever the visitor decided to type, and a purge that left it behind
    // would leave the most personal field in the table.
    $w = ratingWorld();

    ConversationRating::factory()->for($w['conversation'])->create([
        'site_id' => $w['site']->id,
        'score' => 'bad',
        'comment' => 'My order number is 41255 and my number is 555-0199.',
    ]);

    expect(ConversationRating::query()->count())->toBe(1);

    $actor = User::factory()->for($w['site']->account)->create(['account_role' => AccountRole::Owner]);

    app(SitePurge::class)->purge($w['site'], $actor);

    expect(ConversationRating::query()->count())->toBe(0);
});

test('a comment is shown to the people who can act on it', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'status' => 'closed',
        'support_code' => 'WF-SAIDIT',
    ]);

    ConversationRating::factory()->for($conversation)->create([
        'site_id' => $site->id,
        'score' => 'bad',
        'comment' => 'Nobody answered for two days and I gave up.',
        'rated_at' => now()->subHours(3),
    ]);

    // Collecting a comment and never showing it would be worse than not asking
    // for one: the visitor spent effort answering a question nobody reads.
    $this->actingAs($agent)
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertSee('What people said')
        ->assertSee('Nobody answered for two days and I gave up.')
        // Labelled by support code, never by subject line: a subject is
        // visitor-authored text, a support code is a reference by construction.
        ->assertSee('WF-SAIDIT');
});

test('a comment never leaves the account that received it', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $stranger = Account::factory()->create();
    $strangerSite = Site::factory()->for($stranger)->create();
    $strangerVisitor = Visitor::factory()->for($strangerSite)->create();
    $strangerConversation = Conversation::factory()->for($strangerSite)->for($strangerVisitor)->create(['status' => 'closed']);

    ConversationRating::factory()->for($strangerConversation)->create([
        'site_id' => $strangerSite->id,
        'score' => 'bad',
        'comment' => 'Something private about somebody else.',
        'rated_at' => now()->subHour(),
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->assertDontSee('Something private about somebody else.');
});

test('a comment is visitor-authored text and is escaped like any other', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'closed']);

    ConversationRating::factory()->for($conversation)->create([
        'site_id' => $site->id,
        'score' => 'bad',
        'comment' => '<script>alert(1)</script> and "quotes"',
        'rated_at' => now(),
    ]);

    $html = $this->actingAs($agent)
        ->get(route('dashboard.reports.index', ['report_days' => 30]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

test('a site that turned the prompt off does not collect ratings anyway', function (): void {
    // The endpoint is reachable without the widget. An operator who explicitly
    // switched collection off must not find scores in their reports.
    $w = ratingWorld();
    $w['site']->forceFill(['settings' => ['rating' => ['enabled' => false]]])->save();
    $token = ratingToken($this);

    postRating($this, $token, 'good')->assertStatus(422);

    expect(ConversationRating::query()->count())->toBe(0);
});

test('an open conversation cannot be rated', function (): void {
    // There is no finished stretch of work to answer about, so a score here is
    // a number nobody was ever asked for.
    $w = ratingWorld();
    $w['conversation']->forceFill(['status' => 'open', 'closed_at' => null])->save();
    $token = ratingToken($this);

    postRating($this, $token, 'bad')->assertStatus(422);

    expect(ConversationRating::query()->count())->toBe(0);
});

test('one answer per close is a database rule, not a read-then-write', function (): void {
    // Two concurrent requests both see no row and both insert, and the bound
    // that keeps a small denominator from being swamped stops holding. The
    // unique index is what makes it hold; this asserts the index exists and
    // bites, because the controller alone cannot.
    $w = ratingWorld();
    $episode = $w['conversation']->currentCloseEpisodeAt();

    ConversationRating::factory()->for($w['conversation'])->for($w['site'])->create([
        'score' => 'good',
        'episode_closed_at' => $episode,
    ]);

    expect(fn () => ConversationRating::factory()->for($w['conversation'])->for($w['site'])->create([
        'score' => 'bad',
        'episode_closed_at' => $episode,
    ]))->toThrow(QueryException::class);
});

test('an answer is counted against the close it answers, not the moment it arrived', function (): void {
    // Otherwise a visitor who answers just after a window boundary is counted
    // without the close they answered about, and the page reports "1 of 0
    // closes answered".
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $conversation = Conversation::factory()->for($site)->for(Visitor::factory()->for($site))->create();

    // The close is far outside a 7-day window; the answer arrived inside it.
    ConversationRating::factory()->for($conversation)->for($site)->create([
        'score' => 'good',
        // With a comment, so the comments list is tested on the cohort filter
        // rather than on whether a comment exists at all.
        'comment' => 'Answered late, about a close from last month.',
        'episode_closed_at' => now()->subDays(40),
        'rated_at' => now()->subHour(),
    ]);

    $report = new SupportReport(
        ReportingScope::for($account, $agent, null),
        ReportingWindow::fromRequestValue('7'),
    );

    expect($report->satisfaction()['answered'])->toBe(0)
        ->and($report->satisfaction()['positive'])->toBeNull()
        ->and($report->comments())->toBe([]);
});

test('the widget is told whether this close has already been answered', function (): void {
    // Widget memory cannot answer this: it is lost on reload, so the visitor
    // would be asked again about a close they already rated, and it survives a
    // genuine reopen, so they would never be asked about the next one.
    $w = ratingWorld();
    $token = ratingToken($this);

    $read = fn () => $this->getJson(route('conversations.messages.index', 'WF-RATE1').'?'.http_build_query([
        'site_public_key' => 'site_public_rate',
        'anonymous_id' => 'anon-rate',
        'visitor_token' => $token,
    ]));

    expect($read()->json('data.conversation.rated'))->toBeFalse();

    postRating($this, $token, 'good')->assertCreated();

    expect($read()->json('data.conversation.rated'))->toBeTrue();

    // A genuine reopen and close is a new question, and the widget is told so.
    reopenAndCloseConversation($w['conversation'], now()->addMinutes(10));

    expect($read()->json('data.conversation.rated'))->toBeFalse();
});
