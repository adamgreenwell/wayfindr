<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\AutomationRuleActionType;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('account admins can review ticket labels and usage', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $usedLabel = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $unusedLabel = TicketLabel::factory()->for($account)->create([
        'name' => 'Billing',
        'slug' => 'billing',
    ]);
    $otherAccountLabel = TicketLabel::factory()->create([
        'name' => 'Other Account',
        'slug' => 'other-account',
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $ticket->labels()->attach($usedLabel);

    $this->actingAs($admin)
        ->get('/dashboard/account/labels')
        ->assertOk()
        ->assertSee('Ticket labels')
        ->assertSee('Create label')
        ->assertSee('Needs Dev')
        ->assertSee('needs-dev')
        ->assertSee('1 ticket')
        ->assertSee('In use on 1 ticket')
        ->assertSee('Billing')
        ->assertSee('Delete label')
        ->assertDontSee('Delete unused')
        ->assertDontSee('Other Account');

    $this->actingAs($agent)
        ->get('/dashboard/account/labels')
        ->assertForbidden();

    expect($unusedLabel->exists)->toBeTrue()
        ->and($otherAccountLabel->exists)->toBeTrue();
});

test('knowledge only roles can manage labels without seeing ticket usage', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageKnowledge->value],
    ]);
    $knowledgeManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Private support volume',
        'slug' => 'private-support-volume',
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $ticket->labels()->attach($label);

    $this->actingAs($knowledgeManager)
        ->get(route('dashboard.account.labels.index'))
        ->assertOk()
        ->assertSee('Private support volume')
        ->assertSee('private-support-volume')
        // Without ticket access the in-use branch cannot run, so this label --
        // which IS on a ticket -- offers Delete. The button used to say
        // "Delete unused" here, which was simply untrue; the controller
        // refuses it with a reason instead.
        ->assertSee('Delete label')
        ->assertDontSee('1 ticket')
        ->assertDontSee('In use on 1 ticket')
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_label' => $label->slug,
        ]));
});

test('ticket label management guides admins before labels exist', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/labels')
        ->assertOk()
        ->assertSee('No ticket labels yet.')
        ->assertSee('Labels group tickets for triage and become filters in the ticket queue.')
        // "Managed" was the code's word, not the reader's.
        ->assertDontSee('No managed ticket labels yet.')
        ->assertSee('Create the first label')
        ->assertSee('href="#new-ticket-label-heading"', false);
});

test('account admins can create reusable ticket labels from management', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->post('/dashboard/account/labels', [
            'label_name' => 'VIP Customer',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHas('status', 'ticket_labels.flash.created');

    $this->assertDatabaseHas('ticket_labels', [
        'account_id' => $account->id,
        'name' => 'VIP Customer',
        'slug' => 'vip-customer',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/labels')
        ->assertOk()
        ->assertSee('VIP Customer')
        ->assertSee('vip-customer')
        ->assertSee('0 tickets')
        ->assertSee('Delete label');
});

test('managed ticket labels link to the all-status ticket queue filter', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $otherAccountLabel = TicketLabel::factory()->create([
        'name' => 'Other Label',
        'slug' => 'other-label',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Closed checkout investigation',
            'status' => 'closed',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($admin)
        ->get('/dashboard/account/labels')
        ->assertOk()
        ->assertSee('Needs Dev')
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_label' => 'needs-dev',
        ]))
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_label' => $otherAccountLabel->slug,
        ]));

    $this->actingAs($admin)
        ->get(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_label' => 'needs-dev',
        ]))
        ->assertOk()
        ->assertSee('Closed checkout investigation');
});

test('managed ticket label drill-in links only count tickets visible to the admin', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $siteAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
    ]);
    $scopedSite = Site::factory()->for($account)->create(['name' => 'Scoped Docs']);
    $scopedSite->supportAgents()->attach($siteAgent);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($scopedSite)
        ->create([
            'subject' => 'Hidden implementation ticket',
            'status' => 'open',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($admin)
        ->get('/dashboard/account/labels')
        ->assertOk()
        ->assertSee('Needs Dev')
        ->assertSee('1 ticket')
        ->assertSee('No visible tickets')
        ->assertDontSee(route('dashboard.tickets.index', [
            'ticket_status' => 'all',
            'ticket_label' => 'needs-dev',
        ]));
});

