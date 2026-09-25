<?php

// The account area's context sidebar (components/layouts/account.blade.php).
//
// It replaced the account overview's in-page "Account map", its directory of
// management pages, and the three different ways those pages linked back. The
// sidebar decides on its own which destinations to show, but it does not own
// the answer: authorization lives in each destination's controller, not in
// route middleware. So the tests here never restate the permission map -- they
// ask the controllers, and hold the sidebar to whatever they say.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Article;
use App\Models\AutomationMacro;
use App\Models\AutomationRule;
use App\Models\CustomRole;
use App\Models\User;
use App\Support\DashboardLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every page the sidebar can send someone to. Deliberately a literal list: if
 * the layout grows a link this does not name, the property test below fails
 * rather than leaving the new link unchecked.
 *
 * @return list<string>
 */
function accountSidebarDestinations(): array
{
    return [
        'dashboard.account.show',
        'dashboard.account.roles.index',
        'dashboard.account.security.show',
        'dashboard.account.articles.index',
        'dashboard.account.reply-templates.index',
        'dashboard.account.labels.index',
        'dashboard.account.visitor-attributes.index',
        'dashboard.account.automation-rules.index',
        'dashboard.account.sla-policies.index',
        'dashboard.account.integrations',
        'dashboard.account.api-tokens.index',
        'dashboard.account.audit.index',
        'dashboard.account.break-glass.index',
    ];
}

/**
 * Each account page, by route name, and the sidebar entry it belongs to.
 *
 * @return array<string, string>
 */
function accountSidebarCurrentEntries(): array
{
    return [
        'dashboard.account.show' => 'Overview',
        'dashboard.account.roles.index' => 'Roles',
        'dashboard.account.security.show' => 'Security',
        'dashboard.account.articles.index' => 'Articles',
        'dashboard.account.articles.show' => 'Articles',
        'dashboard.account.reply-templates.index' => 'Reply templates',
        'dashboard.account.labels.index' => 'Ticket labels',
        'dashboard.account.visitor-attributes.index' => 'Visitor attributes',
        'dashboard.account.automation-rules.index' => 'Automations',
        'dashboard.account.automation-rules.create' => 'Automations',
        'dashboard.account.automation-rules.edit' => 'Automations',
        'dashboard.account.automation-macros.create' => 'Automations',
        'dashboard.account.automation-macros.edit' => 'Automations',
        'dashboard.account.sla-policies.index' => 'SLA policies',
        'dashboard.account.integrations' => 'Integrations',
        'dashboard.account.api-tokens.index' => 'API and webhooks',
        'dashboard.account.audit.index' => 'Audit log',
        'dashboard.account.break-glass.index' => 'Operator access',
    ];
}

/**
 * The page's account sidebar, parsed: one entry per link, in order.
 *
 * @return list<array{href: string, label: string, current: bool}>
 */
function accountSidebarLinks(string $html, string $where): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $navs = $xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " wf-context-nav ")]');

    expect($navs->length)->toBe(1, "{$where} does not render exactly one account sidebar");

    $links = [];

    foreach ($xpath->query('.//a', $navs->item(0)) as $link) {
        $links[] = [
            'href' => $link->getAttribute('href'),
            'label' => trim($link->textContent),
            'current' => $link->getAttribute('aria-current') === 'page',
        ];
    }

    return $links;
}

/** @return array<string, array{AccountRole, list<AccountPermission>|null}> */
function accountSidebarReaders(): array
{
    $readers = [
        'built-in owner' => [AccountRole::Owner, null],
        'built-in admin' => [AccountRole::Admin, null],
        'built-in agent' => [AccountRole::Agent, null],
        // ADR 0023: a custom role holding nothing at all is still a member of
        // the account, and the overview is still theirs to open.
        'custom role with no permissions' => [AccountRole::Agent, []],
    ];

    // One custom role per permission, so each destination's own permission is
    // exercised alone -- the combination a built-in role never produces.
    foreach (AccountPermission::delegable() as $permission) {
        $readers['custom role with only '.$permission->value] = [AccountRole::Agent, [$permission]];
    }

    return $readers;
}

function accountSidebarReader(AccountRole $builtIn, ?array $permissions): User
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $attributes = ['account_role' => $builtIn];

    if ($permissions !== null) {
        $attributes['custom_role_id'] = CustomRole::factory()->for($account)->create([
            'permissions' => array_map(fn (AccountPermission $permission): string => $permission->value, $permissions),
        ])->id;
    }

    return User::factory()->for($account)->create($attributes);
}

