<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OperatorSetting;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Reporting\DurationSummary;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Declare when lifecycle recording became trustworthy.
 *
 * A migration stamps this once per install. In a test the migration runs at
 * "now" while fixtures are back-dated, which is the opposite of production, so
 * every test states the boundary it means instead of inheriting one.
 */
function recordingBeganAt(CarbonImmutable $at): void
{
    OperatorSetting::query()->updateOrCreate(
        ['key' => 'reporting.lifecycle_recording_began_at'],
        ['value' => $at->toDateTimeString()],
    );
}

function reportWorld(): array
{
    // No stamp: an install that held no conversations when recording began has
    // nothing predating the log, so everything is measurable. Tests about the
    // boundary stamp one.
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();

    return compact('account', 'site', 'agent', 'visitor');
}

function reportFor(array $world, int $days = 30, mixed $requestedSite = null): SupportReport
{
    return new SupportReport(
        ReportingScope::for($world['account'], $world['agent'], $requestedSite),
        ReportingWindow::ofDays($days),
    );
}

function conversationOpenedAt(array $world, CarbonImmutable $at, string $status = 'open'): Conversation
{
    return Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'status' => $status,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function agentReplyAt(Conversation $conversation, User $agent, CarbonImmutable $at): ConversationMessage
{
    return ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function lifecycleEventAt(Conversation $conversation, string $action, CarbonImmutable $at, ?array $metadata = null): void
{
    $conversation->auditEvents()->create([
        'account_id' => $conversation->site?->account_id,
        'site_id' => $conversation->site_id,
        'action' => $action,
        'metadata' => $metadata ?? ['previous_status' => 'open', 'actor' => 'agent'],
        'occurred_at' => $at,
    ]);
}

test('volume buckets every day in the window, including the quiet ones', function (): void {
    $world = reportWorld();

    conversationOpenedAt($world, CarbonImmutable::now()->subDays(2)->setTime(9, 0));
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(2)->setTime(14, 0));
    conversationOpenedAt($world, CarbonImmutable::now()->setTime(10, 0));

    $volume = reportFor($world, 7)->volume();

    // Seven buckets whether or not anything happened: a chart that omits quiet
    // days makes a weekend look like an afternoon.
    expect($volume['opened'])->toHaveCount(7)
        ->and($volume['opened_total'])->toBe(3)
        ->and($volume['opened'][CarbonImmutable::now()->subDays(2)->format('Y-m-d')])->toBe(2)
        ->and($volume['opened'][CarbonImmutable::now()->subDays(5)->format('Y-m-d')])->toBe(0);
});

test('conversations opened before the window are not counted in it', function (): void {
    $world = reportWorld();

    conversationOpenedAt($world, CarbonImmutable::now()->subDays(40));
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(3));

    expect(reportFor($world, 7)->volume()['opened_total'])->toBe(1)
        ->and(reportFor($world, 90)->volume()['opened_total'])->toBe(2);
});

test('first response is measured from the visitor opening the conversation', function (): void {
    $world = reportWorld();

    $quick = conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(9, 0));
    agentReplyAt($quick, $world['agent'], CarbonImmutable::now()->subDays(1)->setTime(9, 2));

    $slow = conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(11, 0));
    agentReplyAt($slow, $world['agent'], CarbonImmutable::now()->subDays(1)->setTime(13, 0));

    $response = reportFor($world)->firstResponse();

    expect($response['summary']->count)->toBe(2)
        ->and($response['awaiting'])->toBe(0)
        // Interpolated: the median of a two-minute wait and a two-hour one
        // sits between them. Nearest-rank would report two minutes and call a
        // desk healthy on the strength of its faster half.
        ->and($response['summary']->median)->toBe(3660)
        ->and($response['summary']->p90)->toBe(6492);
});

