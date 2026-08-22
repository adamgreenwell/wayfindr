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
        ->and($during->opensAt?->toDateTimeString())->toBe('2026-08-27 09:00:00');

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
