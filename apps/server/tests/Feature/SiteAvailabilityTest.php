<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\SiteAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithHours(array $availability = []): Site
{
    return Site::factory()->create([
        'settings' => ['availability' => array_replace([
            'enabled' => true,
            'timezone' => 'Europe/London',
            'weekdays' => [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '17:00'],
                'sat' => null,
                'sun' => null,
            ],
            'away_message' => 'We are closed. Leave a message and we will reply Monday.',
        ], $availability)],
    ]);
}

test('a site with no availability configured stays open, as it was before this existed', function (): void {
    // The dangerous default. If absence read as "closed", enabling this feature
    // would shut every existing desk on upgrade without anyone asking for it.
    $site = Site::factory()->create(['settings' => []]);

    $availability = SiteAvailability::for($site);

    expect($availability->scheduled)->toBeFalse()
        ->and($availability->open)->toBeTrue()
        ->and($availability->awayMessage)->toBeNull();
});

test('a configured desk is open inside its hours and closed outside them', function (): void {
    $site = siteWithHours();

    // Wednesday 10:30 London.
    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 10:30', 'Europe/London'))->open)->toBeTrue();

    // Same day, after closing.
    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 17:30', 'Europe/London'))->open)->toBeFalse();

    // Sunday, a day with no hours at all.
    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-30 12:00', 'Europe/London'))->open)->toBeFalse();
});

test('closing time is exclusive so the desk is shut on the dot', function (): void {
    $site = siteWithHours();

    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 16:59', 'Europe/London'))->open)->toBeTrue()
        ->and(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 17:00', 'Europe/London'))->open)->toBeFalse();
});

test('the schedule is read in the site timezone, not the server one', function (): void {
    // The server runs UTC; the desk is in Los Angeles. 17:00 UTC is 10:00 there,
    // which is open -- reading the server clock would call it closed.
    config(['app.timezone' => 'UTC']);
    $site = siteWithHours(['timezone' => 'America/Los_Angeles']);

    $availability = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 17:00', 'UTC'));

    expect($availability->open)->toBeTrue()
        ->and($availability->timezone)->toBe('America/Los_Angeles');
});

test('an away desk says when it opens next, across a closed weekend', function (): void {
    $site = siteWithHours();

    $availability = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-28 18:00', 'Europe/London'));

    // Friday evening -> Monday morning, skipping Saturday and Sunday entirely.
    expect($availability->open)->toBeFalse()
        ->and($availability->opensAt?->toDateTimeString())->toBe('2026-08-31 09:00:00')
        ->and($availability->awayMessage)->toContain('Leave a message');
});

test('a desk closed every day has no next opening rather than looping', function (): void {
    $site = siteWithHours(['weekdays' => array_fill_keys(SiteAvailability::DAYS, null)]);

    $availability = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 10:00', 'Europe/London'));

    expect($availability->open)->toBeFalse()
        ->and($availability->opensAt)->toBeNull();
});

test('a manual close overrides open hours and expires on its own', function (): void {
    // "Close the desk early" must not become a flag somebody forgets on Monday.
    $site = siteWithHours(['closed_until' => '2026-08-26T15:00:00+01:00']);

    $during = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 11:00', 'Europe/London'));
    expect($during->open)->toBeFalse()
        // The close ends inside Wednesday's hours, so the desk is back at 15:00
        // -- not tomorrow morning. Promising tomorrow while answering in four
        // hours sends the visitor away for nothing.
        ->and($during->opensAt?->toDateTimeString())->toBe('2026-08-26 15:00:00');

    // Past the override, the schedule takes over again with no further action.
    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 15:30', 'Europe/London'))->open)->toBeTrue();
});

test('malformed configuration closes the day rather than throwing at a visitor', function (): void {
    $site = siteWithHours(['weekdays' => [
        'mon' => ['nonsense', '17:00'],
        'tue' => ['17:00', '09:00'],
        'wed' => ['09:00', '17:00'],
    ], 'timezone' => 'Not/AZone']);

    // Monday is unparseable and Tuesday closes before it opens: both are closed,
    // and the bad timezone falls back rather than raising.
    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-24 12:00', 'UTC'))->open)->toBeFalse()
        ->and(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-25 12:00', 'UTC'))->open)->toBeFalse()
        ->and(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 12:00', 'UTC'))->open)->toBeTrue();
});

test('the payload tells the widget only what it needs', function (): void {
    $site = siteWithHours();

    $payload = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-30 12:00', 'Europe/London'))->toPayload();

    expect($payload)->toHaveKeys(['away', 'message', 'opens_at', 'timezone'])
        ->and($payload['away'])->toBeTrue()
        ->and($payload['opens_at'])->toStartWith('2026-08-31T09:00');
});

test('the widget bootstrap carries the away state', function (): void {
    $site = siteWithHours();
    $this->travelTo(CarbonImmutable::parse('2026-08-30 12:00', 'Europe/London'));

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-availability-away',
    ])->assertSuccessful();

    $availability = $response->json('data.site.availability');

    expect($availability['away'])->toBeTrue()
        ->and($availability['message'])->toContain('Leave a message')
        ->and($availability['opens_at'])->toStartWith('2026-08-31T09:00')
        // The schedule itself stays server-side: a visitor is told the desk is
        // away and when it returns, not the whole week's opening hours.
        ->and($availability)->not->toHaveKey('weekdays');
});

test('an unscheduled site bootstraps as available, so nothing changes on upgrade', function (): void {
    $site = Site::factory()->create(['settings' => []]);

    $availability = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-availability-open',
    ])->assertSuccessful()->json('data.site.availability');

    expect($availability['away'])->toBeFalse()
        ->and($availability['message'])->toBeNull();
});