test('ticket label creation rejects reserved and duplicate account slugs', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->post('/dashboard/account/labels', [
            'label_name' => 'All',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHasErrors('label_name');

    $this->assertDatabaseMissing('ticket_labels', [
        'account_id' => $account->id,
        'slug' => 'all',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->post('/dashboard/account/labels', [
            'label_name' => 'Needs    Dev',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHasErrors('label_name');

    expect(TicketLabel::query()
        ->where('account_id', $account->id)
        ->where('slug', 'needs-dev')
        ->count())->toBe(1);
});

test('only account admins can create managed ticket labels', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/account/labels', [
            'label_name' => 'VIP Customer',
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('ticket_labels', [
        'account_id' => $account->id,
        'slug' => 'vip-customer',
    ]);
});

test('dashboard ticket labels link to the matching ticket queue filter', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Checkout outage',
            'status' => 'open',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Checkout outage')
        ->assertSee('Needs Dev')
        ->assertSee(route('dashboard.tickets.index', ['ticket_label' => 'needs-dev']), false);
});

test('dashboard ticket label links preserve the active ticket status queue', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Checkout follow-up',
            'status' => 'pending',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=pending')
        ->assertOk()
        ->assertSee('Checkout follow-up')
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'pending',
            'ticket_label' => 'needs-dev',
        ]));
});

test('ticket detail labels link back to the matching dashboard filter', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Billing',
        'slug' => 'billing',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Billing question',
            'status' => 'open',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Billing')
        ->assertSee(route('dashboard.tickets.index', ['ticket_label' => 'billing']), false);
});

test('ticket detail label links preserve non-open ticket status', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Follow Up',
        'slug' => 'follow-up',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Closed follow-up',
            'status' => 'closed',
        ]);
    $ticket->labels()->attach($label);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Follow Up')
        ->assertSee(route('dashboard.tickets.index', [
            'ticket_status' => 'closed',
            'ticket_label' => 'follow-up',
        ]));
});

