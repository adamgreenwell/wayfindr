<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can create a new site for their account', function (): void {
    config()->set('app.url', 'https://support.example.test');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Add site')
        ->assertSee('/dashboard/sites/new', false);

    $this->actingAs($agent)
        ->get('/dashboard/sites/new')
        ->assertOk()
        ->assertSee('Add site')
        ->assertSee('Site name')
        ->assertSee('Domain');

    $response = $this->actingAs($agent)
        ->from('/dashboard/sites/new')
        ->post('/dashboard/sites', [
            'name' => 'Wayfindr Public Site',
            'domain' => 'https://wayfindr.cc/',
        ])
        ->assertRedirect();

    $site = Site::query()
        ->where('account_id', $account->id)
        ->where('name', 'Wayfindr Public Site')
        ->firstOrFail();

    expect($site->domain)->toBe('wayfindr.cc')
        ->and($site->public_key)->toStartWith('site_')
        ->and($site->settings)->toBe(['mask_selectors' => []]);

    $response->assertRedirect("/dashboard/sites/{$site->id}");

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Site created. Copy the install snippet to finish connecting it.')
        ->assertSee('Wayfindr Public Site')
        ->assertSee('wayfindr.cc')
        ->assertSee("data-wayfindr-site-key=&quot;{$site->public_key}&quot;", false);
});

test('site creation requires an agent account', function (): void {
    $agent = User::factory()->create(['account_id' => null]);

    $this->actingAs($agent)
        ->post('/dashboard/sites', [
            'name' => 'Detached Site',
            'domain' => 'example.test',
        ])
        ->assertForbidden();

    expect(Site::query()->count())->toBe(0);
});

test('agent can view their account site privacy settings without edit controls', function (): void {
    config()->set('app.url', 'http://localhost');
    config()->set('broadcasting.default', 'null');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'domain' => 'docs.example.test',
        'public_key' => 'site_public_docs',
        'settings' => [
            'mask_selectors' => ['[data-secret]', 'input[name="token"]'],
            'internal_note' => 'do not show this',
        ],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Acme Docs')
        ->assertSee('docs.example.test')
        ->assertSee('site_public_docs')
        ->assertSee('Install snippet')
        ->assertSee('Paste this before the closing')
        ->assertSee('&lt;script', false)
        ->assertSee('src=&quot;http://localhost/widget.js&quot;', false)
        ->assertSee('data-wayfindr-api-base-url=&quot;http://localhost&quot;', false)
        ->assertSee('data-wayfindr-site-key=&quot;site_public_docs&quot;', false)
        ->assertSee('Mask selectors')
        ->assertSee('[data-secret]')
        ->assertSee('input[name=&quot;token&quot;]', false)
        ->assertSee('data-wayfindr-mask')
        ->assertSee('data-wayfindr-private')
        ->assertSee('data-wayfindr-allow')
        ->assertSee('Retaining visitor-supplied data may create privacy, security, and legal obligations.')
        ->assertSee('Account owners and admins manage privacy settings.')
        ->assertDontSee('<textarea id="mask_selectors"', false)
        ->assertDontSee('Save privacy settings')
        ->assertDontSee('internal_note')
        ->assertDontSee('do not show this');
});

test('site install snippet includes public reverb configuration when realtime is enabled', function (): void {
    config()->set('app.url', 'https://support.example.test/');
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'public-reverb-key');
    config()->set('broadcasting.connections.reverb.options.host', 'support.example.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create([
        'public_key' => 'site_public_docs',
        'settings' => [
            'internal_note' => 'keep private',
        ],
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('src=&quot;https://js.pusher.com/8.3.0/pusher.min.js&quot;', false)
        ->assertSee('src=&quot;https://support.example.test/widget.js&quot;', false)
        ->assertSee('data-wayfindr-api-base-url=&quot;https://support.example.test&quot;', false)
        ->assertSee('data-wayfindr-site-key=&quot;site_public_docs&quot;', false)
        ->assertSee('data-wayfindr-reverb-app-key=&quot;public-reverb-key&quot;', false)
        ->assertSee('data-wayfindr-reverb-host=&quot;support.example.test&quot;', false)
        ->assertSee('data-wayfindr-reverb-port=&quot;443&quot;', false)
        ->assertSee('data-wayfindr-reverb-scheme=&quot;https&quot;', false)
        ->assertDontSee('keep private');
});

test('account admins can update mask selectors for their account site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => [
            'mask_selectors' => ['[data-old]'],
            'internal_note' => 'keep me',
        ],
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}", [
            'mask_selectors' => " [data-secret] \n\ninput[name=\"token\"]\n[data-secret]\n",
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHas('status', 'Site privacy settings saved.');

    expect($site->fresh()->settings)->toBe([
        'mask_selectors' => ['[data-secret]', 'input[name="token"]'],
        'internal_note' => 'keep me',
        'mask_terms' => [],
    ]);
});

test('account admins can configure extra sensitive field terms for their account site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create([
        'settings' => [
            'mask_selectors' => ['[data-secret]'],
        ],
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/sites/{$site->id}")
        ->put("/dashboard/sites/{$site->id}", [
            'mask_selectors' => '[data-secret]',
            'mask_terms' => " contraseña \n\nNHS number\ncontraseña\n",
        ])
        ->assertRedirect("/dashboard/sites/{$site->id}")
        ->assertSessionHas('status', 'Site privacy settings saved.');

    expect($site->fresh()->settings)->toBe([
        'mask_selectors' => ['[data-secret]'],
        'mask_terms' => ['contraseña', 'NHS number'],
    ]);
});

test('plain agents cannot update mask selectors for their account site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create([
        'settings' => [
            'mask_selectors' => ['[data-original]'],
        ],
    ]);
    $site->supportAgents()->attach($agent);

    $this->actingAs($agent)
        ->put("/dashboard/sites/{$site->id}", [
            'mask_selectors' => '[data-secret]',
        ])
        ->assertForbidden();

    expect($site->fresh()->settings['mask_selectors'])->toBe(['[data-original]']);
});

test('agent cannot view another account site settings', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();

    $this->actingAs($agent)
        ->get("/dashboard/sites/{$otherSite->id}")
        ->assertNotFound();
});

test('agent cannot update another account site settings', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create([
        'settings' => [
            'mask_selectors' => ['[data-original]'],
        ],
    ]);

    $this->actingAs($agent)
        ->put("/dashboard/sites/{$otherSite->id}", [
            'mask_selectors' => '[data-secret]',
        ])
        ->assertNotFound();

    expect($otherSite->fresh()->settings['mask_selectors'])->toBe(['[data-original]']);
});
