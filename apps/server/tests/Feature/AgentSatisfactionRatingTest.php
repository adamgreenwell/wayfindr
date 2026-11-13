<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRating;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use App\Support\Sites\SiteRatingPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The starter template asks about the one agent who helped, separately from the
 * overall experience (#1156). The rules under test are the ones
 * docs/product/satisfaction.md records: who qualifies, what is snapshotted,
 * and that skipped or not-applicable answers are never scores.
 */
function agentRatingWorld(string $template = 'starter'): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create([
        'public_key' => 'site_public_agentrate',
        'settings' => ['rating' => ['enabled' => true, 'template' => $template]],
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-agentrate']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AGENT1',
        'status' => 'open',
        'created_at' => now()->subHour(),
    ]);

    return compact('account', 'site', 'visitor', 'conversation');
}

function agentRatingReply(Conversation $conversation, User $agent, string $at): void
{
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Here is how.',
        'created_at' => $at,
    ]);
}

function agentRatingClose(Conversation $conversation, string $at, ?User $by = null): AuditEvent
{
    app(ConversationLifecycleLog::class)->closed($conversation->fresh(), $by, 'open');
    $conversation->forceFill(['status' => 'closed', 'closed_at' => $at])->save();

    $event = AuditEvent::query()
        ->where('subject_id', $conversation->id)
        ->where('action', ConversationLifecycleLog::CLOSED)
        ->latest('id')
        ->firstOrFail();
    $event->forceFill(['occurred_at' => $at])->save();

    return $event->fresh();
}

function agentRatingReopen(Conversation $conversation, string $at): void
{
    app(ConversationLifecycleLog::class)->reopened($conversation->fresh(), null, 'closed');
    $conversation->forceFill(['status' => 'open', 'closed_at' => null])->save();

    AuditEvent::query()
        ->where('subject_id', $conversation->id)
        ->where('action', ConversationLifecycleLog::REOPENED)
        ->latest('id')
        ->limit(1)
        ->update(['occurred_at' => $at]);
}

function agentRatingToken($test, Conversation $conversation): string
{
    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_agentrate',
        'anonymous_id' => 'anon-agentrate',
    ])->assertSuccessful()->json('data.visitor.token');

    conversationOwnedBySession($conversation, $token);

    return $token;
}

function agentRatingPoll($test, string $token)
{
    return $test->getJson(route('conversations.messages.index', 'WF-AGENT1').'?'.http_build_query([
        'site_public_key' => 'site_public_agentrate',
        'anonymous_id' => 'anon-agentrate',
        'visitor_token' => $token,
    ]));
}

function agentRatingPost($test, string $token, array $answer)
{
    $conversation = Conversation::query()->where('support_code', 'WF-AGENT1')->firstOrFail();

    return $test->postJson(route('conversations.rating.store', 'WF-AGENT1'), array_filter($answer + [
        'site_public_key' => 'site_public_agentrate',
        'anonymous_id' => 'anon-agentrate',
        'visitor_token' => $token,
        'episode' => $conversation->currentCloseEpisodeToken(),
        'template' => 'starter',
        'template_version' => 1,
    ], fn (mixed $value): bool => $value !== null));
}

test('the one agent who replied in the closed episode is named, and the answer is snapshotted', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create(['name' => 'Ada Agent']);
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    $named = agentRatingPoll($this, $token)->assertOk()->json('data.conversation.rating_agent');

    expect($named)->toBe(['ref' => Conversation::ratingAgentToken($close, $ada), 'name' => 'Ada Agent']);

    agentRatingPost($this, $token, ['score' => 'ok', 'agent' => $named['ref'], 'agent_score' => 'good'])->assertCreated();

    $rating = ConversationRating::query()->firstOrFail();

    expect($rating->score)->toBe('ok')
        ->and($rating->template_key)->toBe('starter')
        ->and($rating->template_version)->toBe(1)
        ->and($rating->agent_user_id)->toBe($ada->id)
        ->and($rating->agent_name)->toBe('Ada Agent')
        ->and($rating->agent_score)->toBe('good');
});

