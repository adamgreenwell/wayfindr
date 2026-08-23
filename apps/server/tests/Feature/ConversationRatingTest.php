<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\Sites\SiteRatingPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every other figure on the reports page describes how FAST support moved. A
 * desk can improve all of them while getting worse at helping people, and until
 * now nothing in the product would have noticed.
 */
function ratingWorld(): array
{
    $site = Site::factory()->for(Account::factory())->create(['public_key' => 'site_public_rate']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-rate']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RATE1',
        'status' => 'closed',
        'closed_at' => now(),
    ]);

    return compact('site', 'visitor', 'conversation');
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
    // A row per rating rather than a column: the second answer is a second
    // data point, not a correction of the first.
    $w = ratingWorld();
    $token = ratingToken($this);

    foreach (['bad', 'good'] as $score) {
        $this->postJson(route('conversations.rating.store', 'WF-RATE1'), [
            'site_public_key' => 'site_public_rate',
            'anonymous_id' => 'anon-rate',
            'visitor_token' => $token,
            'score' => $score,
        ])->assertCreated();
    }

    expect(ConversationRating::query()->pluck('score')->all())->toBe(['bad', 'good']);
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

    $conversation = Conversation::factory()->for($site)->for(Visitor::factory()->for($site))->create();

    foreach (['good', 'good', 'bad'] as $score) {
        ConversationRating::factory()->for($conversation)->for($site)->create(['score' => $score, 'rated_at' => now()->subDay()]);
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