test('only the first agent reply counts, and only agent replies count', function (): void {
    $world = reportWorld();

    $conversation = conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(9, 0));

    // A visitor following up before anyone answers is not a response.
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $world['visitor']->id,
        'created_at' => CarbonImmutable::now()->subDays(1)->setTime(9, 1),
    ]);

    agentReplyAt($conversation, $world['agent'], CarbonImmutable::now()->subDays(1)->setTime(9, 5));
    agentReplyAt($conversation, $world['agent'], CarbonImmutable::now()->subDays(1)->setTime(9, 9));

    expect(reportFor($world)->firstResponse()['summary']->median)->toBe(300);
});

test('conversations still awaiting a first reply are counted, never folded in as zero', function (): void {
    $world = reportWorld();

    $answered = conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(9, 0));
    agentReplyAt($answered, $world['agent'], CarbonImmutable::now()->subDays(1)->setTime(9, 30));

    conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(10, 0));
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(1)->setTime(11, 0));

    $response = reportFor($world)->firstResponse();

    // A desk that answers a third of its conversations quickly and ignores the
    // rest must not report an excellent median with nothing beside it.
    expect($response['summary']->count)->toBe(1)
        ->and($response['summary']->median)->toBe(1800)
        ->and($response['awaiting'])->toBe(2);
});

test('a reclosed conversation is measured from its reopen, not its original open', function (): void {
    $world = reportWorld();

    $opened = CarbonImmutable::now()->subDays(5)->setTime(9, 0);
    $conversation = conversationOpenedAt($world, $opened, 'closed');

    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, $opened->addHour());
    lifecycleEventAt($conversation, ConversationLifecycleLog::REOPENED, $opened->addDay(), ['previous_status' => 'closed', 'actor' => 'visitor']);
    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, $opened->addDay()->addMinutes(30));

    $resolution = reportFor($world)->resolution();

    // One hour for the first stretch, thirty minutes for the second. Charging
    // the second close with the day between them would describe work nobody did.
    expect($resolution['summary']->count)->toBe(2)
        ->and($resolution['summary']->median)->toBe(2700)
        ->and($resolution['unmeasurable'])->toBe(0)
        ->and($resolution['reopened'])->toBe(1)
        ->and($resolution['reopened_by_visitor'])->toBe(1);
});

test('a close whose episode start predates recording is counted, not measured', function (): void {
    $world = reportWorld();

    // Recording became trustworthy five days ago. Anything older than that may
    // have been closed and reopened while nothing was writing it down.
    recordingBeganAt(CarbonImmutable::now()->subDays(5));

    $recent = conversationOpenedAt($world, CarbonImmutable::now()->subDays(4), 'closed');
    lifecycleEventAt($recent, ConversationLifecycleLog::CLOSED, CarbonImmutable::now()->subDays(4)->addHour());

    // Measuring this one from its creation would charge the close with eighty
    // days of work that was already finished, and quietly drag the median up.
    $ancient = conversationOpenedAt($world, CarbonImmutable::now()->subDays(80), 'closed');
    lifecycleEventAt($ancient, ConversationLifecycleLog::CLOSED, CarbonImmutable::now()->subDays(2));

    $resolution = reportFor($world, 7)->resolution();

    expect($resolution['summary']->count)->toBe(1)
        ->and($resolution['summary']->median)->toBe(3600)
        ->and($resolution['unmeasurable'])->toBe(1)
        // The close is still real, and still counted as volume.
        ->and(reportFor($world, 7)->volume()['closed_total'])->toBe(2);
});

test('a reopen makes an old conversation measurable again', function (): void {
    $world = reportWorld();

    recordingBeganAt(CarbonImmutable::now()->subDays(5));

    // Created long before recording, but reopened after it -- so the stretch
    // ending in this close is fully on the record even though the conversation
    // is not.
    $conversation = conversationOpenedAt($world, CarbonImmutable::now()->subDays(80), 'closed');
    lifecycleEventAt($conversation, ConversationLifecycleLog::REOPENED, CarbonImmutable::now()->subDays(3), ['previous_status' => 'closed', 'actor' => 'visitor']);
    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, CarbonImmutable::now()->subDays(3)->addMinutes(45));

    $resolution = reportFor($world, 7)->resolution();

    expect($resolution['summary']->count)->toBe(1)
        ->and($resolution['summary']->median)->toBe(2700)
        ->and($resolution['unmeasurable'])->toBe(0);
});

