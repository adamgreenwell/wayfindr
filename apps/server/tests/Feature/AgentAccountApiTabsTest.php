<?php

// The API and webhooks page as two tabs: API tokens, which let an integration
// call in, and outbound webhooks, which let this account call out.
//
// The markup is the easy half. What these tests hold is where the page OPENS:
// a token and a signing secret are each shown exactly once, so neither may be
// issued into a panel the reader cannot see -- not after a redirect, and not
// in a browser that never runs the tabs script.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\OutboundWebhookEndpoint;
use App\Models\User;
use App\Support\Webhooks\OutboundWebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class);

function apiTabsAdmin(): User
{
    return User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Admin]);
}

/**
 * The page's tabs as the markup states them before any script runs: which tab
 * is selected, and which panel is not hidden. They are two separate claims
 * about the same thing, so tests hold them to each other.
 *
 * @return array{labels: list<string>, selected: list<string>, shown: list<string>, panels: array<string, DOMElement>, xpath: DOMXPath}
 */
function apiTabsOpening(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $container = $xpath->query('//div[@id="api-workspace"]')->item(0);

    expect($container)->toBeInstanceOf(DOMElement::class, 'the API and webhooks tabs did not render');

    $labels = [];
    $selected = [];
    $shown = [];
    $panels = [];

    foreach ($xpath->query('.//*[@role="tab"]', $container) as $tab) {
        $labels[] = trim($tab->textContent);

        if ($tab->getAttribute('aria-selected') === 'true') {
            $selected[] = $tab->getAttribute('data-tab');
        }
    }

    foreach ($xpath->query('.//*[@data-tab-panel]', $container) as $panel) {
        $panels[$panel->getAttribute('data-tab-panel')] = $panel;

        if (! $panel->hasAttribute('hidden')) {
            $shown[] = $panel->getAttribute('data-tab-panel');
        }
    }

    return compact('labels', 'selected', 'shown', 'panels', 'xpath');
}

function apiTabsExpectOpensOn(array $opening, string $panel, string $why): void
{
    expect($opening['shown'])->toBe([$panel], "{$why}: the page shows the wrong panel")
        ->and($opening['selected'])->toBe([$panel], "{$why}: the selected tab disagrees with the panel shown");
}

test('tokens and outbound webhooks are two tabs, and each section sits in its own', function (): void {
    $html = (string) $this->actingAs(apiTabsAdmin())
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->getContent();
    $opening = apiTabsOpening($html);

    expect($opening['labels'])->toBe(['API tokens', 'Outbound webhooks']);
    apiTabsExpectOpensOn($opening, 'tokens', 'with nothing just issued');

    $sections = [
        'tokens' => ['api-token-list-heading', 'api-token-create-heading'],
        'webhooks' => ['outbound-webhook-list-heading', 'outbound-webhook-create-heading', 'outbound-webhook-deliveries-heading'],
    ];

    foreach ($sections as $panel => $ids) {
        foreach ($ids as $id) {
            foreach ($opening['panels'] as $name => $element) {
                $found = $opening['xpath']->query('.//*[@id="'.$id.'"]', $element)->length;

                expect($found)->toBe($name === $panel ? 1 : 0, "#{$id} belongs in the {$panel} panel, and is counted {$found} times in {$name}");
            }
        }
    }
});

test('a new signing secret opens the page on the webhooks tab before any script runs', function (): void {
    $admin = apiTabsAdmin();
    app()->instance(OutboundWebhookDestination::class, new OutboundWebhookDestination(fn (string $host): array => ['8.8.8.8']));

    $this->actingAs($admin)
        ->post(route('dashboard.account.outbound-webhooks.store'), [
            'webhook' => [
                'name' => 'Warehouse listener',
                'url' => 'https://hooks.example.test/wayfindr',
                'events' => [OutboundWebhookEndpoint::EVENT_TICKET_CREATED],
            ],
        ])
        ->assertRedirect(route('dashboard.account.api-tokens.index').'#tab-webhooks');

    $secret = OutboundWebhookEndpoint::query()->sole()->secret;
    $opening = apiTabsOpening((string) $this->get(route('dashboard.account.api-tokens.index'))->assertOk()->getContent());

    apiTabsExpectOpensOn($opening, 'webhooks', 'right after a signing secret was issued');

    // Not toContain($secret, $message): that is variadic, and would look for
    // the message as a second value.
    expect(str_contains($opening['panels']['webhooks']->textContent, $secret))->toBeTrue('the signing secret is not in the panel the page opens on')
        ->and(str_contains($opening['panels']['tokens']->textContent, $secret))->toBeFalse('the signing secret leaked into the tokens panel');
});