test('account admins can rename ticket labels', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->put("/dashboard/account/labels/{$label->id}", [
            'label_name' => 'Escalation',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHas('status', 'ticket_labels.flash.renamed');

    $this->assertDatabaseHas('ticket_labels', [
        'id' => $label->id,
        'account_id' => $account->id,
        'name' => 'Escalation',
        'slug' => 'escalation',
    ]);
});

test('ticket label renames reject reserved dashboard filter slugs', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->put("/dashboard/account/labels/{$label->id}", [
            'label_name' => 'All',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHasErrors('label_name');

    $this->assertDatabaseHas('ticket_labels', [
        'id' => $label->id,
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
});

test('ticket label renames reject duplicate account slugs', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    TicketLabel::factory()->for($account)->create([
        'name' => 'Billing',
        'slug' => 'billing',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->put("/dashboard/account/labels/{$label->id}", [
            'label_name' => 'Billing',
        ])
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHasErrors('label_name');

    $this->assertDatabaseHas('ticket_labels', [
        'id' => $label->id,
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
});

test('account admins can delete unused labels but not labels still on tickets', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $usedLabel = TicketLabel::factory()->for($account)->create([
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
    ]);
    $unusedLabel = TicketLabel::factory()->for($account)->create([
        'name' => 'Billing',
        'slug' => 'billing',
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $ticket->labels()->attach($usedLabel);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->delete("/dashboard/account/labels/{$usedLabel->id}")
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHasErrors('label');

    $this->assertDatabaseHas('ticket_labels', [
        'id' => $usedLabel->id,
        'slug' => 'needs-dev',
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/labels')
        ->delete("/dashboard/account/labels/{$unusedLabel->id}")
        ->assertRedirect('/dashboard/account/labels')
        ->assertSessionHas('status', 'ticket_labels.flash.deleted');

    $this->assertDatabaseMissing('ticket_labels', [
        'id' => $unusedLabel->id,
    ]);
});

test('ticket label management actions stay inside same account admin boundaries', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $otherLabel = TicketLabel::factory()->for($otherAccount)->create([
        'name' => 'Other Account',
        'slug' => 'other-account',
    ]);

    $this->actingAs($admin)
        ->put("/dashboard/account/labels/{$otherLabel->id}", [
            'label_name' => 'Borrowed',
        ])
        ->assertNotFound();

    $this->actingAs($admin)
        ->delete("/dashboard/account/labels/{$otherLabel->id}")
        ->assertNotFound();

    $this->actingAs($agent)
        ->put("/dashboard/account/labels/{$otherLabel->id}", [
            'label_name' => 'Nope',
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('ticket_labels', [
        'id' => $otherLabel->id,
        'name' => 'Other Account',
        'slug' => 'other-account',
    ]);
});

test('ticket label mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $knowledgeRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageKnowledge->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $knowledgeRole->id,
    ]);
    $label = TicketLabel::factory()->for($account)->create([
        'name' => 'Original label',
        'slug' => 'original-label',
    ]);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageKnowledge))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.account.labels.store'), [
            'label_name' => 'Late label',
        ]),
        'update' => $this->put(route('dashboard.account.labels.update', $label), [
            'label_name' => 'Late rename',
        ]),
        'delete' => $this->delete(route('dashboard.account.labels.destroy', $label)),
    };

    $action === 'create'
        ? $response->assertForbidden()
        : $response->assertNotFound();

    expect(TicketLabel::query()->count())->toBe(1)
        ->and($label->fresh()->name)->toBe('Original label')
        ->and($label->fresh()->slug)->toBe('original-label');
})->with(['create', 'update', 'delete']);

test('an agent who reads German gets the labels page, counts included, in German', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);
    $site = Site::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Eskalation']);

    // Two tickets, so the PLURAL branch renders. A singular fixture would let
    // a catalogue whose plural was pasted from its singular pass.
    Ticket::factory()->count(2)->for($account)->for($site)->create()
        ->each(fn (Ticket $ticket) => $ticket->labels()->attach($label));

    $this->actingAs($admin)
        ->get(route('dashboard.account.labels.index'))
        ->assertOk()
        ->assertSee('Ticket-Labels')
        ->assertSee('Label erstellen')
        // The count sentence, inflected -- not `2 tickets` welded to an English
        // pluraliser, which is what `Str::plural` produced whatever the
        // catalogue said.
        //
        // The NEGATIVE is what actually catches that. This page renders the
        // count twice, in the usage column and in the in-use note, so
        // asserting only the German form passes while one of the two is still
        // English -- which is how the first version of this test survived
        // reverting exactly the line it was written for.
        ->assertSee('2 Tickets')
        ->assertDontSee('2 tickets')
        ->assertDontSee('Create label')
        ->assertDontSee('Ticket labels');
});

test('every rename form says which label it renames', function (): void {
    // The browser half of the binding below: the page has to SEND the id, or
    // the error routing has nothing to route on.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $labels = TicketLabel::factory()->for($account)->count(2)->sequence(
        ['name' => 'Needs Dev', 'slug' => 'needs-dev'],
        ['name' => 'Billing', 'slug' => 'billing'],
    )->create();

    $xpath = ticketLabelManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.labels.index'))->assertOk()->getContent(),
    );

    foreach ($labels as $label) {
        ticketLabelManagementElement(
            $xpath,
            '//form[@action="'.route('dashboard.account.labels.update', $label).'"]//input[@type="hidden" and @name="editing_label" and @value="'.$label->id.'"]',
        );
    }
});

test('a rejected rename comes back to the row that sent it and nowhere else', function (): void {
    // Every rename form and the create form post the same `label_name`. Before
    // the rows said which one they were, a rejected rename came back as a
    // detached message at the top of the page, with the rejected value
    // painted into the create field and EVERY row -- so a Save on any other
    // row sent it as that label's new name.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $renamed = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);
    $bystander = TicketLabel::factory()->for($account)->create(['name' => 'Billing', 'slug' => 'billing']);

    $html = $this->actingAs($admin)
        ->from(route('dashboard.account.labels.index'))
        ->followingRedirects()
        ->put(route('dashboard.account.labels.update', $renamed), [
            'editing_label' => (string) $renamed->id,
            'label_name' => 'All',
        ])
        ->assertOk()
        ->getContent();

    $xpath = ticketLabelManagementXPath($html);
    $failed = ticketLabelManagementElement($xpath, '//input[@id="ticket-label-'.$renamed->id.'"]');
    $message = ticketLabelManagementElement($xpath, '//p[@id="ticket-label-'.$renamed->id.'-error"]');
    $other = ticketLabelManagementElement($xpath, '//input[@id="ticket-label-'.$bystander->id.'"]');
    $create = ticketLabelManagementElement($xpath, '//input[@id="new-label-name"]');

    expect($failed->getAttribute('value'))->toBe('All', 'the failing row lost what the agent typed')
        ->and($failed->getAttribute('aria-invalid'))->toBe('true', 'the failing row is not marked invalid')
        ->and($failed->getAttribute('aria-describedby'))->toBe('ticket-label-'.$renamed->id.'-error', 'the failing row is not described by its message')
        ->and($failed->hasAttribute('autofocus'))->toBeTrue('the reloaded page does not open on the failing row')
        ->and(trim($message->textContent))->toBe(__('ticket_labels.validation.reserved'))
        ->and($other->getAttribute('value'))->toBe('Billing', 'the rejected rename was painted into another row')
        ->and($other->hasAttribute('aria-invalid'))->toBeFalse('a row that was not submitted is marked invalid')
        ->and($create->getAttribute('value'))->toBe('', 'the rejected rename was painted into the create field')
        ->and($create->hasAttribute('aria-invalid'))->toBeFalse('the create field is marked invalid for a rename')
        ->and($xpath->query('//p[contains(@class, "field-error")]')->length)->toBe(1, 'the message is printed more than once');
});

