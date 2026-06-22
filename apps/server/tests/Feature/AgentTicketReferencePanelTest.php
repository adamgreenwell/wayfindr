<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ticket detail shows a compact support reference for linked tickets', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-reference',
        'email' => 'visitor@example.test',
    ]);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-REFERENCE',
            'subject' => 'Reference lookup',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'metadata' => [
                'visitor_context' => [
                    'last_page_url' => 'https://docs.example.test/account',
                ],
            ],
            'subject' => 'Reference ticket',
        ]);

    $response = $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support reference')
        ->assertSee('Ticket reference')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Support code')
        ->assertSee('WF-REFERENCE')
        ->assertSee(route('dashboard.conversations.show', $conversation->support_code), false)
        ->assertSee('Acme Docs')
        ->assertSee('visitor@example.test')
        ->assertSee('Latest visitor page')
        ->assertSee('https://docs.example.test/account');

    $ticketHeader = Str::between(
        $response->content(),
        '<h1>Reference ticket</h1>',
        '<section class="section" aria-labelledby="ticket-map-heading">',
    );

    expect($ticketHeader)
        ->toContain('Ticket #'.$ticket->id)
        ->toContain('WF-REFERENCE')
        ->toContain(route('dashboard.conversations.show', $conversation->support_code))
        ->toContain('Copy support code WF-REFERENCE');
});

test('ticket detail gives agents a section map for available workspaces', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-map',
        'last_seen_at' => now()->subMinutes(12),
    ]);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-MAP',
            'subject' => 'Mapped conversation',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Mapped ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Ticket map')
        ->assertSee('href="#ticket-work-state-heading"', false)
        ->assertSee('href="#ticket-reference-heading"', false)
        ->assertSee('href="#ticket-labels-heading"', false)
        ->assertSee('href="#ticket-timeline-heading"', false)
        ->assertSee('href="#linked-conversation-heading"', false)
        ->assertSee('href="#ticket-reply"', false)
        ->assertSee('href="#external-links-heading"', false)
        ->assertSee('href="#ticket-visitor-context-heading"', false)
        ->assertSee('href="#ticket-actions-heading"', false)
        ->assertSee('href="#ticket-details-heading"', false)
        ->assertSee('href="#ticket-notes-heading"', false)
        ->assertSee('href="#ticket-activity-heading"', false);
});

test('ticket detail reference handles standalone tickets without a linked conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Standalone ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support reference')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Support code')
        ->assertSee('No linked conversation')
        ->assertSee('Requester')
        ->assertSee('Not linked')
        ->assertSee('Latest visitor page')
        ->assertSee('Not reported');
});

test('ticket detail shows safe visitor context and prior support records', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-context',
        'external_id' => 'customer-123',
        'last_seen_at' => now()->subMinutes(8),
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/account',
            'context' => [
                'plan' => 'Team',
                'password' => 'super-secret',
            ],
        ],
    ]);
    $otherVisitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-CURRENTCTX',
            'subject' => 'Current context ticket',
            'metadata' => [
                'started_page_url' => 'https://docs.example.test/pricing',
            ],
        ]);
    Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-PRIORCTX',
            'subject' => 'Earlier context question',
            'created_at' => now()->subDays(3),
            'last_message_at' => now()->subDays(2),
        ]);
    Conversation::factory()
        ->for($site)
        ->for($otherVisitor)
        ->create([
            'support_code' => 'WF-OTHERCTX',
            'subject' => 'Other visitor question',
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Prior requester ticket',
            'status' => 'pending',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($otherVisitor, 'requester')
        ->create([
            'subject' => 'Other visitor ticket',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'metadata' => [
                'visitor_context' => [
                    'host_context' => [],
                    'started_page_url' => 'https://docs.example.test/pricing',
                ],
            ],
            'subject' => 'Current requester ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Visitor at a glance')
        ->assertSee('Safe context only')
        ->assertSee('anon-context')
        ->assertSee('Host visitor ID')
        ->assertSee('customer-123')
        ->assertSee('Last seen')
        ->assertSee('8 minutes ago')
        ->assertSee('Latest page')
        ->assertSee('https://docs.example.test/account')
        ->assertSee('Entry page')
        ->assertSee('https://docs.example.test/pricing')
        ->assertSee('Host context')
        ->assertSee('plan')
        ->assertSee('Team')
        ->assertDontSee('super-secret')
        ->assertSee('Prior support records')
        ->assertSee('Earlier context question')
        ->assertSee('WF-PRIORCTX')
        ->assertSee('Prior requester ticket')
        ->assertSee('Pending')
        ->assertDontSee('Other visitor question')
        ->assertDontSee('Other visitor ticket');
});
