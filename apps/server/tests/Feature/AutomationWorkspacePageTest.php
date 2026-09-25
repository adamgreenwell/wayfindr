<?php

use App\Enums\AccountRole;
use App\Enums\AutomationExecutionStatus;
use App\Models\Account;
use App\Models\AutomationMacro;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function automationWorkspacePageXPath(TestResponse $response): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

function automationWorkspacePageText(DOMXPath $xpath, string $query): string
{
    $node = $xpath->query($query)->item(0);

    return $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent));
}

test('the automations index tabs rules, macros, proactive messages and the execution log', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    Site::factory()->for($account)->create(['name' => 'Docs']);

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk());

    $tabs = [];
    foreach ($xpath->query('//div[@id="automation-workspace"]//button[@role="tab"]') as $tab) {
        $tabs[$tab->getAttribute('data-tab')] = trim($tab->textContent);
    }

    expect($tabs)->toBe([
        'rules' => 'Rules',
        'macros' => 'Macros',
        'proactive' => 'Proactive messages',
        'executions' => 'Execution log',
    ], 'the index must be one tab strip, rules first, not five stacked sections');

    // Each list lives in its own panel, and only the rules panel is open on
    // arrival: that is what makes the enabled rules visible without a scroll.
    foreach ([
        'rules' => 'automation-rules-heading',
        'macros' => 'automation-macros-heading',
        'proactive' => 'proactive-sites-heading',
        'executions' => 'automation-executions-heading',
    ] as $panel => $heading) {
        expect($xpath->query('//div[@id="tab-panel-'.$panel.'"]//h2[@id="'.$heading.'"]')->length)
            ->toBe(1, "the {$heading} section must sit inside the {$panel} tab panel");
        expect($xpath->query('//div[@id="tab-panel-'.$panel.'"]')->item(0)->hasAttribute('hidden'))
            ->toBe($panel !== 'rules', "only the rules panel is open on arrival ({$panel})");
    }
});

test('the automations index keeps the safety note short, collapsed and true for this page', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $response = $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk();
    $xpath = automationWorkspacePageXPath($response);

    expect($xpath->query('//details[contains(@class, "automation-safety")]')->length)
        ->toBe(1, 'the safety prose belongs in a disclosure, not a full-width section above the lists');
    expect(automationWorkspacePageText($xpath, '//details[contains(@class, "automation-safety")]/summary'))
        ->toBe('Automation safety');

    $safety = automationWorkspacePageText($xpath, '//details[contains(@class, "automation-safety")]');

    // The run-order rule is stated on the form, where run order is set. A
    // third copy on the index is what the audit asked to remove.
    expect(str_contains((string) $response->getContent(), 'Lower run-order numbers execute first'))
        ->toBeFalse('the index must not restate the run-order rule the form states at the point of use');

    // Proactive messages share this page and DO reach visitors, so the
    // sentence has to name what it is true of.
    expect(str_contains($safety, 'Rules and macros never send a visitor-facing message; only proactive messages do.'))
        ->toBeTrue('the visitor-safety sentence must be scoped to rules and macros, not every automation on the page');
    expect(str_contains($safety, 'This action set'))
        ->toBeFalse('"this action set" has no referent on a list page');

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.create'))
        ->assertOk()
        ->assertSee('Lower numbers run first; equal numbers fall back to creation order.');
});

test('explanatory ledes on the automations index sit under their heading', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk());

    // `.section-header` is a space-between flex row: a sentence that is a
    // direct child of it is pushed to the far edge, away from its heading.
    foreach (['proactive-sites-heading', 'automation-executions-heading'] as $heading) {
        expect($xpath->query('//div[contains(@class, "section-header")]/div[h2[@id="'.$heading.'"]]/p[contains(@class, "lede")]')->length)
            ->toBe(1, "the {$heading} lede must be wrapped with its heading, not a flex sibling on the right");
    }

    // A count is a status, not an explanation: it keeps the right-hand slot.
    foreach (['automation-rules-heading', 'automation-macros-heading'] as $heading) {
        expect($xpath->query('//div[contains(@class, "section-header")][h2[@id="'.$heading.'"]]/span[contains(@class, "lede")]')->length)
            ->toBe(1, "the {$heading} count stays on the right of its header");
    }
});

