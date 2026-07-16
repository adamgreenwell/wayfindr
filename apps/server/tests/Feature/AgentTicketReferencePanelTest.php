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
        ->assertSee('aria-label="Copy ticket reference Ticket #'.$ticket->id.'"', false)
        ->assertSee('data-copy-value="Ticket #'.$ticket->id.'"', false)
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
        '<section class="section" aria-labelledby="ticket-work-state-heading">',
    );

    expect($ticketHeader)
        ->toContain('Ticket #'.$ticket->id)
        ->toContain('WF-REFERENCE')
        ->toContain(route('dashboard.conversations.show', $conversation->support_code))
        ->toContain('Copy support code WF-REFERENCE');
});

test('ticket detail organizes the workspace into tabs', function (): void {
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

    $response = $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        // The tabbed workspace replaces the old "ticket map" jump list: the
        // agent's work is the default tab and everything else is one click
        // away instead of a 50-section scroll.
        ->assertDontSee('Ticket map')
        ->assertSee('role="tablist"', false)
        ->assertSeeInOrder([
            'data-tab="work"',
            'data-tab="conversation"',
            'data-tab="external"',
            'data-tab="details"',
            'data-tab="activity"',
        ], false)
        ->assertSeeInOrder([
            'data-tab-panel="work"',
            'data-tab-panel="conversation"',
            'data-tab-panel="external"',
            'data-tab-panel="details"',
            'data-tab-panel="activity"',
        ], false)
        // The linked conversation's support code badges the tab itself.
        ->assertSee('WF-MAP')
        // Legacy anchors survive inside the panels for deep links.
        ->assertSee('id="ticket-work-state-heading"', false)
        ->assertSee('id="linked-conversation-heading"', false)
        ->assertSee('id="external-links-heading"', false)
        ->assertSee('id="ticket-notes-heading"', false)
        ->assertSee('id="ticket-activity-heading"', false);

    // The work panel is the active default; the rest start hidden.
    $html = $response->getContent();
    expect(preg_match('/data-tab-panel="work"[^>]*hidden/', $html))->toBe(0)
        ->and(preg_match('/hidden[^>]*data-tab-panel="activity"|data-tab-panel="activity"[^>]*hidden/', $html))->toBe(1);
});

test('ticket detail guides agents through empty workspaces', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Sparse ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('No labels on this ticket yet.')
        ->assertSee('No external issue links yet.')
        ->assertSee('Attach an external reference only when another tracker owns part of the follow-up. Wayfindr can stay the source of truth when it does not.')
        ->assertSee('No internal notes yet.')
        ->assertSee('Use notes for private handoff context, not customer-visible replies.')
        ->assertSee('No ticket activity yet.')
        ->assertSee('Lifecycle, assignment, label, reply, and external-link updates will appear here once the team works the ticket.');
});

test('ticket detail guides agents through manual external references', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Manual external reference',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Manual external reference')
        ->assertSee('Attach an existing issue only when another tracker owns part of the follow-up.')
        ->assertSee('Use stable issue URLs, project keys, and issue IDs so teammates can find the handoff later.')
        ->assertSee('Keep raw visitor data, transcripts, cobrowse snapshots, and internal notes in Wayfindr unless an agent deliberately summarizes them.')
        ->assertSee('Manual references do not push data to the provider.');
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
        ->assertSee('aria-label="Copy ticket reference Ticket #'.$ticket->id.'"', false)
        ->assertSee('data-copy-value="Ticket #'.$ticket->id.'"', false)
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