test('every sidebar link opens for its reader, and every destination it hides is forbidden', function (AccountRole $builtIn, ?array $permissions): void {
    $reader = accountSidebarReader($builtIn, $permissions);
    $role = $permissions === null
        ? $builtIn->value
        : 'custom role ['.implode(', ', array_map(fn (AccountPermission $permission): string => $permission->value, $permissions)).']';

    $overview = $this->actingAs($reader)->get(route('dashboard.account.show'));

    expect($overview->status())->toBe(200, "{$role}: the account overview answers {$overview->status()}");

    $shown = array_column(accountSidebarLinks((string) $overview->getContent(), 'the overview'), 'href');
    $destinations = collect(accountSidebarDestinations())
        ->mapWithKeys(fn (string $name): array => [route($name) => $name]);

    foreach ($shown as $href) {
        expect($destinations->has($href))->toBeTrue("{$role}: the sidebar links to {$href}, which this test does not check -- add it to accountSidebarDestinations()");
    }

    foreach ($destinations as $href => $name) {
        $response = $this->actingAs($reader)->get($href);
        $status = $response->status();

        if (! in_array($href, $shown, true)) {
            expect($status)->toBe(403, "{$role}: the sidebar hides {$name}, but it answers {$status} -- a page this reader can open has no way in");

            continue;
        }

        expect($status)->toBe(200, "{$role}: the sidebar shows {$name}, but it answers {$status}");

        // Built once per request, in the layout -- so every page must offer
        // the same destinations the overview did.
        expect(array_column(accountSidebarLinks((string) $response->getContent(), $name), 'href'))
            ->toBe($shown, "{$role}: {$name} renders a different sidebar from the overview");
    }
})->with(accountSidebarReaders());

test('a group heading is shown only when the group has a destination for the reader', function (): void {
    // Only ManageSites: SLA policies under Workflow, and nothing under Support
    // content or Oversight.
    $reader = accountSidebarReader(AccountRole::Agent, [AccountPermission::ManageSites]);

    $html = (string) $this->actingAs($reader)->get(route('dashboard.account.show'))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $headings = [];

    foreach ((new DOMXPath($document))->query('//nav[@aria-label="Account sections"]/p[contains(@class, "wf-context-heading")]') as $heading) {
        $headings[] = trim($heading->textContent);
    }

    expect($headings)->toBe(['Account', 'Workflow', 'Connections'], 'a group with nothing in it for this reader still shows its heading')
        ->and(array_column(accountSidebarLinks($html, 'the overview'), 'label'))
        ->toBe(['Overview', 'SLA policies', 'Integrations']);
});

test('each account page marks its own sidebar entry, and only that one', function (string $routeName, string $entry): void {
    $owner = accountSidebarReader(AccountRole::Owner, null);
    $account = $owner->account;
    $parameters = match ($routeName) {
        'dashboard.account.articles.show' => [Article::factory()->for($account)->create()],
        'dashboard.account.automation-rules.edit' => [AutomationRule::factory()->for($account)->create()],
        'dashboard.account.automation-macros.edit' => [AutomationMacro::factory()->for($account)->create()],
        default => [],
    };

    $response = $this->actingAs($owner)->get(route($routeName, $parameters));

    expect($response->status())->toBe(200, "{$routeName} answers {$response->status()} for an owner");

    $current = array_values(array_filter(
        accountSidebarLinks((string) $response->getContent(), $routeName),
        fn (array $link): bool => $link['current'],
    ));

    expect(array_column($current, 'label'))->toBe([$entry], "{$routeName} should mark exactly \"{$entry}\" as the current sidebar entry");

    // The breadcrumb names the same place.
    $response->assertSee('<span class="wf-crumb-current">'.$entry.'</span>', false);
})->with(function (): array {
    $cases = [];

    foreach (accountSidebarCurrentEntries() as $routeName => $entry) {
        $cases[$routeName] = [$routeName, $entry];
    }

    return $cases;
});

test('every account page is covered by the current-entry test above', function (): void {
    // A page added under /dashboard/account without an entry above would
    // render with nothing marked, and nothing would say so.
    $pages = collect(app('router')->getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name): bool => str_starts_with($name, 'dashboard.account.') && in_array('GET', $route->methods(), true))
        ->keys()
        // A CSV download, not a page: it renders no layout at all.
        ->reject(fn (string $name): bool => $name === 'dashboard.account.audit.export')
        ->sort()
        ->values()
        ->all();

    $covered = collect(array_keys(accountSidebarCurrentEntries()))->sort()->values()->all();

    expect($pages)->not->toBeEmpty()
        ->and($covered)->toBe($pages);
});

test('every account page speaks the reader\'s language, so the sidebar can', function (): void {
    // A translated catalogue renders only on a route listed as extracted; a
    // page left off the list draws the German sidebar in English.
    foreach (array_keys(accountSidebarCurrentEntries()) as $routeName) {
        $this->assertContains($routeName, DashboardLanguage::EXTRACTED_ROUTES, "{$routeName} renders the account sidebar but is not an extracted route, so its labels stay English");
    }
});