test('a new API token opens the page on the tokens tab', function (): void {
    $this->actingAs(apiTabsAdmin())
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->assertRedirect(route('dashboard.account.api-tokens.index').'#tab-tokens');

    $opening = apiTabsOpening((string) $this->get(route('dashboard.account.api-tokens.index'))->assertOk()->getContent());

    apiTabsExpectOpensOn($opening, 'tokens', 'right after a token was issued');

    expect($opening['xpath']->query('.//*[@id="api-token-issued-heading"]', $opening['panels']['tokens'])->length)
        ->toBe(1, 'the one-time token is not in the panel the page opens on');
});

test('a webhook form that fails validation reopens on the webhooks tab with its errors', function (): void {
    // A failed form goes back() to the page, with no fragment for the tabs
    // script to read, so the server's answer is the only one.
    //
    // No assertSessionHasErrors() here: it starts the session to read it, and
    // with JSON session serialization the next request then rebuilds the
    // error bag from an already-rebuilt one and gets an empty bag -- a test
    // harness artifact, not the app. The errors rendered in the panel below
    // are the stronger check anyway.
    $this->actingAs(apiTabsAdmin())
        ->from(route('dashboard.account.api-tokens.index'))
        ->post(route('dashboard.account.outbound-webhooks.store'), ['webhook' => ['name' => '', 'url' => '']])
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    $opening = apiTabsOpening((string) $this->get(route('dashboard.account.api-tokens.index'))->assertOk()->getContent());

    apiTabsExpectOpensOn($opening, 'webhooks', 'after the webhook form failed');

    expect($opening['xpath']->query('.//p[contains(@class, "field-error")]', $opening['panels']['webhooks'])->length)
        ->toBeGreaterThan(0, 'the webhook form errors are not in the panel the page opens on');
});

test('the page opens on the panel the last write belonged to', function (array $session, string $panel): void {
    $opening = apiTabsOpening((string) $this->actingAs(apiTabsAdmin())
        ->withSession($session)
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->getContent());

    apiTabsExpectOpensOn($opening, $panel, 'after '.json_encode(array_keys($session)));
})->with([
    // The secret alone, without the status that normally travels with it: it
    // is the one thing that must never open in a hidden panel, so it cannot
    // rely on a flash key's prefix to get there. A closure, because a data
    // provider runs before the app boots and encryption needs its key.
    'a signing secret was issued' => [fn (): array => ['issued_webhook_secret' => Crypt::encryptString('whsec_only_the_secret')], 'webhooks'],
    'a webhook was disabled' => [['status' => 'outbound_webhooks.flash.disabled'], 'webhooks'],
    'a delivery retry was queued' => [['status' => 'outbound_webhooks.flash.retrying'], 'webhooks'],
    'a token was revoked' => [['status' => 'api_tokens.flash.revoked'], 'tokens'],
    'the token form failed' => [['errors' => (new ViewErrorBag)->put('default', new MessageBag(['name' => ['Required.']]))], 'tokens'],
    'nothing happened' => [[], 'tokens'],
]);

test('a page that names no active tab still opens on its first', function (): void {
    // `active` on the tabs component is new with this page; every other page
    // passes none and must render exactly as before.
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $html = (string) $this->actingAs($owner)->get(route('dashboard.account.automation-rules.index'))->assertOk()->getContent();

    preg_match_all('/<button[^>]*role="tab"[^>]*aria-selected="true"[^>]*data-tab="([^"]+)"/', $html, $selected);

    expect($selected[1])->toBe(['rules'], 'the automations page no longer opens on its first tab');
});
