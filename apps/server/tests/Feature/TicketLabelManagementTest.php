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