test('an admin can set support hours and the widget sees them', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->put(route('dashboard.sites.availability.update', $site), [
        'availability_enabled' => '1',
        'availability_timezone' => 'Europe/London',
        'availability_away_message' => 'Closed. Back Monday.',
        'availability_open' => ['mon' => '1', 'tue' => '1'],
        'availability_from' => ['mon' => '09:00', 'tue' => '10:00'],
        'availability_to' => ['mon' => '17:00', 'tue' => '16:00'],
    ])->assertRedirect(route('dashboard.sites.show', $site));

    $stored = $site->fresh()->settings['availability'];

    expect($stored['enabled'])->toBeTrue()
        ->and($stored['weekdays']['mon'])->toBe(['09:00', '17:00'])
        ->and($stored['weekdays']['wed'])->toBeNull()
        ->and($stored['away_message'])->toBe('Closed. Back Monday.');
});

test('a day whose end is not after its start is stored closed, not silently open', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->put(route('dashboard.sites.availability.update', $site), [
        'availability_enabled' => '1',
        'availability_timezone' => 'UTC',
        'availability_open' => ['mon' => '1'],
        'availability_from' => ['mon' => '17:00'],
        'availability_to' => ['mon' => '09:00'],
    ])->assertRedirect();

    expect($site->fresh()->settings['availability']['weekdays']['mon'])->toBeNull();
});

test('editing the schedule does not reopen a desk somebody closed early', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['availability' => ['enabled' => true, 'closed_until' => '2026-09-01T09:00:00+00:00']],
    ]);

    $this->actingAs($admin)->put(route('dashboard.sites.availability.update', $site), [
        'availability_enabled' => '1',
        'availability_timezone' => 'UTC',
        'availability_open' => ['mon' => '1'],
        'availability_from' => ['mon' => '09:00'],
        'availability_to' => ['mon' => '17:00'],
    ])->assertRedirect();

    expect($site->fresh()->settings['availability']['closed_until'])->toBe('2026-09-01T09:00:00+00:00');
});

test('a plain agent cannot set support hours', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($agent)->put(route('dashboard.sites.availability.update', $site), [
        'availability_timezone' => 'UTC',
    ])->assertForbidden();

    expect($site->fresh()->settings['availability'] ?? null)->toBeNull();
});

test('an unknown timezone is rejected rather than stored', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->put(route('dashboard.sites.availability.update', $site), [
        'availability_enabled' => '1',
        'availability_timezone' => 'Not/AZone',
    ])->assertSessionHasErrors('availability_timezone');
});

test('the site page shows the desk state and the form', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('When the desk is open')
        ->assertSee('Always open')
        ->assertSee('name="availability_timezone"', false)
        ->assertSee('name="availability_open[mon]"', false);
});

test('a manual close ending after hours waits for the next scheduled opening', function (): void {
    // The other half of the same rule: an expiry outside open hours cannot be
    // the reopening, because nobody is there at that moment either.
    $site = siteWithHours(['closed_until' => '2026-08-26T21:00:00+01:00']);

    $availability = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 11:00', 'Europe/London'));

    expect($availability->open)->toBeFalse()
        ->and($availability->opensAt?->toDateTimeString())->toBe('2026-08-27 09:00:00');
});

test('a manual close ending exactly at opening time reopens then, not a day later', function (): void {
    $site = siteWithHours(['closed_until' => '2026-08-27T09:00:00+01:00']);

    $availability = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 11:00', 'Europe/London'));

    expect($availability->opensAt?->toDateTimeString())->toBe('2026-08-27 09:00:00');
});

