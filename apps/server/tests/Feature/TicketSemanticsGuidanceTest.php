<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('conversation ticket creation explains operational priority semantics', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIORITYSEM',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-PRIORITYSEM')
        ->assertOk()
        ->assertSee('Urgent - Business-critical, active outage, or blocked production work.')
        ->assertSee('Agent move: assign immediately and keep the visitor updated.')
        ->assertSee('High - Time-sensitive issue affecting an important customer workflow.')
        ->assertSee('Agent move: keep it moving today.')
        ->assertSee('Normal - Standard support request with no immediate deadline.')
        ->assertSee('Agent move: answer in normal queue order.')
        ->assertSee('Low - Nice-to-have follow-up or non-blocking question.')
        ->assertSee('Agent move: handle after active visitor blockers.');
});

test('ticket editing explains operational category semantics', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Checkout trouble',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Question - General question or how-to help.')
        ->assertSee('Use for: clarification, product guidance, or "how do I?" support.')
        ->assertSee('Bug - Something broken or not working as expected.')
        ->assertSee('Use for: broken, unexpected, or reproducible behavior.')
        ->assertSee('Billing - Pricing, invoice, payment, or account billing issue.')
        ->assertSee('Use for: pricing, invoices, payments, renewals, or billing-account changes.')
        ->assertSee('Access - Login, permissions, or account access issue.')
        ->assertSee('Use for: login, roles, locked accounts, permissions, or identity/access issues.')
        ->assertSee('Task - Follow-up work, configuration, or operational request.')
        ->assertSee('Use for: setup, configuration, operational work, or planned follow-up.')
        ->assertSee('Other - Anything that does not fit the other categories.')
        ->assertSee('Use sparingly; add context so it can be recategorized later.');
});