test('nobody is named when no one, or more than one person, replied in the episode', function (): void {
    $w = agentRatingWorld();
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    expect($w['conversation']->ratingEpisodeAgent($close))->toBeNull()
        ->and(agentRatingPoll($this, $token)->json('data.conversation.rating_agent'))->toBeNull();

    $other = Conversation::factory()->for($w['site'])->for($w['visitor'])->create(['status' => 'open']);
    $ada = User::factory()->for($w['account'])->create();
    $bob = User::factory()->for($w['account'])->create();
    agentRatingReply($other, $ada, '2026-11-01 10:01:00');
    agentRatingReply($other, $bob, '2026-11-01 10:02:00');
    $handoff = agentRatingClose($other, '2026-11-01 10:10:00');

    // A handoff: two people helped, so there is no honest single answer.
    expect($other->ratingEpisodeAgent($handoff))->toBeNull();
});

test('assignment and who clicked Close settle nothing; only who replied does', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create();
    $closer = User::factory()->for($w['account'])->create();
    $w['conversation']->forceFill(['assigned_agent_id' => $closer->id])->save();
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00', $closer);

    expect($w['conversation']->ratingEpisodeAgent($close)?->id)->toBe($ada->id);

    // Reassigning later moves nothing either.
    $w['conversation']->forceFill(['assigned_agent_id' => User::factory()->for($w['account'])->create()->id])->save();
    expect($w['conversation']->fresh()->ratingEpisodeAgent($close)?->id)->toBe($ada->id);
});

test('a reopened episode names only the person who helped in it', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create();
    $bob = User::factory()->for($w['account'])->create();
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $first = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    agentRatingReopen($w['conversation'], '2026-11-01 11:00:00');
    agentRatingReply($w['conversation'], $bob, '2026-11-01 11:05:00');
    $second = agentRatingClose($w['conversation'], '2026-11-01 11:10:00');

    expect($w['conversation']->ratingEpisodeAgent($first)?->id)->toBe($ada->id)
        ->and($w['conversation']->ratingEpisodeAgent($second)?->id)->toBe($bob->id);
});

test('integrations, API tokens and proactive rules are not a person helping', function (): void {
    $w = agentRatingWorld();
    ConversationMessage::factory()->for($w['conversation'])->create([
        'sender_type' => ApiToken::class,
        'sender_id' => 1,
        'created_at' => '2026-11-01 10:05:00',
    ]);
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');

    expect($w['conversation']->ratingEpisodeAgent($close))->toBeNull();
});

test('an agent answer naming anyone but the agent who helped is refused', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create();
    $mallory = User::factory()->for($w['account'])->create();
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    agentRatingPost($this, $token, ['score' => 'good', 'agent' => Conversation::ratingAgentToken($close, $mallory), 'agent_score' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');

    // A score with no agent named is not an agent answer either.
    agentRatingPost($this, $token, ['score' => 'good', 'agent_score' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent_score');

    // And the single-question template never carries one.
    agentRatingPost($this, $token, ['score' => 'good', 'template' => 'overall', 'agent' => Conversation::ratingAgentToken($close, $ada), 'agent_score' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');

    expect(ConversationRating::query()->count())->toBe(0);
});

test('an answer keeps the template it was shown, whatever the site switched to since', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create(['name' => 'Ada']);
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    // The admin goes back to the single question while the prompt is open.
    $w['site']->mutateSettings(function (array $settings): array {
        $settings['rating']['template'] = 'overall';

        return $settings;
    });

    agentRatingPost($this, $token, ['score' => 'good', 'agent' => Conversation::ratingAgentToken($close, $ada), 'agent_score' => 'ok'])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->only(['template_key', 'agent_score']))
        ->toBe(['template_key' => 'starter', 'agent_score' => 'ok']);

    agentRatingPost($this, $token, ['score' => 'good', 'template' => 'starter', 'template_version' => 99])
        ->assertUnprocessable()->assertJsonValidationErrors('template');
});

test('a widget that predates templates is stored as the single question', function (): void {
    $w = agentRatingWorld('overall');
    agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    agentRatingPost($this, $token, ['score' => 'bad', 'template' => null, 'template_version' => null])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->only(['template_key', 'template_version', 'agent_name']))
        ->toBe(['template_key' => 'overall', 'template_version' => 1, 'agent_name' => null]);
});

test('the snapshot survives a rename, and a deleted account keeps the name the visitor saw', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create(['name' => 'Ada Agent']);
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);

    agentRatingPost($this, $token, ['score' => 'good', 'agent' => Conversation::ratingAgentToken($close, $ada), 'agent_score' => 'good'])->assertCreated();

    $ada->forceFill(['name' => 'Ada Renamed'])->save();
    expect(ConversationRating::query()->firstOrFail()->agent_name)->toBe('Ada Agent');

    $ada->delete();

    expect(ConversationRating::query()->firstOrFail()->only(['agent_user_id', 'agent_name']))
        ->toBe(['agent_user_id' => null, 'agent_name' => 'Ada Agent']);
});