test('paging the execution log reopens the execution log tab', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $rule = AutomationRule::factory()->for($account)->create([
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);

    foreach (range(1, 26) as $index) {
        AutomationRuleExecution::query()->create([
            'account_id' => $account->id,
            'automation_rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'event' => $rule->event,
            'status' => AutomationExecutionStatus::Succeeded,
            'conditions' => [],
            'actions' => $rule->actions,
            'action_results' => [],
            'metadata' => ['message_id' => null],
            'started_at' => now()->subMinutes($index),
            'completed_at' => now()->subMinutes($index),
        ]);
    }

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk());

    $pageLinks = [];
    foreach ($xpath->query('//div[@id="tab-panel-executions"]//a[contains(@href, "page=2")]') as $link) {
        $pageLinks[] = $link->getAttribute('href');
    }

    expect($pageLinks)->not->toBe([]);

    foreach ($pageLinks as $href) {
        expect(parse_url($href, PHP_URL_FRAGMENT))
            ->toBe('tab-executions', "a log page link must reopen the log tab, not land on rules: {$href}");
    }
});

test('a dry-run preview result takes focus instead of relying on a live region', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $rule = AutomationRule::factory()->for($account)->create([
        'event' => 'ticket.created',
        'conditions' => [],
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->withSession(['automation_preview' => [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'event' => $rule->event,
            'matched' => true,
            'subject_label' => 'Ticket #1',
            'conditions' => [],
            'actions' => $rule->actions,
        ]])
        ->get(route('dashboard.account.automation-rules.edit', $rule))
        ->assertOk()
        ->assertSee('Would match'));

    $result = $xpath->query('//div[contains(@class, "automation-preview-result")]')->item(0);

    expect($result)->not->toBeNull();
    expect($result->getAttribute('tabindex'))
        ->toBe('-1', 'the preview result must be focusable so focus can land on the verdict');
    expect($result->hasAttribute('autofocus'))
        ->toBeTrue('the preview result arrives on a fresh page load, so focus has to move to it');
    expect($result->hasAttribute('aria-live'))
        ->toBeFalse('a live region born with its content never announces it; it must not stand in for focus');
    expect($xpath->query('//*[@autofocus]')->length)
        ->toBe(1, 'only the first autofocus candidate is honoured, so the result must be the only one');

    // The dry-run header used to carry a permanently amber "No changes" pill
    // bound to no state; the lede beside it already says so.
    expect($xpath->query('//section[@aria-labelledby="automation-preview-heading"]/div[contains(@class, "section-header")]//*[contains(@class, "readiness-status")]')->length)
        ->toBe(0, 'the dry-run header must not carry a hard-coded status pill');
});

test('the preview redirect lands on the verdict it just produced', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $rule = AutomationRule::factory()->for($account)->create([
        'event' => 'ticket.created',
        'conditions' => [],
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);

    $location = (string) $this->actingAs($admin)
        ->post(route('dashboard.account.automation-rules.preview', $rule), [
            'preview_subject' => 'ticket:'.$ticket->id,
        ])
        ->assertSessionHas('automation_preview')
        ->headers->get('Location');
    $fragment = parse_url($location, PHP_URL_FRAGMENT);

    // Navigating to a fragment scrolls to its target and, when the target can
    // take focus, focuses it -- which is the whole point of this redirect.
    expect($fragment)->not->toBeNull('the preview redirect must name the verdict so the browser scrolls and focuses there');

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)->get($location)->assertOk()->assertSee('Would match'));
    $target = $xpath->query('//*[@id="'.$fragment.'"]')->item(0);

    expect($target)->not->toBeNull("the redirect fragment #{$fragment} must name an element on the page it lands on");
    expect(str_contains($target->getAttribute('class'), 'automation-preview-result'))
        ->toBeTrue("#{$fragment} must be the preview result, not some other element");
    expect($target->getAttribute('tabindex'))
        ->toBe('-1', "#{$fragment} must be focusable, or fragment navigation only scrolls");
});

