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

test('account admins are not offered the instance readiness report', function (): void {
    // Readiness is an operator surface. The rail used to offer it to any
    // account admin, who could open it but could not act on anything in it.
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('/dashboard/readiness', false)
        ->assertDontSee('/operator', false);
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

// ── Shell rebuilt on the persistent rail (ADR 0014, step 3) ──────────────────

test('the rail groups daily work apart from configuration', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    // Nine equally weighted pills is what this replaces: "Conversations" and
    // "Operator" used to rank the same.
    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('wf-rail', false)
        ->assertSee('>Work</p>', false)
        ->assertSee('>Manage</p>', false)
        ->assertSeeInOrder(['>Work</p>', 'Conversations', '>Manage</p>', 'Sites'], false);
});

test('the breadcrumb names the account and the current section', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('aria-label="Breadcrumb"', false)
        ->assertSee('wf-crumb-current', false)
        ->assertSeeInOrder(['Acme Support', 'wf-crumb-current">Tickets'], false);
});

test('the breadcrumb falls back to the page title outside the rail', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    // Profile has no navigation item, so the page title is the honest answer.
    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('wf-crumb-current">Agent Profile', false);
});

test('every navigation item keeps a text label for assistive technology', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    // On narrow viewports the labels are visually hidden, NOT display:none.
    // Removing them outright leaves every rail link with no accessible name,
    // because the icon beside it is aria-hidden.
    $response = $this->actingAs($agent)->get('/dashboard')->assertOk();

    preg_match_all('/<a class="wf-nav-link".*?<\/a>/s', $response->getContent(), $matches);

    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $link) {
        expect($link)->toMatch('/<span>[^<]+<\/span>/');
    }
});

test('navigation items carry an icon that inherits colour', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('class="wf-icon"', false)
        ->assertSee('aria-hidden="true"', false)
        ->assertSee('stroke="currentColor"', false);
});

test('the theme control offers system, light and dark', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    // "system" must be an explicit option rather than merely the default:
    // choosing it is what clears a stored preference and hands control back
    // to the operating system.
    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-wf-theme-set="system"', false)
        ->assertSee('data-wf-theme-set="light"', false)
        ->assertSee('data-wf-theme-set="dark"', false)
        ->assertSee('aria-label="Colour theme"', false);
});

test('the stored theme is applied before the page paints', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $content = $this->actingAs($agent)->get('/dashboard')->assertOk()->getContent();

    // Anything after </head> is too late: a dark-mode agent gets a white flash
    // on every navigation.
    $head = substr($content, 0, strpos($content, '</head>'));

    expect($head)->toContain("localStorage.getItem('wayfindr:theme')")
        ->and($head)->toContain('data-wf-theme');
});

test('the dashboard heading does not repeat the breadcrumb or greet you with your own email', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'email' => 'ada@example.com',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('<h1>Dashboard</h1>', false)
        ->assertDontSee('Signed in as ada@example.com');
});
