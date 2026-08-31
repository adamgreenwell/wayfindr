<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\User;
use App\Support\DashboardTimezone;
use App\Support\ReaderClock;
use App\Support\Reporting\ReportingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Berlin is the worked example throughout because its offset is never zero:
 * +02:00 in August, +01:00 in January. A test that passed under a zone which
 * happened to sit on UTC would prove nothing at all.
 */
const BERLIN = 'Europe/Berlin';

/**
 * An account with a live break-glass grant, whose banner is the one place the
 * dashboard prints an absolute wall-clock time.
 *
 * @return array{0: User, 1: BreakGlassGrant}
 */
function readerClockDeskWithGrant(?string $timezone, CarbonImmutable $expiresAt): array
{
    // The banner only renders while the grant is live, so the clock has to sit
    // before the expiry we are asserting on.
    test()->travelTo($expiresAt->subMinutes(30));

    $account = Account::factory()->create();
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'timezone' => $timezone,
    ]);

    $grant = BreakGlassGrant::factory()
        ->activeFor($account, $operator)
        ->create(['expires_at' => $expiresAt]);

    return [$agent, $grant];
}

/**
 * The defect this whole seam exists to remove.
 *
 * Asserted on the RENDERED page rather than on `ReaderClock`, because the
 * helper being right is not the claim -- the claim is that an agent in Berlin
 * reads their own clock, and only the view can say whether it was used.
 */
it('renders an absolute time on the reader clock, not the storage clock', function () {
    // 14:32 UTC is 16:32 in Berlin: the exact pair from the issue.
    [$agent, $grant] = readerClockDeskWithGrant(BERLIN, CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC'));

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('16:32', escape: false);
    $response->assertDontSee('14:32');

    expect($grant->fresh()->expires_at->setTimezone('UTC')->format('H:i'))
        ->toBe('14:32', 'storage must stay on UTC no matter who is reading');
});

it('leaves an agent who has chosen nothing on the install clock', function () {
    [$agent] = readerClockDeskWithGrant(null, CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC'));

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('14:32', escape: false)
        ->assertDontSee('16:32');
});

/**
 * The bug the middleware draft would have shipped.
 *
 * A display setting that reaches `app.timezone` reaches storage with it,
 * because Laravel writes `created_at` through the same config value into a
 * column that carries no offset.
 */
it('never lets the reader clock reach what is written to the database', function () {
    [$agent] = readerClockDeskWithGrant(BERLIN, CarbonImmutable::now('UTC')->addHour());

    $this->actingAs($agent)->get(route('dashboard'))->assertOk();

    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');

    $written = User::factory()->create();
    $raw = DB::table('users')->where('id', $written->id)->value('created_at');

    // Within a minute of true UTC, and nowhere near Berlin's +02:00.
    expect(abs(CarbonImmutable::parse($raw, 'UTC')->diffInSeconds(CarbonImmutable::now('UTC'))))
        ->toBeLessThan(60, 'a row written while a Berlin agent was signed in stored their wall clock');
});

it('files a moment under the reader day, not the UTC day', function () {
    // 23:30 UTC on the 24th is already 01:30 on the 25th in Berlin.
    $lateNight = CarbonImmutable::create(2026, 8, 24, 23, 30, 0, 'UTC');

    $berlin = ReportingWindow::ofDays(7, BERLIN);
    $utc = ReportingWindow::ofDays(7, 'UTC');

    expect($berlin->bucketKey($lateNight))->toBe('2026-08-25')
        ->and($utc->bucketKey($lateNight))->toBe('2026-08-24');
});

it('cuts the report window on the reader midnight', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 24, 23, 30, 0, 'UTC'));

    $window = ReportingWindow::ofDays(7, BERLIN);

    // It is already the 25th in Berlin, so "today" is the 25th and the window
    // ends at the 25th's midnight there -- 22:00 UTC on the 25th.
    // Seven days inclusive of the 25th starts on the 19th; the 19th's Berlin
    // midnight is 22:00 UTC on the 18th.
    expect($window->start->toIso8601String())->toBe('2026-08-18T22:00:00+00:00')
        ->and($window->end->format('Y-m-d H:i'))->toBe('2026-08-25 21:59');

    $labels = array_map(fn (CarbonImmutable $day) => $day->format('Y-m-d'), $window->days());

    expect($labels)->toHaveCount(7)
        ->and($labels[6])->toBe('2026-08-25', 'the last bucket is the reader today');

    CarbonImmutable::setTestNow();
});

/**
 * `whereBetween` binds a datetime formatted in the instance's OWN zone, so a
 * boundary still carrying Berlin would shift every report query by the offset
 * while looking entirely correct in the constructor.
 */
it('keeps the window bounds bindable against UTC columns', function () {
    $window = ReportingWindow::ofDays(30, BERLIN);

    expect($window->start->getTimezone()->getName())->toBe('UTC')
        ->and($window->end->getTimezone()->getName())->toBe('UTC')
        ->and($window->zone)->toBe(BERLIN);
});

it('walks a DST change without losing the midnight', function () {
    // Europe/Berlin leaves DST on 2026-10-25; that local day is 25 hours long.
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 10, 26, 12, 0, 0, 'UTC'));

    $days = ReportingWindow::ofDays(7, BERLIN)->days();

    foreach ($days as $day) {
        expect($day->format('H:i'))->toBe('00:00', "{$day->toDateString()} did not start at midnight");
    }

    expect(array_map(fn (CarbonImmutable $d) => $d->toDateString(), $days))
        ->toBe(['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26']);

    CarbonImmutable::setTestNow();
});

