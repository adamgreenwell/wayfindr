<?php

// The rail inside one site (components/layouts/site.blade.php).
//
// One sidebar that changes with where you are: on a site's pages the rail
// shows that site's sections in place of the product's, and leads back to the
// sites directory. Like the account sidebar, it decides on its own which
// destinations to show, but authorization lives in each controller -- so these
// tests never restate the permission map. They ask the controllers.

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Every page the site rail can send someone to, by route name. Deliberately a
 * literal list: a link this does not name fails the property test below.
 *
 * @return list<string>
 */
function siteRailDestinations(): array
{
    return [
        'dashboard.sites.show',
        'dashboard.sites.status',
        'dashboard.sites.tester',
        'dashboard.sites.live',
        'dashboard.sites.proactive-messages.index',
    ];
}

/**
 * The page's site rail, parsed: one entry per link, in order.
 *
 * @return list<array{href: string, label: string, current: bool}>
 */
function siteRailLinks(string $html, string $where): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $navs = $xpath->query('//aside[@class="wf-rail"]//nav[@aria-label="Site sections"]');

    expect($navs->length)->toBe(1, "{$where} does not render exactly one site rail");

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
function siteRailReaders(): array
{
    $readers = [
        'built-in owner' => [AccountRole::Owner, null],
        'built-in admin' => [AccountRole::Admin, null],
        'built-in agent' => [AccountRole::Agent, null],
    ];

    // One custom role per permission, so each destination's own permission is
    // exercised alone -- the combination a built-in role never produces.
    foreach (AccountPermission::delegable() as $permission) {
        $readers['custom role with only '.$permission->value] = [AccountRole::Agent, [$permission]];
    }

    return $readers;
}

function siteRailReader(AccountRole $builtIn, ?array $permissions): User
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

test('every site rail link opens for its reader, and every destination it hides is closed', function (AccountRole $builtIn, ?array $permissions): void {
    $reader = siteRailReader($builtIn, $permissions);
    // Presence on, so the live board is a destination; with it off the rail
    // withholds the board on purpose, which the test below covers.
    $site = Site::factory()->for($reader->account)->create([
        'name' => 'Acme Docs',
        'settings' => ['presence' => ['enabled' => true]],
    ]);
    $role = $permissions === null
        ? $builtIn->value
        : 'custom role ['.implode(', ', array_map(fn (AccountPermission $permission): string => $permission->value, $permissions)).']';

    $destinations = collect(siteRailDestinations())
        ->mapWithKeys(fn (string $name): array => [route($name, $site) => $name]);
    $statuses = $destinations->map(fn (string $name, string $href): int => $this->actingAs($reader)->get($href)->status());
    $open = $statuses->filter(fn (int $status): bool => $status === 200)->keys()->all();

    if ($open === []) {
        // A reader who can open none of a site's pages never sees the rail.
        expect($statuses->every(fn (int $status): bool => in_array($status, [403, 404], true)))
            ->toBeTrue("{$role}: a site page answered something other than 200, 403 or 404");

        return;
    }

    foreach ($open as $href) {
        $name = $destinations[$href];
        $shown = array_column(siteRailLinks((string) $this->actingAs($reader)->get($href)->getContent(), $name), 'href');

        foreach ($shown as $link) {
            expect($destinations->has($link))->toBeTrue("{$role}: the site rail links to {$link}, which this test does not check -- add it to siteRailDestinations()");
        }

        expect($shown)->toBe($open, "{$role}: {$name} shows a site rail that differs from the pages this reader can open");

        // The way out has to open too: a reader holding only ManageAutomations
        // reaches proactive messages but not the sites directory.
        preg_match('/class="wf-context-back" href="([^"]+)"/', (string) $this->actingAs($reader)->get($href)->getContent(), $back);
        $backStatus = $this->actingAs($reader)->get(html_entity_decode($back[1] ?? ''))->status();

        expect($backStatus)->toBe(200, "{$role}: {$name}'s rail leads back to ".($back[1] ?? 'nothing').", which answers {$backStatus}");
    }
})->with(siteRailReaders());

test('an archived site does not offer proactive messages, which refuses it', function (): void {
    $owner = siteRailReader(AccountRole::Owner, null);
    $site = Site::factory()->for($owner->account)->create(['archived_at' => now()]);

    $this->actingAs($owner)->get(route('dashboard.sites.proactive-messages.index', $site))->assertNotFound();

    $html = (string) $this->actingAs($owner)->get(route('dashboard.sites.show', $site))->assertOk()->getContent();

    expect(array_column(siteRailLinks($html, 'an archived site'), 'href'))
        ->not->toContain(route('dashboard.sites.proactive-messages.index', $site));
});

