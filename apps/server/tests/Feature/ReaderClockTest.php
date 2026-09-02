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
use Illuminate\Support\Facades\App;
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
    // 12-hour with the zone named, because that is what an English reader's
    // locale says -- the SEAM decides the shape now, not the call site.
    $response->assertSee('4:32 PM', escape: false);
    $response->assertDontSee('2:32 PM');

    expect($grant->fresh()->expires_at->setTimezone('UTC')->format('H:i'))
        ->toBe('14:32', 'storage must stay on UTC no matter who is reading');
});

it('leaves an agent who has chosen nothing on the install clock', function () {
    [$agent] = readerClockDeskWithGrant(null, CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC'));

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('2:32 PM', escape: false)
        ->assertDontSee('4:32 PM');
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
        ->assertSee('Aug 24, 2026 4:32 PM')
        ->assertDontSee('2026-08-24 16:32:00')
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

    // A default grant runs an hour: 15:00 UTC, which in Berlin is 17:00 --
    // rendered on an English reader's locale as 5:00 PM, with the zone named.
    // `toContain` is variadic on strings, so the reason goes in a comment
    // rather than a second argument -- passed there it becomes another needle.
    expect($status)->toContain('5:00 PM')
        ->and(str_contains((string) $status, '3:00 PM'))->toBeFalse();
});

/**
 * `DateTimeZone::listIdentifiers()` leaves out roughly 180 backward-compatible
 * aliases that PHP resolves perfectly well, and real configuration is full of
 * them. Refusing one produces no error anybody sees -- it drops that reader to
 * the fallback and renders their whole dashboard on a clock they never chose.
 */
it('accepts a zone the platform can resolve, alias or not', function () {
    foreach (['US/Eastern', 'Asia/Calcutta', 'Europe/Kiev'] as $alias) {
        expect(DashboardTimezone::normalise($alias))->toBe($alias)
            ->and(in_array($alias, DateTimeZone::listIdentifiers(), true))
            ->toBeFalse("{$alias} is no longer an alias; the test has stopped proving anything");
    }

    $agent = User::factory()->for(Account::factory())->create(['timezone' => 'US/Eastern']);

    // 14:32 UTC is 10:32 on the US east coast in August.
    expect(ReaderClock::moment(CarbonImmutable::create(2026, 8, 24, 14, 32, 0, 'UTC'), $agent)->format('H:i'))
        ->toBe('10:32');
});

it('keeps an alias on the profile form instead of silently reassigning it', function () {
    // The select offers canonical names only, so a stored alias has no option
    // to be selected. Left out, the browser selects whichever option comes
    // first and the next save moves the agent to a zone they never picked.
    $agent = User::factory()->for(Account::factory())->create(['timezone' => 'US/Eastern']);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('value="US/Eastern" selected', escape: false);

    // And it survives a round trip through the form.
    $this->actingAs($agent)
        ->put(route('dashboard.profile.update'), ['name' => 'Ada Agent', 'timezone' => 'US/Eastern'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($agent->fresh()->timezone)->toBe('US/Eastern');
});

it('offers canonical names only, not the aliases beside them', function () {
    $choices = DashboardTimezone::choices();

    expect($choices['Asia'])->toContain('Asia/Kolkata')
        ->and(in_array('Asia/Calcutta', $choices['Asia'], true))->toBeFalse()
        ->and(array_key_exists('US', $choices))->toBeFalse();

    // Unless one is already stored, in which case it is offered so it can be
    // kept -- and exactly once, however many times it is asked for.
    $withAlias = DashboardTimezone::choices('US/Eastern');

    expect($withAlias['US'])->toBe(['US/Eastern'])
        ->and(DashboardTimezone::choices('Asia/Kolkata')['Asia'])->toBe($choices['Asia']);
});

/**
 * A converted timestamp in an English month order still reads foreign. The
 * zone was only half of what the moment needed.
 */
it('writes a date the way the reader writes dates', function () {
    $at = CarbonImmutable::create(2026, 8, 24, 15, 5, 0, 'UTC');
    $germanBerliner = User::factory()->for(Account::factory())
        ->create(['timezone' => BERLIN, 'locale' => 'de']);

    App::setLocale('en');
    expect(ReaderClock::date($at))->toBe('Aug 24, 2026')
        ->and(ReaderClock::time($at))->toBe('3:05 PM')
        // A named reader brings their own language, so this does not follow
        // the ambient English above.
        ->and(ReaderClock::time($at, $germanBerliner))->toBe('17:05');

    App::setLocale('de');
    expect(ReaderClock::date($at))->toBe('24. Aug 2026');

    App::setLocale('it');
    expect(ReaderClock::date($at))->toBe('24 ago 2026');
});

it('names the clock on a time-bounded promise', function () {
    $at = CarbonImmutable::create(2026, 8, 24, 15, 5, 0, 'UTC');
    $berliner = User::factory()->for(Account::factory())->create(['timezone' => BERLIN]);

    App::setLocale('en');

    // "Read-only access until 17:05" with no clock named is a promise an agent
    // in another zone reads wrongly and cannot tell they have.
    expect(ReaderClock::timeWithZone($at, $berliner))->toBe('5:05 PM CEST')
        ->and(ReaderClock::timeWithZone($at))->toBe('3:05 PM UTC');
});

it('translates the month rather than reordering it, which is why isoFormat', function () {
    // `translatedFormat('M j, Y')` translates the month name and keeps the US
    // order: Italian gets `ago 24, 2026`. That is a worse answer than leaving
    // it English, because it looks translated.
    $at = CarbonImmutable::create(2026, 8, 24, 12, 0, 0, 'UTC');

    App::setLocale('it');

    expect(ReaderClock::date($at))->toBe('24 ago 2026')
        ->and(CarbonImmutable::instance($at)->locale('it')->translatedFormat('M j, Y'))->toBe('ago 24, 2026');
});