test('a rejected create comes back to the create field and leaves every row alone', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $existing = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);

    $html = $this->actingAs($admin)
        ->from(route('dashboard.account.labels.index'))
        ->followingRedirects()
        ->post(route('dashboard.account.labels.store'), [
            'label_name' => 'All',
        ])
        ->assertOk()
        ->getContent();

    $xpath = ticketLabelManagementXPath($html);
    $create = ticketLabelManagementElement($xpath, '//input[@id="new-label-name"]');
    $message = ticketLabelManagementElement($xpath, '//p[@id="new-label-name-error"]');
    $row = ticketLabelManagementElement($xpath, '//input[@id="ticket-label-'.$existing->id.'"]');

    expect($create->getAttribute('value'))->toBe('All', 'the create field lost what the agent typed')
        ->and($create->getAttribute('aria-invalid'))->toBe('true', 'the create field is not marked invalid')
        ->and($create->getAttribute('aria-describedby'))->toBe('new-label-name-error', 'the create field is not described by its message')
        ->and($create->hasAttribute('autofocus'))->toBeTrue('the reloaded page does not open on the create field')
        ->and(trim($message->textContent))->toBe(__('ticket_labels.validation.reserved'))
        ->and($row->getAttribute('value'))->toBe('Needs Dev', 'the rejected create was painted into a rename row')
        ->and($row->hasAttribute('aria-invalid'))->toBeFalse('a rename row is marked invalid for a create');
});

test('label names and slugs are marked as account data, not dashboard copy', function (): void {
    // The same label is language-reset as a chip on a ticket
    // (components/ticket-label-chip); the page that names it has to agree, or
    // a German agent's screen reader reads `Needs Dev` with German phonetics.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);

    $xpath = ticketLabelManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.labels.index'))->assertOk()->getContent(),
    );

    $rename = ticketLabelManagementElement($xpath, '//input[@id="ticket-label-'.$label->id.'"]');

    expect($xpath->query('//td/strong[@lang="" and normalize-space(.)="Needs Dev"]')->length)
        ->toBe(1, 'the label name is not marked as account data')
        ->and($xpath->query('//td/code[@lang="" and normalize-space(.)="needs-dev"]')->length)
        ->toBe(1, 'the slug is not marked as account data')
        ->and($rename->hasAttribute('lang') && $rename->getAttribute('lang') === '')
        ->toBeTrue('the rename field, whose value is the label name, is not marked as account data');
});

test('the create section explains itself under its heading, and the count stays on the right', function (): void {
    // `.section-header` is a space-between flex row. A lede that is the h2's
    // SIBLING is pushed to the far edge -- right for a count, wrong for a
    // sentence that explains the heading it belongs to.
    $admin = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Admin]);

    $xpath = ticketLabelManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.labels.index'))->assertOk()->getContent(),
    );

    $header = 'div[contains(concat(" ", normalize-space(@class), " "), " section-header ")]';

    expect($xpath->query('//'.$header.'/div[h2[@id="new-ticket-label-heading"]]/p[@class="lede"]')->length)
        ->toBe(1, 'the create lede is not under its heading')
        ->and($xpath->query('//'.$header.'[h2[@id="ticket-labels-heading"]]/span[@class="lede"]')->length)
        ->toBe(1, 'the label count left the right-hand slot');
});

function ticketLabelManagementXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

/**
 * The one element a query names. Failing on a count of zero OR two says the
 * query is wrong before any attribute assertion can pass on the wrong node.
 */
function ticketLabelManagementElement(DOMXPath $xpath, string $query): DOMElement
{
    $nodes = $xpath->query($query);

    expect($nodes === false ? 0 : $nodes->length)->toBe(1, "expected exactly one element for {$query}");

    return $nodes->item(0);
}

