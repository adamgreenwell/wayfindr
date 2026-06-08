<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\FirstRunState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('first run setup page is available before bootstrap data exists', function (): void {
    $this->get('/setup')
        ->assertOk()
        ->assertSee('Set up Wayfindr')
        ->assertSee('Create the first account, owner, and install site.')
        ->assertSee('account_name', false)
        ->assertSee('agent_email', false)
        ->assertSee('site_name', false);
});

test('login redirects empty installs to first run setup', function (): void {
    $this->get('/login')
        ->assertRedirect('/setup');
});

test('first run setup page remains available when bootstrap records are incomplete', function (): void {
    $account = Account::factory()->create(['name' => 'Half Built Support']);

    Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);

    $this->get('/login')
        ->assertRedirect('/setup');

    $this->get('/setup')
        ->assertOk()
        ->assertSee('Finish setting up Wayfindr')
        ->assertSee('Some first-run records already exist, but no account owner has been created yet.');
});

test('first run setup creates the owner account site and session', function (): void {
    $response = $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ]);

    $account = Account::query()->sole();
    $agent = User::query()->sole();
    $site = Site::query()->sole();

    $response
        ->assertRedirect("/dashboard/sites/{$site->id}#install-snippet")
        ->assertSessionHas('status', 'Wayfindr is ready. Copy the install snippet to connect your first site.');

    expect($account->name)->toBe('Acme Support')
        ->and($account->slug)->toBe('acme-support')
        ->and($agent->account_id)->toBe($account->id)
        ->and($agent->account_role)->toBe(AccountRole::Owner)
        ->and($agent->platform_role)->toBe(PlatformRole::Operator)
        ->and($agent->name)->toBe('Ada Agent')
        ->and($agent->email)->toBe('ada@example.com')
        ->and(Hash::check('correct-horse-battery-staple', $agent->password))->toBeTrue()
        ->and($site->account_id)->toBe($account->id)
        ->and($site->name)->toBe('Acme Docs')
        ->and($site->domain)->toBe('docs.example.test')
        ->and($site->public_key)->toStartWith('site_')
        ->and($site->settings)->toMatchArray([
            'mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]'],
        ]);

    expect($site->supportAgents()->whereKey($agent->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($agent);
});

test('first run setup claims incomplete bootstrap records without creating duplicates', function (): void {
    $account = Account::factory()->create([
        'name' => 'Half Built Support',
        'slug' => 'half-built-support',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);

    $response = $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'https://docs.example.test/install',
    ]);

    $agent = User::query()->sole();

    $response
        ->assertRedirect("/dashboard/sites/{$site->id}#install-snippet")
        ->assertSessionHas('status', 'Wayfindr is ready. Copy the install snippet to connect your first site.');

    expect(Account::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1)
        ->and($account->refresh()->name)->toBe('Acme Support')
        ->and($account->slug)->toBe('acme-support')
        ->and($site->refresh()->name)->toBe('Acme Docs')
        ->and($site->domain)->toBe('docs.example.test')
        ->and($agent->account_id)->toBe($account->id)
        ->and($agent->account_role)->toBe(AccountRole::Owner)
        ->and($agent->platform_role)->toBe(PlatformRole::Operator)
        ->and($site->supportAgents()->whereKey($agent->id)->exists())->toBeTrue();
});

test('first run setup rechecks setup state inside the recovery transaction', function (): void {
    $account = Account::factory()->create([
        'name' => 'Half Built Support',
        'slug' => 'half-built-support',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);
    $existingOwner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'platform_role' => PlatformRole::Operator,
        'email' => 'owner@example.com',
    ]);

    $this->app->instance(FirstRunState::class, new class extends FirstRunState
    {
        private int $checks = 0;

        public function needsSetup(): bool
        {
            $this->checks++;

            return $this->checks === 1 || parent::needsSetup();
        }
    });

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ])
        ->assertRedirect('/login');

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->is($existingOwner))->toBeTrue()
        ->and($account->refresh()->name)->toBe('Half Built Support')
        ->and($site->refresh()->name)->toBe('Half Built Docs')
        ->and($site->supportAgents()->count())->toBe(0);
});

test('first run setup handoff shows install guidance and operator readiness links', function (): void {
    config()->set('app.url', 'https://support.example.test');

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ]);

    $site = Site::query()->sole();

    $this->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Wayfindr is ready. Copy the install snippet to connect your first site.')
        ->assertSee('Install snippet')
        ->assertSee('Next steps')
        ->assertSee('Copy this snippet into docs.example.test.')
        ->assertSee('Visit the site and send a test message from the widget.')
        ->assertSee('Post-install smoke path')
        ->assertSee('Send a real email')
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com')
        ->assertSee('Confirm background workers')
        ->assertSee('php artisan queue:failed')
        ->assertSee('/operator', false)
        ->assertSee('Open operator console')
        ->assertSee('/dashboard/readiness', false)
        ->assertSee('data-wayfindr-site-key=&quot;'.$site->public_key.'&quot;', false);
});

test('first run setup is locked after bootstrap data exists', function (): void {
    User::factory()->for(Account::factory())->create();

    $this->get('/setup')
        ->assertRedirect('/login');

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
    ])
        ->assertRedirect('/login');

    expect(User::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(0);
});
