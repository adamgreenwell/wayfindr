<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('site management mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $siteManagerRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageSites->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $siteManagerRole->id,
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Original site',
        'domain' => 'original.example.test',
        'inbound_address' => 'original@example.test',
        'archived_at' => $action === 'unarchive' ? now() : null,
        'settings' => [
            'locale' => 'en',
            'rating' => ['enabled' => false, 'intro' => null],
            'availability' => [
                'enabled' => true,
                'timezone' => 'UTC',
                'weekdays' => [],
                'away_message' => null,
                'closed_until' => null,
            ],
        ],
    ]);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageSites))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $before = $site->fresh()->getRawOriginal();

    $response = match ($action) {
        'rating' => $this->put(route('dashboard.sites.rating.update', $site), [
            'rating_enabled' => true,
            'rating_intro' => 'How did we do?',
        ]),
        'intake' => $this->put(route('dashboard.sites.intake.update', $site), [
            'intake_intro' => 'Tell us about yourself.',
        ]),
        'language' => $this->put(route('dashboard.sites.language.update', $site), [
            'widget_locale' => 'de',
        ]),
        'availability' => $this->put(route('dashboard.sites.availability.update', $site), [
            'availability_enabled' => true,
            'availability_timezone' => 'America/New_York',
        ]),
        'close availability' => $this->post(route('dashboard.sites.availability.close', $site), [
            'closure' => 'hour',
        ]),
        'reopen availability' => $this->delete(route('dashboard.sites.availability.reopen', $site)),
        'inbound address' => $this->put(route('dashboard.sites.inbound-address.update', $site), [
            'inbound_address' => 'changed@example.test',
        ]),
        'appearance' => $this->put(route('dashboard.sites.appearance.update', $site), [
            'widget_position' => 'left',
            'widget_accent' => '#123456',
        ]),
        'details' => $this->put(route('dashboard.sites.details.update', $site), [
            'name' => 'Changed site',
            'domain' => 'changed.example.test',
        ]),
        'archive' => $this->post(route('dashboard.sites.archive', $site)),
        'unarchive' => $this->post(route('dashboard.sites.unarchive', $site)),
    };

    $response->assertNotFound();

    expect($site->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('audit_events', 0);
})->with([
    'rating',
    'intake',
    'language',
    'availability',
    'close availability',
    'reopen availability',
    'inbound address',
    'appearance',
    'details',
    'archive',
    'unarchive',
]);