test('each site page marks its own rail entry, and the rail leads back to the sites directory', function (string $routeName, string $entry): void {
    $owner = siteRailReader(AccountRole::Owner, null);
    $site = Site::factory()->for($owner->account)->create(['name' => 'Acme Docs']);
    $response = $this->actingAs($owner)->get(route($routeName, $site));

    expect($response->status())->toBe(200, "{$routeName} answers {$response->status()} for an owner");

    $current = array_values(array_filter(siteRailLinks((string) $response->getContent(), $routeName), fn (array $link): bool => $link['current']));

    expect(array_column($current, 'label'))->toBe([$entry], "{$routeName} should mark exactly \"{$entry}\" as the current rail entry");

    $response->assertSeeInOrder([
        'class="wf-context-back" href="'.route('dashboard.sites.index').'"',
        '<span>Sites</span>',
        'class="wf-context-title"',
        // The site's name is the account's words, not the dashboard's.
        '<span lang="">Acme Docs</span>',
    ], false)->assertDontSee('aria-label="Primary navigation"', false);
})->with([
    'settings' => ['dashboard.sites.show', 'Settings'],
    'status' => ['dashboard.sites.status', 'Status'],
    'tester' => ['dashboard.sites.tester', 'Tester'],
    'live' => ['dashboard.sites.live', 'Live visitors'],
    'proactive messages' => ['dashboard.sites.proactive-messages.index', 'Proactive messages'],
    'new proactive message' => ['dashboard.sites.proactive-messages.create', 'Proactive messages'],
]);

test('the site rail carries the site\'s wayfinding colour', function (): void {
    $owner = siteRailReader(AccountRole::Owner, null);
    $site = Site::factory()->for($owner->account)->create();

    $this->actingAs($owner)
        ->get(route('dashboard.sites.status', $site))
        ->assertOk()
        ->assertSee('class="wf-context-title" style="--rail-context-color: var('.$site->resolvedColor()->cssVariable().')"', false);
});

test('the site rail speaks the reader\'s language on an extracted page', function (): void {
    $owner = siteRailReader(AccountRole::Owner, null);
    $owner->forceFill(['locale' => 'de'])->save();
    $site = Site::factory()->for($owner->account)->create(['settings' => ['presence' => ['enabled' => true]]]);

    $html = (string) $this->actingAs($owner)->get(route('dashboard.sites.status', $site))->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('aria-label="Website-Bereiche"', false)
        ->getContent();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $labels = [];

    foreach ((new DOMXPath($document))->query('//nav[@aria-label="Website-Bereiche"]//a') as $link) {
        $labels[] = trim($link->textContent);
    }

    expect($labels)->toBe(['Einstellungen', 'Status', 'Tester', 'Live-Besuchende', 'Proaktive Nachrichten']);
});

test('no site page links back to the site or the directory from its own body', function (string $routeName): void {
    // The rail is the way back now, as it is in the account. A leftover
    // "Back to site settings" is a second, differently-placed control for the
    // same thing.
    $owner = siteRailReader(AccountRole::Owner, null);
    $site = Site::factory()->for($owner->account)->create();

    $html = (string) $this->actingAs($owner)->get(route($routeName, $site))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $backs = [];

    foreach ((new DOMXPath($document))->query('//main//a[contains(@class, "page-header__back")]') as $link) {
        $backs[] = $link->getAttribute('href');
    }

    expect($backs)->not->toContain(route('dashboard.sites.show', $site))
        ->and($backs)->not->toContain(route('dashboard.sites.index'));
})->with(['dashboard.sites.show', 'dashboard.sites.status', 'dashboard.sites.tester']);

test('the live board is offered only while the site collects presence', function (): void {
    // The settings page withholds its link to the board when presence is off,
    // because the board then has nothing to show; the rail agrees with it. A
    // reader who arrives anyway still sees where they are.
    $owner = siteRailReader(AccountRole::Owner, null);
    $site = Site::factory()->for($owner->account)->create(['settings' => ['presence' => ['enabled' => false]]]);
    $live = route('dashboard.sites.live', $site);

    $settings = (string) $this->actingAs($owner)->get(route('dashboard.sites.show', $site))->assertOk()->getContent();

    expect(array_column(siteRailLinks($settings, 'settings, presence off'), 'href'))->not->toContain($live);

    $board = (string) $this->actingAs($owner)->get($live)->assertOk()->getContent();
    $current = array_values(array_filter(siteRailLinks($board, 'the board, presence off'), fn (array $link): bool => $link['current']));

    expect(array_column($current, 'href'))->toBe([$live]);

    $site->forceFill(['settings' => ['presence' => ['enabled' => true]]])->save();

    expect(array_column(siteRailLinks((string) $this->actingAs($owner)->get(route('dashboard.sites.show', $site))->getContent(), 'settings, presence on'), 'href'))
        ->toContain($live);
});