test('clearing a day survives a validation error on another field', function (): void {
    // An unchecked box sends nothing, so old() fell back to the SAVED value and
    // re-checked what the operator had just cleared. Correcting the visible
    // error and resubmitting then silently kept the day open.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['availability' => [
            'enabled' => true,
            'timezone' => 'UTC',
            'weekdays' => ['mon' => ['09:00', '17:00'], 'tue' => ['09:00', '17:00']],
        ]],
    ]);

    $response = $this->actingAs($admin)
        ->from(route('dashboard.sites.show', $site))
        ->put(route('dashboard.sites.availability.update', $site), [
            'availability_enabled' => '1',
            'availability_timezone' => 'UTC',
            'availability_away_message' => str_repeat('x', 501),
            // Monday cleared: the hidden partner input is what carries that.
            'availability_open' => ['mon' => '0', 'tue' => '1'],
            'availability_from' => ['mon' => '09:00', 'tue' => '09:00'],
            'availability_to' => ['mon' => '17:00', 'tue' => '17:00'],
        ]);

    $response->assertSessionHasErrors('availability_away_message');

    // The re-rendered form must show Monday closed, not silently reopen it.
    $this->actingAs($admin)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk();

    expect(old('availability_open.mon'))->not->toBe(true);
});

test('an explicit off actually closes the day', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => ['availability' => ['enabled' => true, 'weekdays' => ['mon' => ['09:00', '17:00']]]],
    ]);

    $this->actingAs($admin)->put(route('dashboard.sites.availability.update', $site), [
        'availability_enabled' => '0',
        'availability_timezone' => 'UTC',
        'availability_open' => ['mon' => '0'],
        'availability_from' => ['mon' => '09:00'],
        'availability_to' => ['mon' => '17:00'],
    ])->assertRedirect();

    $stored = $site->fresh()->settings['availability'];

    // "0" is a non-empty string; a naive cast keeps both of these true.
    expect($stored['enabled'])->toBeFalse()
        ->and($stored['weekdays']['mon'])->toBeNull();
});

test('the form submits an explicit off for every checkbox', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('<input type="hidden" name="availability_enabled" value="0">', false)
        ->assertSee('<input type="hidden" name="availability_open[mon]" value="0">', false);
});

// Closing the desk early: the mechanism has always been here and nothing could
// reach it. These cover the write path, and the one behaviour change the switch
// needed -- a desk with no schedule can now be closed too.

test('an unscheduled desk can still be closed, and reopens when the close expires', function (): void {
    // The case that needed the change. A site with no hours had no way to say
    // "stepping out", because the unscheduled branch never read closed_until.
    $site = Site::factory()->create(['settings' => ['availability' => [
        'closed_until' => '2026-08-26T15:00:00+00:00',
        'away_message' => 'Back shortly.',
    ]]]);

    $during = SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 11:00', 'UTC'));

    expect($during->open)->toBeFalse()
        ->and($during->scheduled)->toBeFalse('no hours were configured, and closing early must not invent any')
        // Nothing to hand back to, so it reopens exactly when it expires.
        ->and($during->opensAt?->toDateTimeString())->toBe('2026-08-26 15:00:00')
        ->and($during->awayMessage)->toBe('Back shortly.');

    expect(SiteAvailability::for($site, CarbonImmutable::parse('2026-08-26 15:30', 'UTC'))->open)->toBeTrue();
});

test('an unscheduled desk with no manual close is still always open', function (): void {
    // The guard on the change above: reading closed_until in that branch must
    // not turn absence of configuration into "closed".
    $site = Site::factory()->create(['settings' => ['availability' => ['timezone' => 'UTC']]]);

    expect(SiteAvailability::for($site)->open)->toBeTrue();
});

test('every closure preset ends on its own', function (): void {
    $site = siteWithHours();
    $wednesdayMorning = CarbonImmutable::parse('2026-08-26 11:00', 'Europe/London');

    $ends = fn (string $preset) => SiteAvailability::closureEndsAt($site, $preset, $wednesdayMorning)?->toDateTimeString();

    expect($ends('hour'))->toBe('2026-08-26 12:00:00')
        // The rest of today is today's CLOSING time, not midnight: the desk was
        // going to shut at 17:00 anyway.
        ->and($ends('today'))->toBe('2026-08-26 17:00:00')
        // And tomorrow means the next time anybody is actually there.
        ->and($ends('tomorrow'))->toBe('2026-08-27 09:00:00');
});

test('a closure preset never reaches backwards or past the weekend', function (): void {
    $site = siteWithHours();

    // Friday evening, already past closing. "The rest of today" has no working
    // day left to run to, so it falls to midnight rather than a time gone by.
    expect(SiteAvailability::closureEndsAt($site, 'today', CarbonImmutable::parse('2026-08-28 19:00', 'Europe/London'))?->toDateTimeString())
        ->toBe('2026-08-29 00:00:00');

    // "Until tomorrow" on a Friday means Monday, because Saturday has no hours.
    expect(SiteAvailability::closureEndsAt($site, 'tomorrow', CarbonImmutable::parse('2026-08-28 11:00', 'Europe/London'))?->toDateTimeString())
        ->toBe('2026-08-31 09:00:00');
});

