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

test('successful support code lookups tell agents what matched', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-MATCHED',
        'subject' => 'Matched conversation',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => ' wf-matched ',
        ]))
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code))
        ->assertSessionHas('support_code_lookup_result', 'Matched support code WF-MATCHED.');

    $this->actingAs($agent)
        ->withSession(['support_code_lookup_result' => 'Matched support code WF-MATCHED.'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('Matched support code WF-MATCHED.');
});

test('agents can jump to a visible conversation from pasted support code context', function (string $reference): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-PASTE1',
        'subject' => 'Pasted reference conversation',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => $reference,
        ]))
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code));
})->with([
    'prose reference' => ['Visitor gave me support code wf-paste1 during chat.'],
    'conversation URL' => ['https://wayfindr.example/dashboard/conversations/wf-paste1?from=email'],
]);

test('agents can jump to a visible conversation from common support code separators', function (string $reference): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-PASTE2',
        'subject' => 'Human formatted support code',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => $reference,
        ]))
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code));
})->with([
    'space separator' => ['Visitor said support code wf paste2.'],
    'colon separator' => ['Support code: WF: PASTE2'],
    'underscore separator' => ['WF_PASTE2'],
    'padded separator' => ['WF - PASTE2'],
]);

test('pasted stale ticket context does not block a visible support code match', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'support_code' => 'WF-MIXED1',
        'subject' => 'Mixed pasted reference',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'Old ticket #999999, current support code wf-mixed1',
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

test('successful ticket reference lookups tell agents what matched', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Matched ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'Ticket #'.$ticket->id,
        ]))
        ->assertRedirect(route('dashboard.tickets.show', $ticket))
        ->assertSessionHas('support_code_lookup_result', 'Matched ticket reference Ticket #'.$ticket->id.'.');

    $this->actingAs($agent)
        ->withSession(['support_code_lookup_result' => 'Matched ticket reference Ticket #'.$ticket->id.'.'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Matched ticket reference Ticket #'.$ticket->id.'.');
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

test('agents can jump to a visible ticket from pasted ticket context', function (string $ticketReference): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Pasted ticket reference',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => str_replace('{ticket}', (string) $ticket->id, $ticketReference),
        ]))
        ->assertRedirect(route('dashboard.tickets.show', $ticket));
})->with([
    'ticket prose' => ['Could you check Ticket #{ticket} from the handoff?'],
    'ticket URL' => ['https://wayfindr.example/dashboard/tickets/{ticket}?from=email'],
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

test('successful visitor id lookups tell agents what matched', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-match-context',
        'external_id' => 'customer-match-context',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'customer-match-context',
        ]))
        ->assertRedirect(route('dashboard.visitors.show', $visitor))
        ->assertSessionHas('support_code_lookup_result', 'Matched visitor ID customer-match-context.');

    $this->actingAs($agent)
        ->withSession(['support_code_lookup_result' => 'Matched visitor ID customer-match-context.'])
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('Matched visitor ID customer-match-context.');
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

test('explicit visitor support lookup does not treat numeric host visitor id as a ticket reference', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-numeric-host-collision',
        'external_id' => '12345',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'id' => 12345,
            'subject' => 'Unrelated numeric ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'reference_type' => 'visitor',
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

test('support record lookup does not treat arbitrary prose numbers as ticket references', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'id' => 12345,
            'subject' => 'Numeric ticket that needs an explicit reference',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.support-code.lookup', [
            'support_code' => 'Customer order 12345 needs help',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('support_code_lookup_status', 'No visible support record found for Customer order 12345 needs help.');
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

test('support lookup misses give agents recovery guidance on the dashboard', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->withSession(['support_code_lookup_status' => 'No visible support record found for WF-MISSING.'])
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No visible support record found for WF-MISSING.')
        ->assertSee('Try a support code like WF-ABC123, a ticket reference like Ticket #123, or a visitor ID.')
        ->assertSee('Records outside your support access stay hidden.');
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
