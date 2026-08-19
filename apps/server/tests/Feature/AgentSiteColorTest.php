<?php

// Site colour (ADR 0014): one operator choice that has to reach the queues, the
// transcript, and the widget a visitor sees.

use App\Enums\AccountRole;
use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
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

test('the first two sites an install ever has are different colours', function (): void {
    // The first-run site used to be created with no colour, so it resolved via
    // the id fallback while the allocator counted it as position 0 -- making
    // the operator's second site land on the same colour as their first.
    $this->post(route('setup.store'), [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Owner',
        'agent_email' => 'ada@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'site_name' => 'First site',
    ])->assertRedirect();

    $owner = User::query()->where('email', 'ada@example.test')->firstOrFail();

    $this->actingAs($owner)
        ->post(route('dashboard.sites.store'), ['name' => 'Second site'])
        ->assertRedirect();

    $colors = Site::query()->orderBy('id')->pluck('color')->map->value->all();

    expect($colors)->toHaveCount(2)
        ->and($colors[0])->not->toBeNull()
        ->and($colors[0])->not->toBe($colors[1]);
});

test('the realtime transcript refresh keeps the site rail', function (): void {
    // refreshTranscript() replaces the transcript as soon as realtime connects,
    // so omitting the colour here dropped the rail with no new message.
    $account = Account::factory()->create();
    $owner = siteColourOwner($account);
    $site = Site::factory()->for($account)->create(['color' => SiteColor::Rust]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REFRESH1',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Still here.',
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard.conversations.messages.index', ['supportCode' => $conversation->support_code]))
        ->assertOk()
        ->assertSee('--wf-conversation-site: var(--wf-site-rust)', false);
});

test('font URLs are generated under the application base path', function (): void {
    // An install mounted below an origin path is explicitly supported, and an
    // origin-root /fonts/... 404s there -- silently, into the system stack.
    // Asserting the generated form rather than forcing a root URL, which would
    // also move the routes this request depends on.
    $layout = (string) file_get_contents(
        base_path('resources/views/components/layouts/app.blade.php')
    );

    expect($layout)->toContain("asset('fonts/")
        ->and($layout)->not->toContain("url('/fonts/");

    $account = Account::factory()->create();

    $this->actingAs(siteColourOwner($account))
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(asset('fonts/IBMPlexSans-Regular.woff2'), false)
        ->assertDontSee("url('/fonts/", false);
});