test('agent results are separate, and not applicable or skipped is never a score', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create(['name' => 'Ada']);
    $closedAt = now()->subDay()->startOfHour();
    $base = ['conversation_id' => $w['conversation']->id, 'site_id' => $w['site']->id, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt];

    foreach ([
        ['score' => 'bad', 'agent_score' => 'good'],
        ['score' => 'bad', 'agent_score' => 'ok'],
        ['score' => 'good', 'agent_score' => 'not_applicable'],
        ['score' => 'good', 'agent_score' => null],
    ] as $i => $answer) {
        ConversationRating::query()->forceCreate($base + $answer + [
            'episode_event_id' => 1000 + $i,
            'template_key' => 'starter', 'template_version' => 1,
            'agent_user_id' => $ada->id, 'agent_name' => 'Ada',
        ]);
    }

    // A legacy row: overall feedback only, never an agent result.
    ConversationRating::query()->forceCreate($base + ['episode_event_id' => 2000, 'score' => 'good']);

    $owner = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Owner]);
    $report = new SupportReport(ReportingScope::for($w['account'], $owner), ReportingWindow::ofDays(7));

    expect($report->agentSatisfaction())->toBe([[
        'agent_user_id' => $ada->id,
        'name' => 'Ada',
        'good' => 1, 'ok' => 1, 'bad' => 0, 'not_applicable' => 1, 'skipped' => 1,
        'answered' => 2,
        'applicable' => 4,
        'positive' => 50.0,
    ]]);

    // The overall figure still counts every overall answer, and only those.
    expect($report->satisfaction())->toMatchArray(['good' => 3, 'bad' => 2, 'answered' => 5]);
});

test('the site settings choose the template and preview its questions', function (): void {
    $w = agentRatingWorld('overall');
    $owner = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->get(route('dashboard.sites.show', $w['site']))
        ->assertOk()
        ->assertSee('data-rating-template-preview="starter"', escape: false)
        ->assertSee('How was the help you received from [agent name]?');

    $this->actingAs($owner)
        ->put(route('dashboard.sites.rating.update', $w['site']), ['rating_enabled' => '1', 'rating_template' => 'starter'])
        ->assertRedirect();

    expect(SiteRatingPrompt::for($w['site']->fresh())->template->key)->toBe('starter');

    $this->actingAs($owner)
        ->put(route('dashboard.sites.rating.update', $w['site']), ['rating_enabled' => '1', 'rating_template' => 'five-stars'])
        ->assertSessionHasErrors('rating_template');
});

test('the reports page shows agent results apart from the overall figure, names escaped', function (): void {
    $w = agentRatingWorld();
    $owner = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Owner]);
    $closedAt = now()->subDay()->startOfHour();

    ConversationRating::query()->forceCreate([
        'conversation_id' => $w['conversation']->id, 'site_id' => $w['site']->id,
        'episode_event_id' => 3000, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt,
        'score' => 'good', 'comment' => 'Quick and kind.',
        'template_key' => 'starter', 'template_version' => 1,
        'agent_user_id' => null, 'agent_name' => '<b>Ada</b>', 'agent_score' => 'not_applicable',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertSee('id="report-agent-satisfaction-heading"', escape: false)
        ->assertSee('&lt;b&gt;Ada&lt;/b&gt;', escape: false)
        ->assertDontSee('<b>Ada</b>', escape: false)
        // Not applicable is a count, so this agent has no score to show.
        ->assertSeeText('No scores yet')
        ->assertSeeText('0 of 1 asked')
        // And the comment row says what was said about the agent.
        ->assertSeeText('Not applicable');
});

