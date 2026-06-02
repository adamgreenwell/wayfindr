<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents can see the support code lookup on the dashboard', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Find support record')
        ->assertSee('support_code')
        ->assertSee(route('dashboard.support-code.lookup'), false);
});

test('agents can jump to a visible conversation by support code', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-FINDME',
        'subject' => 'Lookup conversation',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => ' wf-findme ',
        ]))
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code));
});

test('agents jump to the linked ticket when a visible support code has one', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-TICKETREF',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->create([
            'subject' => 'Durable support record',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'WF-TICKETREF',
        ]))
        ->assertRedirect(route('dashboard.tickets.show', $ticket));
});

test('support code lookup does not expose another account record', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    Conversation::factory()->for($otherSite)->create([
        'support_code' => 'WF-PRIVATE',
        'subject' => 'Other account conversation',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'WF-PRIVATE',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for WF-PRIVATE.');
});

test('support code lookup rejects non-scalar query values', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => ['WF-FINDME'],
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'Enter a support code to find a conversation or ticket.');
});

test('support code lookup respects explicit site support access', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $siteAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $scopedSite = Site::factory()->for($account)->create(['name' => 'Scoped Docs']);
    $scopedSite->supportAgents()->attach($siteAgent);
    Conversation::factory()->for($scopedSite)->create([
        'support_code' => 'WF-SCOPED',
        'subject' => 'Scoped conversation',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'WF-SCOPED',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for WF-SCOPED.');

    $this->actingAs($agent)
        ->withSession(['support_code_lookup_status' => 'No visible support record found for WF-SCOPED.'])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No visible support record found for WF-SCOPED.');
});