test('a failed automation save is framed as an error on both forms', function (string $store, string $heading): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $response = $this->actingAs($admin)
        ->followingRedirects()
        ->from(route(str_replace('.store', '.create', $store)))
        ->post(route($store), ['name' => '', 'actions' => []])
        ->assertOk()
        ->assertSee($heading);
    $xpath = automationWorkspacePageXPath($response);
    $html = (string) $response->getContent();

    expect($xpath->query('//section[contains(@class, "automation-validation")]')->length)->toBe(1);
    expect(preg_match('/\.automation-validation\s*\{[^}]*var\(--danger\)/', $html))
        ->toBe(1, 'the validation summary must be danger-framed, not styled like every other card');
    expect(preg_match('/\.automation-validation h2\s*\{[^}]*color:\s*var\(--danger\)/', $html))
        ->toBe(1, 'the validation summary heading must read as an error');
})->with([
    'rule form' => ['dashboard.account.automation-rules.store', 'Review the rule definition'],
    'macro form' => ['dashboard.account.automation-macros.store', 'Review the macro definition'],
]);

test('a failed automation save moves focus to the validation summary', function (string $kind, bool $editing): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $prefix = "dashboard.account.automation-{$kind}";
    $invalid = ['name' => '', 'actions' => []];

    if ($editing) {
        $saved = $kind === 'rules'
            ? AutomationRule::factory()->for($account)->create(['actions' => [['type' => 'set_priority', 'value' => 'urgent']]])
            : AutomationMacro::factory()->for($account)->create();
        $form = route("{$prefix}.edit", $saved);
        $response = $this->actingAs($admin)->from($form)->put(route("{$prefix}.update", $saved), $invalid);
    } else {
        $form = route("{$prefix}.create");
        $response = $this->actingAs($admin)->from($form)->post(route("{$prefix}.store"), $invalid);
    }

    // Not assertSessionHasErrors: it re-marshals the error bag in the test
    // session, and the next request then renders an empty one.
    $location = (string) $response->assertRedirect()->headers->get('Location');
    $fragment = parse_url($location, PHP_URL_FRAGMENT);

    expect(Str::before($location, '#'))->toBe($form, 'a failed save must return to the form it came from');
    // The summary arrives on a full page load, where a live region is born
    // with its content and announces nothing: focus has to be moved there.
    expect($fragment)->toBe('automation-validation', "the failed-save redirect must name the validation summary so the browser focuses it: {$location}");

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get($location)
        ->assertOk()
        ->assertSee('The name field is required.'));
    $summary = $xpath->query('//*[@id="'.$fragment.'"]')->item(0);

    expect($summary)->not->toBeNull("the redirect fragment #{$fragment} must name an element on the form it lands on");
    expect(str_contains($summary->getAttribute('class'), 'automation-validation'))
        ->toBeTrue("#{$fragment} must be the validation summary, not some other element");
    expect($summary->getAttribute('tabindex'))
        ->toBe('-1', 'the validation summary must be focusable, or fragment navigation only scrolls');
    expect($summary->hasAttribute('autofocus'))
        ->toBeTrue('the validation summary must autofocus where the browser does not focus a fragment target');
    expect($xpath->query('//*[@autofocus]')->length)
        ->toBe(1, 'only the first autofocus candidate is honoured, so the summary must be the only one');
    expect($summary->hasAttribute('aria-live') || in_array($summary->getAttribute('role'), ['alert', 'status'], true))
        ->toBeFalse('a live region born with its content never announces it; it must not stand in for focus');
})->with([
    'new rule' => ['rules', false],
    'saved rule' => ['rules', true],
    'new macro' => ['macros', false],
    'saved macro' => ['macros', true],
]);

