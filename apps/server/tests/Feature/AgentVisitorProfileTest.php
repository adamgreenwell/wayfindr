<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('agent can view a safe visitor profile with same-site support history', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-profile',
        'external_id' => 'customer-123',
        'last_seen_at' => now()->subMinutes(11),
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/account',
            'context' => [
                'plan' => 'Team',
                'support_region' => 'EU',
                'password' => 'super-secret',
            ],
        ],
    ]);
    $otherVisitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);

    $currentConversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-PROFILE1',
            'subject' => 'Current profile question',
            'metadata' => [
                'started_page_url' => 'https://docs.example.test/pricing',
            ],
        ]);
    Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-PRIORPROFILE',
            'subject' => 'Earlier profile question',
            'created_at' => now()->subDays(3),
            'last_message_at' => now()->subDays(2),
        ]);
    Conversation::factory()
        ->for($site)
        ->for($otherVisitor)
        ->create([
            'support_code' => 'WF-OTHERPROFILE',
            'subject' => 'Other visitor question',
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($currentConversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Current profile ticket',
            'status' => 'open',
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Prior profile ticket',
            'status' => 'pending',
            'updated_at' => now()->subDay(),
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($otherVisitor, 'requester')
        ->create([
            'subject' => 'Other visitor ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('Visitor profile')
        ->assertSee('Safe context only')
        ->assertSee('anon-profile')
        ->assertSee('Host visitor ID')
        ->assertSee('customer-123')
        ->assertSee('Last seen')
        ->assertSee('11 minutes ago')
        ->assertSee('Latest page')
        ->assertSee('https://docs.example.test/account')
        ->assertSee('First captured entry page')
        ->assertSee('https://docs.example.test/pricing')
        ->assertSee('Data boundary')
        ->assertSee('Use this page to understand the support trail. Do not collect, export, or infer extra visitor data without consent.')
        ->assertSee('Host context')
        ->assertSee('plan')
        ->assertSee('Team')
        ->assertSee('support_region')
        ->assertSee('EU')
        ->assertDontSee('super-secret')
        ->assertSee('Support history')
        ->assertSee('Current profile question')
        ->assertSee('WF-PROFILE1')
        ->assertSee('Earlier profile question')
        ->assertSee('WF-PRIORPROFILE')
        ->assertSee('Current profile ticket')
        ->assertSee('Prior profile ticket')
        ->assertSee('Pending')
        ->assertDontSee('Other visitor question')
        ->assertDontSee('Other visitor ticket');
});

test('visitor profile finds the first entry page beyond the recent history limit', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-long-history',
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/current',
        ],
    ]);

    Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-FIRSTENTRY',
            'subject' => 'Original entry page question',
            'metadata' => [
                'started_page_url' => 'https://docs.example.test/original-entry',
            ],
            'created_at' => now()->subDays(30),
            'last_message_at' => now()->subDays(30),
        ]);

    foreach (range(1, 11) as $index) {
        Conversation::factory()
            ->for($site)
            ->for($visitor)
            ->create([
                'support_code' => 'WF-RECENT'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'subject' => 'Recent profile question '.$index,
                'created_at' => now()->subDays(12 - $index),
                'last_message_at' => now()->subDays(12 - $index),
            ]);
    }

    $this->actingAs($agent)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertOk()
        ->assertSee('First captured entry page')
        ->assertSee('https://docs.example.test/original-entry');
});

test('conversation and ticket context panels link to the visitor profile', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-linked']);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-VISITORLINK',
            'subject' => 'Profile link question',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Profile link ticket',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('Open visitor profile')
        ->assertSee(route('dashboard.visitors.show', $visitor), false);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Open visitor profile')
        ->assertSee(route('dashboard.visitors.show', $visitor), false);
});

test('visitor profile respects site support access', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $allowedAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $blockedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Restricted Docs']);
    $site->supportAgents()->attach($allowedAgent);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-restricted']);

    $this->actingAs($blockedAgent)
        ->get(route('dashboard.visitors.show', $visitor))
        ->assertNotFound();
});
