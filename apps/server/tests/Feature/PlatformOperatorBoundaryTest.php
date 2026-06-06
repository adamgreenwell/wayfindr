<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('operator console requires authentication', function (): void {
    $this->get('/operator')
        ->assertRedirect('/login');
});

test('account roles do not grant platform operator access', function (AccountRole $accountRole): void {
    $agent = User::factory()->for(Account::factory())->create([
        'account_role' => $accountRole,
    ]);

    $this->actingAs($agent)
        ->get('/operator')
        ->assertForbidden();
})->with([
    'owner' => AccountRole::Owner,
    'admin' => AccountRole::Admin,
    'agent' => AccountRole::Agent,
]);

test('explicit platform operators can inspect the operator console', function (): void {
    $operator = User::factory()->for(Account::factory(['name' => 'Wayfindr Ops']))->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Operator console')
        ->assertSee('Instance readiness')
        ->assertSee('Recommended next step')
        ->assertSee('Post-install smoke path')
        ->assertSee('Send a widget smoke test')
        ->assertSee('Platform operator access does not grant support data access.');
});

test('platform operators can confirm manual readiness items from the operator console', function (): void {
    $account = Account::factory()->create(['name' => 'Wayfindr Ops']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);

    $this->actingAs($operator)
        ->post('/operator/readiness/confirmations', [
            'key' => 'backups_restore',
            'note' => 'Snapshot and database restore tested.',
        ])
        ->assertRedirect('/operator');

    $this->assertDatabaseHas('operator_readiness_confirmations', [
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'note' => 'Snapshot and database restore tested.',
    ]);

    $auditEvent = AuditEvent::query()
        ->where('action', 'operator_readiness.confirmed')
        ->firstOrFail();

    expect($auditEvent->account_id)
        ->toBe($account->id)
        ->and($auditEvent->actor_id)->toBe($operator->id)
        ->and($auditEvent->metadata)->toMatchArray([
            'key' => 'backups_restore',
            'note' => 'Snapshot and database restore tested.',
        ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Backups and restore confirmed.')
        ->assertSee('Confirmed by Olive Operator')
        ->assertSee('Snapshot and database restore tested.');
});

test('platform operators cannot confirm unsupported readiness keys', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->post('/operator/readiness/confirmations', [
            'key' => 'database_connection',
            'note' => 'Trying to override an automatic check.',
        ])
        ->assertInvalid('key');

    $this->assertDatabaseMissing('operator_readiness_confirmations', [
        'key' => 'database_connection',
    ]);
});

test('operator console shows safe system identity and documentation links', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    config([
        'app.debug' => false,
        'broadcasting.default' => 'reverb',
        'queue.default' => 'redis',
        'wayfindr.documentation.forge_url' => 'https://example.test/docs/forge',
        'wayfindr.documentation.runtime_requirements_url' => 'https://example.test/docs/runtime',
        'wayfindr.documentation.self_hosting_url' => 'https://example.test/docs/self-hosting',
        'wayfindr.release.commit' => 'abc1234',
        'wayfindr.release.version' => '0.1.0',
    ]);

    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('System identity')
        ->assertSee('Wayfindr version')
        ->assertSee('0.1.0')
        ->assertSee('Source revision')
        ->assertSee('abc1234')
        ->assertSee('Environment')
        ->assertSeeInOrder(['Environment', 'production', 'Debug mode'])
        ->assertSee('Debug mode')
        ->assertSee('Disabled')
        ->assertSee('PHP version')
        ->assertSee(PHP_VERSION)
        ->assertSee('Laravel version')
        ->assertSee(LaravelApplication::VERSION)
        ->assertSee('Queue driver')
        ->assertSee('redis')
        ->assertSee('Broadcast driver')
        ->assertSee('reverb')
        ->assertSee('Self-hosting docs')
        ->assertSee('https://example.test/docs/self-hosting', false)
        ->assertSee('Runtime requirements')
        ->assertSee('https://example.test/docs/runtime', false)
        ->assertSee('Forge deploy guide')
        ->assertSee('https://example.test/docs/forge', false);
});

test('deactivated platform operators cannot inspect the operator console', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
        'deactivated_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertRedirect('/login');
});
