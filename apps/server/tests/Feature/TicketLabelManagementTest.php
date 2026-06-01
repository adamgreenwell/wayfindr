<?php

use App\Enums\AccountRole;
use App\Models\Account;
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
        ->assertSee('Delete unused')
        ->assertDontSee('Other Account');

    $this->actingAs($agent)
        ->get('/dashboard/account/labels')
        ->assertForbidden();

    expect($unusedLabel->exists)->toBeTrue()
        ->and($otherAccountLabel->exists)->toBeTrue();
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
        ->assertSessionHas('status', 'Ticket label created.');

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
        ->assertSee('Delete unused');
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
        ->assertSee(route('dashboard', [
            'ticket_status' => 'all',
            'ticket_label' => 'needs-dev',
        ]).'#tickets')
        ->assertDontSee(route('dashboard', [
            'ticket_status' => 'all',
            'ticket_label' => $otherAccountLabel->slug,
        ]).'#tickets');

    $this->actingAs($admin)
        ->get(route('dashboard', [
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
        ->assertDontSee(route('dashboard', [
            'ticket_status' => 'all',
            'ticket_label' => 'needs-dev',
        ]).'#tickets');
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
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Checkout outage')
        ->assertSee('Needs Dev')
        ->assertSee(route('dashboard', ['ticket_label' => 'needs-dev']).'#tickets', false);
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
        ->get('/dashboard?ticket_status=pending')
        ->assertOk()
        ->assertSee('Checkout follow-up')
        ->assertSee(route('dashboard', [
            'ticket_status' => 'pending',
            'ticket_label' => 'needs-dev',
        ]).'#tickets');
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
        ->assertSee(route('dashboard', ['ticket_label' => 'billing']).'#tickets', false);
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
        ->assertSee(route('dashboard', [
            'ticket_status' => 'closed',
            'ticket_label' => 'follow-up',
        ]).'#tickets');
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
        ->assertSessionHas('status', 'Ticket label renamed.');

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
        ->assertSessionHas('status', 'Unused ticket label deleted.');

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
