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

    // Ten failures against this address from this source exhausts the tighter
    // of the two buckets. The eleventh is refused by the throttle rather than
    // by the credential check.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->post(route('login.store'), [
            'email' => $agent->email,
            'password' => 'wrong-'.$attempt,
        ])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('one bad client on a shared address does not lock out its colleagues', function (): void {
    // The case the previous version missed. Its office test only had agents
    // SUCCEED, so a per-source bucket was never filled and the test passed
    // against a design that would refuse a colleague's correct password for
    // the whole window once one machine on the NAT had failed enough.
    $account = Account::factory()->create();
    $office = ['REMOTE_ADDR' => '198.51.100.10'];

    $victim = User::factory()->for($account)->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);
    $colleague = User::factory()->for($account)->create([
        'email' => 'grace@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // A compromised machine behind the office NAT sprays several addresses.
    // Spread ACROSS accounts on purpose: grinding one address just fills that
    // account's own bucket and stops, which masks a per-source bucket and is
    // why an earlier version of this test passed against one. Five failures
    // each stays under the per-account limit while putting thirty failures on
    // the shared address.
    foreach (['ada', 'grace', 'alan', 'edsger', 'barbara', 'donald'] as $target) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables($office)->post(route('login.store'), [
                'email' => $target.'@example.test',
                'password' => 'wrong-'.$attempt,
            ]);
        }
    }

    // Everyone else behind that same address signs in normally, because the
    // bucket is keyed to the account as well as the source.
    $this->withServerVariables($office)
        ->post(route('login.store'), [
            'email' => $colleague->email,
            'password' => 'correct-horse-battery-staple',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($colleague->fresh());
});

test('a shift signing in from one address is never throttled', function (): void {
    // Counted on failures rather than requests, so the number of colleagues
    // sharing an address is irrelevant.
    $account = Account::factory()->create();

    foreach (range(1, 25) as $n) {
        $agent = User::factory()->for($account)->create([
            'email' => "agent{$n}@example.test",
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->post(route('login.store'), [
                'email' => $agent->email,
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($agent->fresh());
        $this->post(route('logout'));
    }
});

test('an attacker cannot lock a named agent out of their own desk', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // The attacker exhausts every bucket their own source can reach.
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('login.store'), [
                'email' => $agent->email,
                'password' => 'wrong-'.$attempt,
            ]);
    }

    // The agent still signs in from theirs. Both keys carry the source, so
    // there is no bucket an attacker can spend on the agent's behalf.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])
        ->post(route('login.store'), [
            'email' => $agent->email,
            'password' => 'correct-horse-battery-staple',
        ])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($agent->fresh());
});

test('a correct password clears the failures that preceded it', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    // Nine fumbles, one short of the limit, then success -- which must reset
    // the count rather than leaving the agent one mistake from a lockout for
    // the rest of the window.
    for ($attempt = 0; $attempt < 9; $attempt++) {
        $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'nope-'.$attempt]);
    }

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    $this->post(route('logout'));

    // If the counter had survived, these two would exhaust it.
    foreach (['a', 'b'] as $wrong) {
        $this->post(route('login.store'), ['email' => $agent->email, 'password' => $wrong]);
    }

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($agent->fresh());
});

test('a long but valid address does not break the throttle key', function (): void {
    // CACHE_STORE defaults to `database`, whose key column is 255 characters.
    // An unhashed composite of a long address and the source exceeds it, and
    // the counter is written before the response returns -- so the insert is
    // rejected and the agent gets a 500 where they should get a login. The
    // array store the rest of the suite uses has no such column.
    config(['cache.default' => 'database']);

    // Every DNS label stays under 63 characters, so this is a genuinely valid
    // address rather than one the validator rejects before the throttle runs.
    $longEmail = str_repeat('a', 60).'@'
        .str_repeat('b', 60).'.'
        .str_repeat('c', 60).'.'
        .str_repeat('d', 50).'.example';

    expect(strlen($longEmail))->toBeGreaterThan(220);

    $agent = User::factory()->for(Account::factory())->create([
        'email' => $longEmail,
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $this->post(route('login.store'), [
        'email' => $longEmail,
        'password' => 'correct-horse-battery-staple',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($agent->fresh());
});