test('no agent section appears where no response was ever asked about an agent', function (): void {
    $w = agentRatingWorld('overall');
    $owner = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Owner]);
    $closedAt = now()->subDay()->startOfHour();

    ConversationRating::query()->forceCreate([
        'conversation_id' => $w['conversation']->id, 'site_id' => $w['site']->id,
        'episode_event_id' => 3001, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt, 'score' => 'bad',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard.reports.index'))
        ->assertOk()
        ->assertDontSee('id="report-agent-satisfaction-heading"', escape: false);
});

test('the reply that reopens a conversation belongs to the episode it started', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create();
    $bob = User::factory()->for($w['account'])->create();
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    agentRatingClose($w['conversation'], '2026-11-01 10:10:00');

    // Bob's reply is written first and reopens the conversation a second
    // later: the reply precedes the reopen it caused.
    agentRatingReply($w['conversation'], $bob, '2026-11-01 11:00:00');
    agentRatingReopen($w['conversation'], '2026-11-01 11:00:01');
    $second = agentRatingClose($w['conversation'], '2026-11-01 11:10:00');

    expect($w['conversation']->ratingEpisodeAgent($second)?->id)->toBe($bob->id);
});

test('a deleted agent keeps the overall answer and drops only the agent answer', function (): void {
    $w = agentRatingWorld();
    $ada = User::factory()->for($w['account'])->create(['name' => 'Ada']);
    agentRatingReply($w['conversation'], $ada, '2026-11-01 10:05:00');
    $close = agentRatingClose($w['conversation'], '2026-11-01 10:10:00');
    $token = agentRatingToken($this, $w['conversation']);
    $handle = Conversation::ratingAgentToken($close, $ada);

    // The account goes while the prompt naming them is still open.
    $ada->delete();

    agentRatingPost($this, $token, ['score' => 'bad', 'comment' => 'Slow.', 'agent' => $handle, 'agent_score' => 'good'])->assertCreated();

    expect(ConversationRating::query()->firstOrFail()->only(['score', 'comment', 'agent_user_id', 'agent_name', 'agent_score']))
        ->toBe(['score' => 'bad', 'comment' => 'Slow.', 'agent_user_id' => null, 'agent_name' => null, 'agent_score' => null]);

    // A handle for anybody else is still refused.
    agentRatingPost($this, $token, ['score' => 'bad', 'agent' => 'not-a-real-handle', 'agent_score' => 'good'])
        ->assertUnprocessable()->assertJsonValidationErrors('agent');
});

test('deleting accounts never merges two agents, and a rename never splits one', function (): void {
    $w = agentRatingWorld();
    $owner = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Owner]);
    $first = User::factory()->for($w['account'])->create(['name' => 'Sam']);
    $second = User::factory()->for($w['account'])->create(['name' => 'Sam']);
    $renamed = User::factory()->for($w['account'])->create(['name' => 'Robin']);
    $closedAt = now()->subDay()->startOfHour();
    $event = 4000;
    $answer = function (User $agent, string $name, string $score) use ($w, $closedAt, &$event): void {
        ConversationRating::query()->forceCreate([
            'conversation_id' => $w['conversation']->id, 'site_id' => $w['site']->id,
            'episode_event_id' => $event++, 'episode_closed_at' => $closedAt, 'rated_at' => $closedAt,
            'score' => 'good', 'template_key' => 'starter', 'template_version' => 1,
            'agent_user_id' => $agent->id, 'agent_name' => $name, 'rated_agent_id' => $agent->id, 'agent_score' => $score,
        ]);
    };

    $answer($first, 'Sam', 'good');
    $answer($second, 'Sam', 'bad');
    $answer($renamed, 'Robin', 'good');
    $answer($renamed, 'Robin B.', 'ok');

    // Both Sams' accounts go, clearing the foreign key on every row.
    $first->delete();
    $second->delete();

    $rows = (new SupportReport(ReportingScope::for($w['account'], $owner), ReportingWindow::ofDays(7)))->agentSatisfaction();
    $byName = collect($rows)->groupBy('name')->map->count();

    expect($rows)->toHaveCount(3)
        ->and($byName['Sam'])->toBe(2)
        ->and(collect($rows)->firstWhere('good', 1)['answered'] ?? null)->toBe(1)
        ->and(collect($rows)->first(fn ($row) => in_array($row['name'], ['Robin', 'Robin B.'], true))['answered'])->toBe(2);
});