test('the sidebar renders in the reader\'s language', function (string $locale, string $label, array $groups, array $sections): void {
    $owner = accountSidebarReader(AccountRole::Owner, null);
    $owner->forceFill(['locale' => $locale])->save();

    // A sub-page rather than the overview, so the sidebar is checked where it
    // is the only way back.
    $html = (string) $this->actingAs($owner)
        ->get(route('dashboard.account.sla-policies.index'))
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee('aria-label="'.$label.'"', false)
        ->assertDontSee('Account sections')
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $headings = [];

    foreach ($xpath->query('//nav[contains(@class, "wf-context-nav")]/p') as $heading) {
        $headings[] = trim($heading->textContent);
    }

    expect($headings)->toBe($groups)
        ->and(array_column(accountSidebarLinks($html, 'SLA policies'), 'label'))->toBe($sections);
})->with([
    'German' => ['de', 'Kontobereiche',
        ['Konto', 'Support-Inhalte', 'Arbeitsabläufe', 'Verbindungen', 'Aufsicht'],
        ['Übersicht', 'Rollen', 'Sicherheit', 'Artikel', 'Antwortvorlagen', 'Ticket-Labels', 'Besucherattribute', 'Automatisierungen', 'SLA-Richtlinien', 'Integrationen', 'API und Webhooks', 'Audit-Protokoll', 'Betreiberzugriff'],
    ],
    'Italian' => ['it', 'Sezioni dell’account',
        ['Account', 'Contenuti di supporto', 'Flussi di lavoro', 'Connessioni', 'Supervisione'],
        ['Panoramica', 'Ruoli', 'Sicurezza', 'Articoli', 'Modelli di risposta', 'Etichette dei ticket', 'Attributi dei visitatori', 'Automazioni', 'Criteri SLA', 'Integrazioni', 'API e webhook', 'Registro di audit', 'Accesso del gestore'],
    ],
]);

test('no account page links back to the overview from its own body', function (string $routeName): void {
    // The sidebar is the way back now. A leftover "Back to account" link is a
    // second, differently-placed control for the same thing -- which is the
    // inconsistency the sidebar was built to remove.
    $owner = accountSidebarReader(AccountRole::Owner, null);
    $account = $owner->account;
    $parameters = match ($routeName) {
        'dashboard.account.articles.show' => [Article::factory()->for($account)->create()],
        'dashboard.account.automation-rules.edit' => [AutomationRule::factory()->for($account)->create()],
        'dashboard.account.automation-macros.edit' => [AutomationMacro::factory()->for($account)->create()],
        default => [],
    };

    $html = (string) $this->actingAs($owner)->get(route($routeName, $parameters))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $body = (new DOMXPath($document))->query('//div[contains(@class, "wf-context-body")]');

    expect($body->length)->toBe(1, "{$routeName} has no account sidebar body");

    $hrefs = [];

    foreach ((new DOMXPath($document))->query('.//a', $body->item(0)) as $link) {
        $hrefs[] = $link->getAttribute('href');
    }

    expect(in_array(route('dashboard.account.show'), $hrefs, true))
        ->toBeFalse("{$routeName} still links back to the account overview from its body");
})->with(array_keys(accountSidebarCurrentEntries()));

test('the pages under a list still lead back to that list', function (): void {
    // The sidebar replaces links whose only job was the account overview. A
    // form's link back to the list it came from is somewhere else, and stays.
    $owner = accountSidebarReader(AccountRole::Owner, null);
    $account = $owner->account;

    $this->actingAs($owner)
        ->get(route('dashboard.account.articles.show', Article::factory()->for($account)->create()))
        ->assertOk()
        ->assertSee('class="page-header__back" href="'.route('dashboard.account.articles.index').'"', false)
        ->assertSee('Back to articles');

    $this->actingAs($owner)
        ->get(route('dashboard.account.automation-macros.edit', AutomationMacro::factory()->for($account)->create()))
        ->assertOk()
        ->assertSee('class="page-header__back" href="'.route('dashboard.account.automation-rules.index').'"', false)
        ->assertSee('Back to automations');

    $this->actingAs($owner)
        ->get(route('dashboard.account.automation-rules.create'))
        ->assertOk()
        ->assertSee('class="page-header__back" href="'.route('dashboard.account.automation-rules.index').'"', false);
});

test('the breadcrumb leads to the account overview, not the operator console', function (): void {
    // The middle crumb was hard-coded to the operator console while that was
    // the only surface with sections of its own.
    $agent = accountSidebarReader(AccountRole::Admin, null);

    $this->actingAs($agent)
        ->get(route('dashboard.account.sla-policies.index'))
        ->assertOk()
        ->assertSeeInOrder([
            'aria-label="Breadcrumb"',
            'href="'.route('dashboard.account.show').'">Account</a>',
            '<span class="wf-crumb-current">SLA policies</span>',
        ], false)
        ->assertDontSee('href="'.route('operator.dashboard').'"', false);
});

test('the desktop sidebar scrolls on its own when it is taller than the window', function (): void {
    // Sticky with no height bound: on a short window (1024x600) the Connections
    // and Oversight links sat below the fold for the whole length of the page.
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $html = (string) $this->actingAs($owner)->get(route('dashboard.account.show'))->assertOk()->getContent();

    preg_match('/\n        \.wf-context-nav \{([^}]*)\}/', $html, $rule);

    expect($rule)->not->toBe([], 'the desktop context-nav rule did not render; this guard is checking nothing')
        ->and(str_contains($rule[1], 'max-height: calc(100vh') && str_contains($rule[1], 'overflow-y: auto'))
        ->toBeTrue('the sticky sidebar has no bounded height of its own, so its last links can sit below a short window');
});
