<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agents can see the support code lookup on the dashboard', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Find support trail')
        ->assertSee('Jump by support code, ticket reference, or visitor ID')
        ->assertSee('Support code, ticket reference, or visitor ID')
        ->assertSee('support_code')
        ->assertSee(route('dashboard.support-code.lookup'), false);
});

test('agents can use support trail lookup from the shared app shell', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('aria-label="Find support trail"', false)
        ->assertSee('name="support_code"', false)
        ->assertSee('placeholder="Support code or ticket"', false)
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

test('agents can jump to a visible ticket by ticket reference', function (string $ticketReference): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Reference lookup ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => str_replace('{ticket}', (string) $ticket->id, $ticketReference),
        ]))
        ->assertRedirect(route('dashboard.tickets.show', $ticket));
})->with([
    'ticket prefix' => [' Ticket #{ticket} '],
    'hash reference' => ['#{ticket}'],
    'plain id' => ['{ticket}'],
]);

test('agents can jump to a visible visitor profile by anonymous visitor id', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-support-trail',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => ' anon-support-trail ',
        ]))
        ->assertRedirect(route('dashboard.visitors.show', $visitor));
});

test('agents can jump to a visible visitor profile by host visitor id', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-host-context',
        'external_id' => 'customer-123',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'customer-123',
        ]))
        ->assertRedirect(route('dashboard.visitors.show', $visitor));
});

test('agents can jump to a visible visitor profile by numeric host visitor id', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-numeric-host-context',
        'external_id' => '12345',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => '12345',
        ]))
        ->assertRedirect(route('dashboard.visitors.show', $visitor));
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

test('support code lookup does not expose another account visitor id', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    Visitor::factory()->for($otherSite)->create([
        'anonymous_id' => 'anon-private',
        'external_id' => 'customer-private',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'customer-private',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for customer-private.');
});

test('support record lookup does not expose another account ticket reference', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'subject' => 'Other account ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'Ticket #'.$otherTicket->id,
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for Ticket #'.$otherTicket->id.'.');
});

test('support code lookup rejects non-scalar query values', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => ['WF-FINDME'],
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'Enter a support code, ticket reference, or visitor ID to find a support trail.');
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

test('support code lookup does not expose visitors from unsupported sites', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $siteAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $scopedSite = Site::factory()->for($account)->create(['name' => 'Scoped Docs']);
    $scopedSite->supportAgents()->attach($siteAgent);
    Visitor::factory()->for($scopedSite)->create([
        'anonymous_id' => 'anon-scoped',
        'external_id' => 'customer-scoped',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'customer-scoped',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for customer-scoped.');
});
