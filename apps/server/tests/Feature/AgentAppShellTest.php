<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated agent pages share primary app navigation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'email' => 'ada@example.com',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-nav']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NAV123',
        'subject' => 'Navigation help',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('aria-label="Primary navigation"', false)
        ->assertSee('Dashboard')
        ->assertSee('Conversations')
        ->assertSee('Tickets')
        ->assertSee('Sites')
        ->assertDontSee('Readiness')
        ->assertSee('Account')
        ->assertSee('/dashboard/conversations', false)
        ->assertSee('/dashboard/tickets', false)
        ->assertDontSee('/dashboard#conversations', false)
        ->assertDontSee('/dashboard?ticket_status=open#tickets', false)
        ->assertSee('/dashboard/sites', false)
        ->assertDontSee('/dashboard/readiness', false)
        ->assertDontSee('/dashboard#sites', false)
        ->assertSee('/dashboard/account', false)
        ->assertSee('/dashboard/sites/new', false)
        ->assertSee('Ada Agent')
        ->assertSee('Acme Support')
        ->assertSee('Sign out');

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('aria-label="Primary navigation"', false)
        ->assertSee('/dashboard/conversations', false)
        ->assertSee('aria-current="page"', false);
});

test('agent pages include an active state for support filter chips', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'email' => 'ada@example.com',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('.filter-chip[aria-current="page"]', false);
});

test('account admins see operator readiness navigation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Readiness')
        ->assertSee('/dashboard/readiness', false);
});
