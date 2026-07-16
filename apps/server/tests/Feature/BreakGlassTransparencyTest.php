<?php

// Account-visible transparency (ADR 0008, slice 4): while a break-glass grant
// is live, EVERY agent of the account sees it on the dashboard — prominence is
// the control that makes self-approval honest. Quiet accounts see nothing.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function breakGlassTransparencyWorld(): array
{
    $account = Account::factory()->create();
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    return compact('account', 'operator', 'site', 'agent');
}

test('every agent sees an active grant on the dashboard; admins get the review action', function (): void {
    $w = breakGlassTransparencyWorld();
    BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(['self_approved' => true]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Platform operator access is active')
        ->assertSee('read-only access to')
        ->assertSee('self-approved')
        ->assertDontSee('Review or revoke');

    $this->actingAs($w['operator']) // account owner
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Platform operator access is active')
        ->assertSee('Review or revoke');
});

test('a quiet account sees no banner', function (): void {
    $w = breakGlassTransparencyWorld();

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Platform operator access is active');
});

test('an overdue-but-unswept grant no longer shows as active', function (): void {
    $w = breakGlassTransparencyWorld();
    BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(['expires_at' => now()->subMinutes(2)]);

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Platform operator access is active');
});

test('another account\'s grant never surfaces on this dashboard', function (): void {
    $w = breakGlassTransparencyWorld();
    $foreignAccount = Account::factory()->create();
    $foreignOperator = User::factory()->for($foreignAccount)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    BreakGlassGrant::factory()
        ->activeFor($foreignAccount, $foreignOperator)
        ->create();

    $this->actingAs($w['agent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Platform operator access is active');
});
