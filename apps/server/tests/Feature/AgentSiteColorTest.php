<?php

// Site colour (ADR 0014): one operator choice that has to reach the queues, the
// transcript, and the widget a visitor sees.

use App\Enums\AccountRole;
use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteColourOwner(Account $account): User
{
    return User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Ada Owner',
    ]);
}

test('a new site takes the next colour so one desk can tell its sites apart', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);

    // Six creations, because the sixth is the last before the palette repeats.
    foreach (range(1, 6) as $index) {
        $this->actingAs($owner)
            ->post(route('dashboard.sites.store'), ['name' => "Site {$index}"])
            ->assertRedirect();
    }

    $colors = Site::query()->where('account_id', $account->id)
        ->orderBy('id')->pluck('color')->map->value->all();

    expect($colors)->toBe(SiteColor::values());
});

test('colours restart only after the palette is exhausted', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);

    Site::factory()->count(count(SiteColor::cases()))->for($account)->create();

    $this->actingAs($owner)
        ->post(route('dashboard.sites.store'), ['name' => 'One too many'])
        ->assertRedirect();

    expect(Site::query()->where('name', 'One too many')->first()->color)
        ->toBe(SiteColor::Red);
});

test('one account filling its palette does not shift another account', function (): void {
    $busy = Account::factory()->create();
    Site::factory()->count(4)->for($busy)->create();

    $quiet = Account::factory()->create();
    $owner = siteColourOwner($quiet);

    $this->actingAs($owner)
        ->post(route('dashboard.sites.store'), ['name' => 'First quiet site'])
        ->assertRedirect();

    expect(Site::query()->where('name', 'First quiet site')->first()->color)
        ->toBe(SiteColor::Red);
});

test('a site with no stored colour still resolves to a stable one', function (): void {
    // Defensive: rows can predate the column, or arrive from a path that did
    // not assign one. Every surface needs an answer, and the same answer twice.
    $site = Site::factory()->create();
    $site->forceFill(['color' => null])->save();

    $resolved = $site->fresh()->resolvedColor();

    expect($resolved)->toBeInstanceOf(SiteColor::class)
        ->and($site->fresh()->resolvedColor())->toBe($resolved);
});

test('the site form offers every colour and marks the current one', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    $site = Site::factory()->for($account)->create(['color' => SiteColor::Pine]);

    $response = $this->actingAs($owner)->get(route('dashboard.sites.show', $site))->assertOk();

    foreach (SiteColor::cases() as $color) {
        $response->assertSee('value="'.$color->value.'"', false)
            ->assertSee('var(--wf-site-'.$color->value.')', false);
    }

    $response->assertSee('id="site_color_pine"', false);
});

test('an owner can recolour a site, and the change is audited', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'color' => SiteColor::Red,
    ]);

    $this->actingAs($owner)
        ->put(route('dashboard.sites.details.update', $site), [
            'name' => 'Acme Docs',
            'domain' => null,
            'color' => 'violet',
        ])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect($site->fresh()->color)->toBe(SiteColor::Violet);

    $audit = AuditEvent::query()->where('action', 'site.details_updated')->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['before']['color'])->toBe('red')
        ->and($audit->metadata['after']['color'])->toBe('violet');
});

test('a colour outside the palette is rejected', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    $site = Site::factory()->for($account)->create(['color' => SiteColor::Red]);

    // The stored key is interpolated into a CSS custom property name on three
    // surfaces, so this is an injection guard as well as a validation rule.
    $this->actingAs($owner)
        ->put(route('dashboard.sites.details.update', $site), [
            'name' => 'Acme Docs',
            'color' => 'red); background: url(https://evil.example',
        ])
        ->assertSessionHasErrors('color');

    expect($site->fresh()->color)->toBe(SiteColor::Red);
});

test('the sites list shows each site colour', function (): void {
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    Site::factory()->for($account)->create(['name' => 'Acme Docs', 'color' => SiteColor::Ochre]);

    $this->actingAs($owner)
        ->get(route('dashboard.sites.index'))
        ->assertOk()
        ->assertSee('--wf-row-site: var(--wf-site-ochre)', false);
});

test('the widget bootstrap carries the site colour as a token key', function (): void {
    $site = Site::factory()->create([
        'public_key' => 'site_public_colour',
        'color' => SiteColor::Ochre,
    ]);

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-colour-1',
    ]);

    // The KEY, never a hex value: the widget resolves it through
    // --wf-site-<key>, which is what lets the dark variant apply and lets an
    // operator recolour a site without redeploying a widget.
    $response->assertSuccessful()->assertJsonPath('data.site.color', 'ochre');

    expect($response->json('data.site.color'))->not->toStartWith('#');
});

test('a site with no stored colour still sends one to the widget', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_nullish']);
    $site->forceFill(['color' => null])->save();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-colour-2',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.site.color', $site->fresh()->resolvedColor()->value);
});

test('a details update that never mentions colour leaves it alone', function (): void {
    // updateDetails exists so one form cannot blank another's fields by
    // omission. Requiring a colour here would break that for every caller that
    // only wants to fix a typo in the name.
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    $site = Site::factory()->for($account)->create([
        'name' => 'Typo Sitte',
        'color' => SiteColor::Violet,
    ]);

    $this->actingAs($owner)
        ->put(route('dashboard.sites.details.update', $site), ['name' => 'Docs Site'])
        ->assertRedirect(route('dashboard.sites.show', $site));

    expect($site->fresh()->name)->toBe('Docs Site')
        ->and($site->fresh()->color)->toBe(SiteColor::Violet);
});
