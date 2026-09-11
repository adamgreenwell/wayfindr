<?php

use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('guest is redirected from dashboard to login', function (): void {
    $this->get('/dashboard')
        ->assertRedirect('/login');
});

test('login form renders', function (): void {
    User::factory()->for(Account::factory())->create();

    $this->get('/login')
        ->assertOk()
        ->assertSee('Agent Login')
        ->assertSee('data-agent-push-guest-cleanup', false)
        ->assertDontSee('data-agent-push-ownership-guard', false);

    $source = file_get_contents(resource_path('views/components/agent-push-guest-cleanup.blade.php'));

    expect($source)
        ->toContain("navigator.serviceWorker.getRegistration('/wayfindr-sw.js')")
        ->toContain('registration.pushManager.getSubscription()')
        ->toContain('subscription.unsubscribe()')
        ->toContain('unsubscribe(subscription, 2)')
        ->toContain('unsubscribe(subscription, attemptsRemaining - 1)');
});

test('agent can log in and view account scoped dashboard', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);

    $agent = User::factory()->for($account)->create([
        'email' => 'agent@example.com',
        'password' => Hash::make('password'),
    ]);

    Site::factory()->for($account)->create(['name' => 'Acme Help']);
    Site::factory()->for($otherAccount)->create(['name' => 'Other Help']);

    $this->post('/login', [
        'email' => 'agent@example.com',
        'password' => 'password',
    ])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($agent);

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Acme Support');

    $this->get('/dashboard/sites')
        ->assertOk()
        ->assertSee('Acme Help')
        ->assertDontSee('Other Help');
});

test('logout ends the agent session', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});

test('logout removes only the current browsers push endpoint before ending the session', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);

    foreach (['current', 'other'] as $browser) {
        $agent->pushSubscriptions()->create([
            'endpoint' => "https://push.example.test/subscriptions/{$browser}",
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    $this->actingAs($agent)
        ->post('/logout', [
            'push_subscription_endpoint' => 'https://push.example.test/subscriptions/current',
        ])
        ->assertRedirect('/login');

    expect(AgentPushSubscription::withoutGlobalScopes()->pluck('endpoint')->all())
        ->toBe(['https://push.example.test/subscriptions/other'])
        ->and($agent->fresh()->alertPushEnabled())->toBeTrue();

    $this->assertGuest();
});

test('database seeder creates demo account agent and site', function (): void {
    $this->seed(DatabaseSeeder::class);

    $agent = User::query()->where('email', 'agent@example.com')->firstOrFail();

    expect($agent->account->name)->toBe('Demo Support Co')
        ->and(Hash::check('password', $agent->password))->toBeTrue();

    $this->assertDatabaseHas('sites', [
        'account_id' => $agent->account_id,
        'name' => 'Demo Site',
        'domain' => 'demo.test',
    ]);
});

test('repeated failed sign-ins from one source are throttled', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // Ten per minute per address. The eleventh is refused by the limiter rather
    // than by the credential check, which is the difference between "wrong
    // password" and "stop guessing".
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('login.store'), [
            'email' => $agent->email,
            'password' => 'wrong-'.$attempt,
        ]);
    }

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'wrong-again',
    ])->assertStatus(429);
});

test('throttling one address does not lock a real agent out from elsewhere', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // An attacker burns the per-IP bucket from their own address...
    for ($attempt = 0; $attempt < 11; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('login.store'), [
                'email' => $agent->email,
                'password' => 'wrong-'.$attempt,
            ]);
    }

    // ...and the agent still signs in from theirs. This is the assertion that
    // separates a rate limit from a lockout: an email-keyed bucket alone would
    // let anyone deny a named agent their own desk, which is why the email
    // limit is loose and the tight one is keyed on the source.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
        ->post(route('login.store'), [
            'email' => $agent->email,
            'password' => 'correct-horse-battery-staple',
        ])
        ->assertRedirect();

    $this->assertAuthenticatedAs($agent->fresh());
});

test('a correct password still signs in after a couple of fumbles', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // The everyday case the limit must never break: someone mistypes twice and
    // then gets it right.
    foreach (['nope', 'nope-again'] as $wrong) {
        $this->post(route('login.store'), ['email' => $agent->email, 'password' => $wrong]);
    }

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($agent->fresh());
});