test('a reopen older than the window still starts the episode it began', function (): void {
    $world = reportWorld();

    $opened = CarbonImmutable::now()->subDays(40)->setTime(9, 0);
    $conversation = conversationOpenedAt($world, $opened, 'closed');

    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, $opened->addHour());
    // The reopen falls outside a seven-day window; the close falls inside it.
    lifecycleEventAt($conversation, ConversationLifecycleLog::REOPENED, CarbonImmutable::now()->subDays(3)->setTime(9, 0), ['previous_status' => 'closed', 'actor' => 'visitor']);
    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, CarbonImmutable::now()->subDays(3)->setTime(11, 0));

    $resolution = reportFor($world, 7)->resolution();

    // Two hours -- measured from the out-of-window reopen. Falling back to the
    // conversation's creation would report forty days.
    expect($resolution['summary']->count)->toBe(1)
        ->and($resolution['summary']->median)->toBe(7200);
});

test('agent activity attributes replies and closes, and survives a removed agent', function (): void {
    $world = reportWorld();
    $other = User::factory()->for($world['account'])->create(['account_role' => AccountRole::Agent, 'name' => 'Second Agent']);

    $conversation = conversationOpenedAt($world, CarbonImmutable::now()->subDays(2));
    agentReplyAt($conversation, $world['agent'], CarbonImmutable::now()->subDays(2)->addMinutes(5));
    agentReplyAt($conversation, $other, CarbonImmutable::now()->subDays(2)->addMinutes(9));
    agentReplyAt($conversation, $other, CarbonImmutable::now()->subDays(2)->addMinutes(12));

    $rows = reportFor($world)->agentActivity();

    expect($rows)->toHaveCount(2)
        // Busiest first, so an imbalance reads off the top of the table.
        ->and($rows[0]['name'])->toBe('Second Agent')
        ->and($rows[0]['replies'])->toBe(2)
        ->and($rows[1]['replies'])->toBe(1);
});

test('the report says when recording began, so a flat line is not read as calm', function (): void {
    $world = reportWorld();

    // An install with no stamp has no unrecorded past to warn about.
    expect(reportFor($world, 90)->historyBeganAt())->toBeNull()
        ->and(reportFor($world, 90)->historyIsPartial())->toBeFalse();

    recordingBeganAt(CarbonImmutable::now()->subDays(4));

    expect(reportFor($world, 30)->historyBeganAt()?->toDateString())
        ->toBe(CarbonImmutable::now()->subDays(4)->toDateString())
        // A thirty-day window reaches back past the start of recording, so the
        // page has to say the earlier part is unrecorded rather than quiet.
        ->and(reportFor($world, 30)->historyIsPartial())->toBeTrue()
        // A seven-day window still reaches past four days.
        ->and(reportFor($world, 7)->historyIsPartial())->toBeTrue();

    // Recording that started before the window covers the whole of it.
    recordingBeganAt(CarbonImmutable::now()->subDays(60));

    expect(reportFor($world, 30)->historyIsPartial())->toBeFalse();
});

test('duration summaries interpolate rather than flattering the faster half', function (): void {
    $summary = DurationSummary::fromSeconds([10, 20, 30, 40]);

    expect($summary->median)->toBe(25)
        ->and($summary->p90)->toBe(37)
        ->and(DurationSummary::fromSeconds([])->median)->toBeNull()
        ->and(DurationSummary::humanize(null))->toBe('--')
        ->and(DurationSummary::humanize(45))->toBe('45s')
        ->and(DurationSummary::humanize(3600))->toBe('1h')
        ->and(DurationSummary::humanize(8100))->toBe('2h 15m')
        ->and(DurationSummary::humanize(90000))->toBe('1d 1h');
});

