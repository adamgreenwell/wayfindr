<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
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
        ->assertDontSee(route('operator.dashboard'), false)
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

test('platform operators see the operator console in navigation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);

    $this->actingAs($operator)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Operator')
        ->assertSee(route('operator.dashboard'), false);
});

test('account admins reach reply templates and ticket labels from the account page', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Reply templates')
        ->assertSee(route('dashboard.account.reply-templates.index'), false)
        ->assertSee('Ticket labels')
        ->assertSee(route('dashboard.account.labels.index'), false);
});

test('plain agents do not see account management links', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Ada Agent',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertDontSee(route('dashboard.account.reply-templates.index'), false)
        ->assertDontSee(route('dashboard.account.labels.index'), false);
});

test('agent pages render the shared page header component', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($agent)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('page-header', false)
        ->assertSee('page-header__back', false)
        ->assertSee('Back to sites')
        ->assertSee('Acme Docs');
});