it('refuses a timezone the platform does not know', function () {
    expect(DashboardTimezone::normalise('Mars/Olympus_Mons'))->toBeNull()
        ->and(DashboardTimezone::normalise('  '))->toBeNull()
        ->and(DashboardTimezone::normalise(['Europe/Berlin']))->toBeNull()
        ->and(DashboardTimezone::normalise(BERLIN))->toBe(BERLIN);

    // A stored value that stopped being a real zone falls back rather than
    // rendering an error page at whoever inherited it.
    $agent = User::factory()->create();
    DB::table('users')->where('id', $agent->id)->update(['timezone' => 'Europe/Atlantis']);

    expect(ReaderClock::zone($agent->fresh()))->toBe('UTC');
});

it('follows the install default when the reader has no preference', function () {
    Config::set('wayfindr.dashboard_timezone', 'Asia/Tokyo');

    expect(ReaderClock::zone(null))->toBe('Asia/Tokyo')
        ->and(ReaderClock::zone(User::factory()->create(['timezone' => null])))->toBe('Asia/Tokyo')
        ->and(ReaderClock::zone(User::factory()->create(['timezone' => BERLIN])))->toBe(BERLIN);
});

it('lets a caller outside a request name its own reader', function () {
    $berliner = User::factory()->create(['timezone' => BERLIN]);
    $moment = CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC');

    // No signed-in user at all -- a queued job's situation.
    expect(ReaderClock::moment($moment, $berliner)->format('H:i'))->toBe('16:32')
        ->and(ReaderClock::moment($moment)->format('H:i'))->toBe('14:32');
});

it('lets an agent choose a timezone and then stop having chosen one', function () {
    $agent = User::factory()->for(Account::factory())->create(['timezone' => null]);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'timezone' => BERLIN])
        ->assertRedirect();

    expect($agent->fresh()->timezone)->toBe(BERLIN);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'timezone' => ''])
        ->assertRedirect();

    expect($agent->fresh()->timezone)->toBeNull('an empty choice means follow the install, not UTC');
});

it('refuses a timezone the platform cannot resolve, rather than storing it', function () {
    $agent = User::factory()->for(Account::factory())->create(['timezone' => BERLIN]);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'timezone' => 'Mars/Olympus_Mons'])
        ->assertSessionHasErrors('timezone');

    expect($agent->fresh()->timezone)->toBe(BERLIN, 'a refused choice must not clear the good one');
});

it('offers every zone on the profile form, grouped to be findable', function () {
    $agent = User::factory()->for(Account::factory())->create(['timezone' => BERLIN]);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('<optgroup label="Europe">', escape: false)
        ->assertSee('value="Europe/Berlin" selected', escape: false);

    $choices = DashboardTimezone::choices();

    expect(array_keys($choices))->toContain('Europe', 'America', 'Asia')
        ->and($choices['Europe'])->toContain(BERLIN);
});

/**
 * The miss a first sweep of `resources/views/` cannot see: a controller that
 * formats a timestamp into a string before any view exists.
 */
it('renders the account audit list on the reader clock', function () {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'timezone' => BERLIN,
    ]);

    AuditEvent::query()->create([
        'account_id' => $account->id,
        'actor_type' => $admin->getMorphClass(),
        'actor_id' => $admin->id,
        'action' => 'site.created',
        'metadata' => [],
        'occurred_at' => CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC'),
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('2026-08-24 16:32:00')
        ->assertDontSee('2026-08-24 14:32:00');
});

/**
 * The report window's bounds are UTC because they are bound into SQL. That
 * makes them the wrong thing to print: for a reader west of UTC the end of
 * Aug 24 locally is 06:59 on Aug 25 UTC, so a chart label built from the raw
 * bound announces a day the chart does not cover.
 */
it('announces the report range on the reader calendar, not the UTC one', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 25, 3, 0, 0, 'UTC'));

    // 03:00 UTC on the 25th is still 20:00 on the 24th in Los Angeles.
    $window = ReportingWindow::ofDays(7, 'America/Los_Angeles');

    expect($window->endsOn()->toDateString())->toBe('2026-08-24')
        ->and($window->end->toDateString())->toBe('2026-08-25', 'the query bound stays UTC')
        ->and($window->startsOn()->toDateString())->toBe('2026-08-18');

    CarbonImmutable::setTestNow();
});

it('tells an approver the expiry on their own clock', function () {
    $account = Account::factory()->create();
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
        'timezone' => BERLIN,
    ]);

    $this->travelTo(CarbonImmutable::create(2026, 8, 24, 14, 0, 0, 'UTC'));

    $grant = BreakGlassGrant::factory()->create([
        'account_id' => $account->id,
        'requester_id' => $operator->id,
        'status' => BreakGlassGrant::STATUS_REQUESTED,
    ]);

    $this->actingAs($operator)
        ->post(route('operator.break-glass.approve', $grant))
        ->assertRedirect();

    $status = session('status');

    // A default grant runs an hour: 15:00 UTC, which in Berlin is 17:00.
    // `toContain` is variadic on strings, so the reason goes in a comment
    // rather than a second argument -- passed there it becomes another needle.
    expect($status)->toContain('17:00')
        ->and(str_contains((string) $status, '15:00'))->toBeFalse();
});
