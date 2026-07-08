<?php

use App\Models\Account;
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
        ->assertSee('Agent Login');
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
