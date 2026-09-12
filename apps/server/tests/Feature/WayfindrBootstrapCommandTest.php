<?php

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('it creates the first account agent and site', function (): void {
    $this->artisan('wayfindr:bootstrap', [
        '--account' => 'Acme Support',
        '--name' => 'Ada Agent',
        '--email' => 'ada@example.com',
        '--password' => 'correct-horse-battery-staple',
        '--site' => 'Acme Docs',
        '--domain' => 'docs.example.test',
        '--site-public-key' => 'site_acme_public_key',
    ])
        ->expectsOutputToContain('Wayfindr is ready.')
        ->expectsOutputToContain('Agent email: ada@example.com')
        ->expectsOutputToContain('Site public key: site_acme_public_key')
        ->assertExitCode(0);

    $account = Account::query()->firstOrFail();
    $agent = User::query()->where('email', 'ada@example.com')->firstOrFail();
    $site = Site::query()->where('public_key', 'site_acme_public_key')->firstOrFail();

    expect($account->name)->toBe('Acme Support')
        ->and($account->slug)->toBe('acme-support')
        ->and($agent->account_id)->toBe($account->id)
        ->and($agent->platform_role)->toBe(PlatformRole::Operator)
        ->and($agent->name)->toBe('Ada Agent')
        ->and(Hash::check('correct-horse-battery-staple', $agent->password))->toBeTrue()
        ->and($site->account_id)->toBe($account->id)
        ->and($site->name)->toBe('Acme Docs')
        ->and($site->domain)->toBe('docs.example.test');
});

test('it requires an email address', function (): void {
    $this->artisan('wayfindr:bootstrap')
        ->expectsOutputToContain('Pass --email to create the first agent.')
        ->assertExitCode(1);
});

test('it refuses to run again without force', function (): void {
    Account::factory()->create();

    $this->artisan('wayfindr:bootstrap', [
        '--email' => 'ada@example.com',
    ])
        ->expectsOutputToContain('Wayfindr already has bootstrap data.')
        ->assertExitCode(1);
});

test('force allows bootstrap to create missing records', function (): void {
    Account::factory()->create(['name' => 'Existing Account']);

    $this->artisan('wayfindr:bootstrap', [
        '--force' => true,
        '--account' => 'Acme Support',
        '--name' => 'Ada Agent',
        '--email' => 'ada@example.com',
        '--password' => 'correct-horse-battery-staple',
        '--site' => 'Acme Docs',
        '--site-public-key' => 'site_acme_public_key',
    ])
        ->expectsOutputToContain('Wayfindr is ready.')
        ->assertExitCode(0);

    $this->assertDatabaseHas('accounts', ['slug' => 'acme-support']);
    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    $this->assertDatabaseHas('sites', ['public_key' => 'site_acme_public_key']);
});

test('it creates an additional agent for an existing account', function (): void {
    $account = Account::factory()->create([
        'name' => 'Acme Support',
        'slug' => 'acme-support',
    ]);

    $this->artisan('wayfindr:agent', [
        '--account' => 'acme-support',
        '--name' => 'Bea Builder',
        '--email' => 'bea@example.com',
        '--password' => 'correct-horse-battery-staple',
    ])
        ->expectsOutputToContain('Agent ready.')
        ->expectsOutputToContain('Account: Acme Support')
        ->expectsOutputToContain('Agent email: bea@example.com')
        ->expectsOutputToContain('Agent password: [provided]')
        ->assertExitCode(0);

    $agent = User::query()->where('email', 'bea@example.com')->firstOrFail();

    expect($agent->account_id)->toBe($account->id)
        ->and($agent->name)->toBe('Bea Builder')
        ->and(Hash::check('correct-horse-battery-staple', $agent->password))->toBeTrue();
});

test('it can infer the account when only one account exists', function (): void {
    $account = Account::factory()->create([
        'name' => 'Acme Support',
        'slug' => 'acme-support',
    ]);

    $this->artisan('wayfindr:agent', [
        '--name' => 'Bea Builder',
        '--email' => 'bea@example.com',
        '--password' => 'correct-horse-battery-staple',
    ])
        ->expectsOutputToContain('Agent ready.')
        ->expectsOutputToContain('Account: Acme Support')
        ->assertExitCode(0);

    expect(User::query()->where('email', 'bea@example.com')->firstOrFail()->account_id)->toBe($account->id);
});

test('it requires an account option when multiple accounts exist', function (): void {
    Account::factory()->create(['slug' => 'acme-support']);
    Account::factory()->create(['slug' => 'other-support']);

    $this->artisan('wayfindr:agent', [
        '--email' => 'bea@example.com',
    ])
        ->expectsOutputToContain('Pass --account with an account slug or ID.')
        ->assertExitCode(1);
});

test('it refuses to create an agent for an unknown account', function (): void {
    Account::factory()->create(['slug' => 'acme-support']);

    $this->artisan('wayfindr:agent', [
        '--account' => 'missing-account',
        '--email' => 'bea@example.com',
    ])
        ->expectsOutputToContain('No matching account was found.')
        ->assertExitCode(1);
});

test('a supplied password meets the same minimum as every other path', function (): void {
    // The one path into this product that never met a validator. Registering a
    // shared rule only reaches code that CALLS PasswordRule::defaults(); these
    // commands hashed --password directly, on the most privileged credential an
    // install has.
    $this->artisan('wayfindr:bootstrap', [
        '--email' => 'owner@example.test',
        '--password' => 'elevenchars',
    ])
        ->expectsOutputToContain('at least 12 characters')
        ->assertFailed();

    expect(User::count())->toBe(0);

    // The generated branch makes 24 characters, so it was never the problem and
    // must keep working without a password being supplied at all.
    $this->artisan('wayfindr:bootstrap', ['--email' => 'owner@example.test'])
        ->assertSuccessful();

    expect(User::count())->toBe(1);
});

test('a supplied agent password meets the same minimum', function (): void {
    $this->artisan('wayfindr:bootstrap', ['--email' => 'owner@example.test'])
        ->assertSuccessful();

    $before = User::count();

    $this->artisan('wayfindr:agent', [
        '--email' => 'agent@example.test',
        '--password' => 'elevenchars',
    ])
        ->expectsOutputToContain('at least 12 characters')
        ->assertFailed();

    expect(User::count())->toBe($before);
});
