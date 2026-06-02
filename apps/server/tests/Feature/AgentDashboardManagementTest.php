<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
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

test('dashboard shows a visitor support readiness checklist', function (): void {
    config([
        'broadcasting.default' => 'log',
        'queue.default' => 'sync',
    ]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $site = Site::factory()->for($account)->create([
        'settings' => ['mask_selectors' => []],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ready to support visitors?')
        ->assertSee('Connect a site')
        ->assertSee('Ready')
        ->assertSee('Confirm widget check-in')
        ->assertSee('Needs attention')
        ->assertSee('Configure privacy masking')
        ->assertSee('Set up realtime delivery')
        ->assertSee('Move queues out of sync mode')
        ->assertSee('Confirm scheduler job')
        ->assertSee('Manual check')
        ->assertSee('Run a first test conversation')
        ->assertSee('Open tester')
        ->assertSee('Ask an account owner or admin to add mask selectors before cobrowse is used with real visitors.')
        ->assertDontSee('Add selectors such as input[type="password"] and [data-wayfindr-mask].');

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-dashboard-ready',
        'last_seen_at' => now(),
    ]);

    $testVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'tester-site-ready',
    ]);

    Conversation::factory()->for($site)->for($testVisitor)->create([
        'subject' => 'Dashboard smoke test',
        'metadata' => ['started_page_url' => '/dashboard/sites/tester'],
    ]);

    $site->forceFill([
        'settings' => ['mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]']],
    ])->save();

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'wayfindr.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'queue.default' => 'database',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Ready to support visitors?')
        ->assertSee('6 ready')
        ->assertSee('0 need attention')
        ->assertSee('1 manual check')
        ->assertSee('Widget check-in is fresh.')
        ->assertSee('Privacy masking has selectors configured.')
        ->assertSee('Realtime delivery is configured.')
        ->assertSee('Queue driver is database.');
});