test('the rule builder does not offer closing the conversation on a visitor message', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $response = $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.create'))
        ->assertOk();
    $xpath = automationWorkspacePageXPath($response);

    // The rendered action row and the template new rows are cloned from.
    $withheld = [];
    foreach ($xpath->query('//*[@data-rule-row][.//*[@data-action-type]]//option[starts-with(@value, "status:")]') as $option) {
        $withheld[$option->getAttribute('value')][] = $option->getAttribute('data-withheld-events');
    }

    expect($withheld)->toBe([
        'status:open' => ['', ''],
        'status:pending' => ['', ''],
        'status:closed' => ['conversation.visitor_message_created', 'conversation.visitor_message_created'],
    ], 'every action row must mark "Closed" as refused on a visitor message, and nothing else as refused');

    // "Status is closed" is still a fair condition; only the action is refused.
    expect($xpath->query('//*[@data-rule-row][.//*[@data-condition-field]]//option[@data-withheld-events]')->length)
        ->toBe(0, 'condition rows must keep offering every status');

    // The builder re-syncs every row when the event changes, so the check
    // belongs in the per-row choice sync, and it has to hide AND disable.
    $sync = Str::between((string) $response->getContent(), 'function syncChoice(', 'function syncCondition(');

    expect(str_contains($sync, '[data-withheld-events]'))
        ->toBeTrue('the choice sync must visit the options an event refuses');
    expect(str_contains($sync, "(option.dataset.withheldEvents || '').split(',').includes(eventSelect.value)"))
        ->toBeTrue('an option must be unavailable exactly when the selected event is one that refuses it');
    expect(str_contains($sync, 'option.hidden = unavailable;') && str_contains($sync, 'option.disabled = unavailable;'))
        ->toBeTrue('a refused status must be both hidden and disabled, so a stale selection is cleared');
});

test('the proactive messages page leads back to the proactive tab', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertOk());
    $href = (string) $xpath->query('//a[contains(@class, "page-header__back")]')->item(0)?->getAttribute('href');

    expect(Str::before($href, '#'))->toBe(route('dashboard.account.automation-rules.index'));
    // Without it the index opens on Rules wherever the tab strip's memory
    // (sessionStorage) is unavailable.
    expect(parse_url($href, PHP_URL_FRAGMENT))
        ->toBe('tab-proactive', "Back to automations must reopen the proactive tab, not land on rules: {$href}");

    $index = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk());

    expect($index->query('//*[@data-tabs]//*[@data-tab-panel="proactive"]')->length)
        ->toBe(1, '#tab-proactive must name a panel the index actually has');
});

test('account-authored names in the automation builders reset the page language', function (string $page): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin, 'name' => 'Ada Admin']);
    $retired = User::factory()->for($account)->create(['name' => 'Rita Retired', 'deactivated_at' => now()]);
    $site = Site::factory()->for($account)->create(['name' => 'Nordwind Preise']);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Rechnung']);

    $xpath = automationWorkspacePageXPath($this->actingAs($admin)
        ->get(route($page))
        ->assertOk());

    $options = function (string $value) use ($xpath): DOMNodeList {
        return $xpath->query('//option[@value="'.$value.'"]');
    };

    $expectations = [
        'agent:'.$admin->id => true,
        'label:'.$label->id => true,
        // An option holds text only: the deactivated suffix is our copy, so
        // that option cannot claim the whole string is in no language.
        'agent:'.$retired->id => false,
    ];

    if ($page === 'dashboard.account.automation-rules.create') {
        $expectations['site:'.$site->id] = true;
    }

    foreach ($expectations as $value => $reset) {
        expect($options($value)->length)->toBeGreaterThan(0, "{$value} must be offered on {$page}");

        foreach ($options($value) as $option) {
            expect($option->hasAttribute('lang') && $option->getAttribute('lang') === '')
                ->toBe($reset, $reset
                    ? "the account-authored name in {$value} must carry lang=\"\""
                    : "{$value} mixes a name with translated copy and must keep the page language");
        }
    }
})->with([
    'rule builder' => ['dashboard.account.automation-rules.create'],
    'macro builder' => ['dashboard.account.automation-macros.create'],
]);

test('both automation forms name their shared back destination the same way', function (string $locale): void {
    // Both forms link back to one index, titled "Automations"; the rule form
    // used to call it "automation rules", a page that does not exist.
    app()->setLocale($locale);

    expect(__('automation_rules.edit.back'))
        ->toBe(__('automation_macros.edit.back'), "the rule and macro forms must agree on what the index is called ({$locale})");
})->with(['en', 'de', 'it']);
