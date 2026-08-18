<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an admin can rename a site and change its domain', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Typo Sitte',
        'domain' => 'old.example.test',
    ]);

    $this->actingAs($admin)
        ->put("/dashboard/sites/{$site->id}/details", [
            'name' => 'Docs Site',
            'domain' => 'https://docs.example.test/install?utm=x',
        ])
        ->assertRedirect(route('dashboard.sites.show', $site));

    $site->refresh();

    expect($site->name)->toBe('Docs Site')
        // Normalized the same way site creation does, so the two entry points
        // cannot disagree about what a domain looks like.
        ->and($site->domain)->toBe('docs.example.test');

    $this->assertDatabaseHas('audit_events', [
        'site_id' => $site->id,
        'action' => 'site.details_updated',
    ]);
});

test('renaming a site leaves its public key and install untouched', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Before',
        'public_key' => 'site_public_stable',
    ]);

    $this->actingAs($admin)
        ->put("/dashboard/sites/{$site->id}/details", ['name' => 'After', 'domain' => null])
        ->assertRedirect();

    expect($site->fresh()->public_key)->toBe('site_public_stable');

    // The widget identifies a site by its key, so a rename must not interrupt a
    // live install.
    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_stable',
        'anonymous_id' => 'anon-after-rename',
    ])->assertCreated();
});

test('the details form does not blank the privacy settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Docs',
        'settings' => ['mask_selectors' => ['[data-secret]'], 'mask_terms' => ['NHS number']],
    ]);

    $this->actingAs($admin)
        ->put("/dashboard/sites/{$site->id}/details", ['name' => 'Docs Renamed', 'domain' => null])
        ->assertRedirect();

    expect($site->fresh()->settings['mask_selectors'])->toBe(['[data-secret]'])
        ->and($site->fresh()->settings['mask_terms'])->toBe(['NHS number']);
});

test('an agent cannot rename a site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['name' => 'Untouched']);

    $this->actingAs($agent)
        ->put("/dashboard/sites/{$site->id}/details", ['name' => 'Hijacked', 'domain' => null])
        ->assertForbidden();

    expect($site->fresh()->name)->toBe('Untouched');
});

test('a site name is required', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Keeps Its Name']);

    $this->actingAs($admin)
        ->put("/dashboard/sites/{$site->id}/details", ['name' => '', 'domain' => null])
        ->assertSessionHasErrors('name');

    expect($site->fresh()->name)->toBe('Keeps Its Name');
});