test('an unscheduled desk falls back to plain midnight', function (): void {
    $site = Site::factory()->create(['settings' => ['availability' => ['timezone' => 'UTC']]]);
    $at = CarbonImmutable::parse('2026-08-26 11:00', 'UTC');

    expect(SiteAvailability::closureEndsAt($site, 'today', $at)?->toDateTimeString())->toBe('2026-08-27 00:00:00')
        ->and(SiteAvailability::closureEndsAt($site, 'tomorrow', $at)?->toDateTimeString())->toBe('2026-08-27 00:00:00')
        ->and(SiteAvailability::closureEndsAt($site, 'hour', $at)?->toDateTimeString())->toBe('2026-08-26 12:00:00');
});

test('a desk closed early asks a visitor for an email, exactly as being out of hours does', function (): void {
    // The point of the whole feature, and it comes free: both routes to "away"
    // meet at the same boolean, so a manual close promotes email to required
    // without intake knowing a manual close exists.
    //
    // Deliberately an UNSCHEDULED site, which could not be closed at all before
    // this slice -- so this covers the new path rather than the old one.
    $site = Site::factory()->create([
        'public_key' => 'site_public_shut',
        'settings' => [
            'availability' => ['closed_until' => CarbonImmutable::now()->addHour()->toIso8601String()],
            'intake' => ['fields' => ['name' => 'off', 'email' => 'off', 'reason' => 'off']],
        ],
    ]);

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_shut',
        'anonymous_id' => 'anon-shut-1',
    ]);

    $response->assertSuccessful();

    expect($response->json('data.site.availability.away'))->toBeTrue()
        ->and($response->json('data.site.intake.fields.email'))->toBe('required')
        ->and($response->json('data.site.intake.asks'))->toBeTrue();
});
test('an admin can close the desk early and reopen it', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => ['availability' => [
        'enabled' => true,
        'timezone' => 'UTC',
        'weekdays' => ['mon' => ['09:00', '17:00']],
    ]]]);

    $this->actingAs($admin)
        ->post(route('dashboard.sites.availability.close', $site), ['closure' => 'hour'])
        ->assertRedirect();

    expect($site->fresh()->settings['availability']['closed_until'])->not->toBeNull()
        // The schedule this action deliberately does not touch.
        ->and($site->fresh()->settings['availability']['weekdays']['mon'])->toBe(['09:00', '17:00'])
        ->and($site->fresh()->settings['availability']['enabled'])->toBeTrue();

    $this->actingAs($admin)
        ->delete(route('dashboard.sites.availability.reopen', $site))
        ->assertRedirect();

    expect($site->fresh()->settings['availability']['closed_until'])->toBeNull()
        ->and($site->fresh()->settings['availability']['weekdays']['mon'])->toBe(['09:00', '17:00']);
});

test('closing the desk does not blank a schedule that was never configured', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => ['mask_selectors' => []]]);

    $this->actingAs($admin)
        ->post(route('dashboard.sites.availability.close', $site), ['closure' => 'today'])
        ->assertRedirect();

    // Settings outside availability are somebody else's, and this action writes
    // one key.
    expect($site->fresh()->settings['mask_selectors'])->toBe([])
        ->and($site->fresh()->settings['availability']['closed_until'])->not->toBeNull();
});

test('an unknown closure length is rejected rather than stored', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)
        ->post(route('dashboard.sites.availability.close', $site), ['closure' => 'forever'])
        ->assertSessionHasErrors('closure');

    expect($site->fresh()->settings['availability']['closed_until'] ?? null)->toBeNull();
});

test('a plain agent cannot close or reopen the desk', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => ['availability' => [
        'closed_until' => '2026-09-01T09:00:00+00:00',
    ]]]);

    $this->actingAs($agent)
        ->post(route('dashboard.sites.availability.close', $site), ['closure' => 'hour'])
        ->assertForbidden();

    $this->actingAs($agent)
        ->delete(route('dashboard.sites.availability.reopen', $site))
        ->assertForbidden();

    // Neither refused request may have moved anything.
    expect($site->fresh()->settings['availability']['closed_until'])->toBe('2026-09-01T09:00:00+00:00');
});

test('the site page offers to close the desk, and to undo it', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($admin)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('Close the desk early without changing the schedule.')
        ->assertSee('name="closure" value="hour"', false)
        ->assertSee('Always open');

    $site->forceFill(['settings' => ['availability' => [
        'closed_until' => CarbonImmutable::now()->addHours(2)->toIso8601String(),
    ]]])->save();

    $this->actingAs($admin)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('Reopen now')
        // The pill said "Always open" about a desk somebody had just shut.
        ->assertSee('Closed early')
        ->assertDontSee('name="closure" value="hour"', false);
});