test('another account is never counted, whatever the query string says', function (): void {
    $world = reportWorld();
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(2));

    $stranger = reportWorld();
    conversationOpenedAt($stranger, CarbonImmutable::now()->subDays(2));
    conversationOpenedAt($stranger, CarbonImmutable::now()->subDays(3));

    // The stranger has two conversations to this account's one, so a scoping
    // failure of any kind reports three.
    expect(reportFor($world)->volume()['opened_total'])->toBe(1)
        // A site id belonging to someone else is rejected and the report falls
        // back to this agent's own sites -- it never reaches across the account
        // boundary (ADR 0015: a supplied id must never widen scope).
        ->and(reportFor($world, 30, (string) $stranger['site']->id)->volume()['opened_total'])->toBe(1);
});

test('a site the agent does not support is not in their report', function (): void {
    $world = reportWorld();
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(2));

    // A second site in the same account, with an explicit roster this agent is
    // not on.
    $restricted = Site::factory()->for($world['account'])->create();
    $other = User::factory()->for($world['account'])->create(['account_role' => AccountRole::Agent]);
    $restricted->supportAgents()->attach($other->id);
    $restrictedVisitor = Visitor::factory()->for($restricted)->create();
    Conversation::factory()->for($restricted)->for($restrictedVisitor)->create([
        'status' => 'open',
        'created_at' => CarbonImmutable::now()->subDays(2),
    ]);

    // Two conversations exist in this account; only one is on a site this
    // agent supports.
    expect(reportFor($world)->volume()['opened_total'])->toBe(1)
        ->and(reportFor($world, 30, (string) $restricted->id)->volume()['opened_total'])->toBe(1);

    // The agent on the roster sees both: the restricted site because they are
    // on its roster, and the first site because a site with no roster at all is
    // open to every agent in the account. The gate is the roster's existence,
    // not the site's.
    $rosterWorld = ['account' => $world['account'], 'agent' => $other, 'site' => $restricted, 'visitor' => $restrictedVisitor];
    expect(reportFor($rosterWorld)->volume()['opened_total'])->toBe(2);
});

test('an archived site keeps its history, because archiving destroys nothing', function (): void {
    $world = reportWorld();
    conversationOpenedAt($world, CarbonImmutable::now()->subDays(2));

    $world['site']->forceFill(['archived_at' => CarbonImmutable::now()])->save();

    // Tidying a site out of service must not rewrite last month's numbers --
    // purging is the operation that removes history, and it is meant to.
    expect(reportFor($world)->volume()['opened_total'])->toBe(1);
});

test('a fresh install is left empty, because restore reads any row as populated', function (): void {
    // wayfindr:restore refuses to overwrite a non-empty database without
    // --force, and counts every table rather than just the content ones. A
    // migration that stamped the recording boundary unconditionally would leave
    // a brand-new install non-empty and break restore-into-a-fresh-install --
    // the disaster-recovery path.
    expect(OperatorSetting::query()->where('key', 'reporting.lifecycle_recording_began_at')->exists())
        ->toBeFalse();

    $world = reportWorld();

    // And with no stamp, nothing predates recording, so closes are measurable
    // rather than silently set aside.
    $conversation = conversationOpenedAt($world, CarbonImmutable::now()->subDays(3), 'closed');
    lifecycleEventAt($conversation, ConversationLifecycleLog::CLOSED, CarbonImmutable::now()->subDays(3)->addHour());

    expect(reportFor($world, 7)->resolution()['summary']->count)->toBe(1)
        ->and(reportFor($world, 7)->resolution()['unmeasurable'])->toBe(0);
});