test('a label row discriminator sent as an array still lands on the page, not a 500', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);

    $this->actingAs($admin)
        ->from(route('dashboard.account.labels.index'))
        ->followingRedirects()
        ->put(route('dashboard.account.labels.update', $label), [
            'editing_label' => [(string) $label->id],
            'label_name' => 'All',
        ])
        ->assertOk();
});

/**
 * What a row's Delete button submits, read off the rendered form rather than
 * restated here, so a field the form stops carrying fails the test.
 */
function ticketLabelManagementDeleteFields(DOMXPath $xpath, TicketLabel $label): array
{
    $form = ticketLabelManagementElement($xpath, '//form[@action="'.route('dashboard.account.labels.destroy', $label).'"][input[@name="_method" and @value="DELETE"]]');
    $fields = [];

    foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }

    unset($fields['_token'], $fields['_method']);

    return $fields;
}

test('a refused delete comes back on the row whose Delete sent it', function (): void {
    // The refusal printed at the top of the page and named no label: "Remove
    // this label from every automation rule and macro before deleting it."
    // With several labels in use, nobody could tell which one it meant.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $used = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);
    $bystander = TicketLabel::factory()->for($account)->create(['name' => 'Billing', 'slug' => 'billing']);
    AutomationRule::factory()->for($account)->create([
        'actions' => [['type' => AutomationRuleActionType::AddLabel->value, 'value' => $used->id]],
    ]);

    $index = route('dashboard.account.labels.index');
    $fields = ticketLabelManagementDeleteFields(ticketLabelManagementXPath((string) $this->actingAs($admin)->get($index)->assertOk()->getContent()), $used);

    $xpath = ticketLabelManagementXPath((string) $this->actingAs($admin)
        ->from($index)
        ->followingRedirects()
        ->delete(route('dashboard.account.labels.destroy', $used), $fields)
        ->assertOk()
        ->getContent());

    $message = ticketLabelManagementElement($xpath, '//p[contains(@class, "field-error")]');
    $row = ticketLabelManagementElement($xpath, '//tr[td/strong[normalize-space(.)="Needs Dev"]]');
    $button = ticketLabelManagementElement($xpath, '//tr[td/strong[normalize-space(.)="Needs Dev"]]//form[input[@name="_method" and @value="DELETE"]]//button');

    expect(trim($message->textContent))->toBe(__('ticket_labels.validation.in_use_automation'))
        ->and($xpath->query('ancestor::tr[1]', $message)->item(0)?->isSameNode($row))
        ->toBeTrue('the refusal is not on the row of the label it refused, so it names no label')
        ->and($message->getAttribute('id'))->toBe('ticket-label-'.$used->id.'-delete-error')
        ->and($button->getAttribute('aria-describedby'))->toBe($message->getAttribute('id'), 'the refused Delete button is not described by its refusal')
        ->and($xpath->query('//tr[td/strong[normalize-space(.)="Billing"]]//*[@aria-describedby]')->length)
        ->toBe(0, 'a row whose Delete was not pressed is described by the refusal');

    $this->assertDatabaseHas('ticket_labels', ['id' => $used->id]);
    $this->assertDatabaseHas('ticket_labels', ['id' => $bystander->id]);
});

test('a refused delete that no row claims is still shown', function (): void {
    // A request without the row's id -- an older tab, a crafted form -- must
    // not lose the refusal: it stays at the top rather than vanishing.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $used = TicketLabel::factory()->for($account)->create(['name' => 'Needs Dev', 'slug' => 'needs-dev']);
    AutomationRule::factory()->for($account)->create([
        'actions' => [['type' => AutomationRuleActionType::AddLabel->value, 'value' => $used->id]],
    ]);

    foreach ([[], ['deleting_label' => [(string) $used->id]], ['deleting_label' => '999999']] as $fields) {
        $xpath = ticketLabelManagementXPath((string) $this->actingAs($admin)
            ->from(route('dashboard.account.labels.index'))
            ->followingRedirects()
            ->delete(route('dashboard.account.labels.destroy', $used), $fields)
            ->assertOk()
            ->getContent());

        expect($xpath->query('//p[contains(@class, "field-error")]')->length)
            ->toBe(1, 'a refusal no row claims vanished from the page, so the delete looks silently ignored');

        $message = ticketLabelManagementElement($xpath, '//p[contains(@class, "field-error")]');

        expect(trim($message->textContent))->toBe(__('ticket_labels.validation.in_use_automation'))
            ->and($xpath->query('ancestor::table', $message)->length)->toBe(0, 'an unclaimed refusal landed on a row');
    }
});
