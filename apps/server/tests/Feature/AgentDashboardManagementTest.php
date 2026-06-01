<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard gives agents a clear place to manage their workspace', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Workspace shortcuts')
        ->assertSee('Profile and alerts')
        ->assertSee('/dashboard/profile', false)
        ->assertSee('Sites and widget installs')
        ->assertSee('/dashboard/sites', false)
        ->assertSee('Account and team')
        ->assertSee('/dashboard/account', false)
        ->assertDontSee('Admin command center')
        ->assertDontSee('Team and roles')
        ->assertDontSee('Audit log')
        ->assertDontSee('/dashboard/account/audit', false)
        ->assertDontSee('Operator readiness')
        ->assertDontSee('/dashboard/readiness', false);
});

test('dashboard gives account admins a command center for account administration', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Admin command center')
        ->assertSee('Team and roles')
        ->assertSee('/dashboard/account#agents', false)
        ->assertSee('Site access')
        ->assertSee('/dashboard/account#site-access-matrix', false)
        ->assertSee('Ticket labels')
        ->assertSee('/dashboard/account/labels', false)
        ->assertSee('Audit log')
        ->assertSee('/dashboard/account/audit', false)
        ->assertSee('Readiness checks')
        ->assertSee('/dashboard/readiness', false)
        ->assertSee('Add site')
        ->assertSee('/dashboard/sites/new', false);
});
