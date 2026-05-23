<?php

use App\Models\Account;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard lists open conversations for the agent account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create();

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ACME123',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => now()->subMinute(),
    ]);

    $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CLOSED1',
        'subject' => 'Closed conversation',
        'status' => 'closed',
    ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
        'subject' => 'Other account problem',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Conversations')
        ->assertSee('Checkout trouble')
        ->assertSee('Acme Docs')
        ->assertSee('anon-acme')
        ->assertSee('WF-ACME123')
        ->assertDontSee($closedConversation->subject)
        ->assertDontSee('Other account problem')
        ->assertDontSee('Other Docs');
});

test('dashboard shows an empty conversation state', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No active conversations yet.');
});

test('dashboard shows ready realtime status when reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Ready')
        ->assertSee('Reverb broadcasts are configured.')
        ->assertSee('Broadcast driver')
        ->assertSee('reverb')
        ->assertSee('wayfindr.test:443')
        ->assertSee('https')
        ->assertSee('App ID')
        ->assertSee('Secret')
        ->assertSee('Set');
});

test('dashboard shows realtime setup guidance when reverb is incomplete', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', null);
    config()->set('broadcasting.connections.reverb.secret', null);
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', null);
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Needs setup')
        ->assertSee('Add Reverb app credentials and public host settings before enabling live updates.')
        ->assertSee('App key')
        ->assertSee('Missing')
        ->assertSee('App ID')
        ->assertSee('Set')
        ->assertSee('Secret')
        ->assertSee('Missing')
        ->assertSee('Endpoint')
        ->assertSee('Incomplete');
});

test('dashboard shows realtime disabled when broadcasting is not using reverb', function (): void {
    config()->set('broadcasting.default', 'log');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Disabled')
        ->assertSee('Set BROADCAST_CONNECTION=reverb to deliver live updates.')
        ->assertSee('Broadcast driver')
        ->assertSee('log');
});

test('agent can view their account conversation timeline', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAIL1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'First visitor message.',
        'created_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'First agent note.',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-DETAIL1')
        ->assertOk()
        ->assertSee('Checkout trouble')
        ->assertSee('Acme Docs')
        ->assertSee('anon-acme')
        ->assertSee('WF-DETAIL1')
        ->assertSee('Send reply')
        ->assertSee('name="body"', false)
        ->assertSeeInOrder(['First visitor message.', 'First agent note.']);
});

test('agent can see cobrowse consent state on a conversation', function (?array $sessionAttributes, string $label, string $message): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-COBROWSE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    if ($sessionAttributes) {
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create($sessionAttributes);
    }

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-COBROWSE')
        ->assertOk()
        ->assertSee('Cobrowse')
        ->assertSee($label)
        ->assertSee($message);
})->with([
    'unavailable' => [
        null,
        'Unavailable',
        'Visitor has not granted cobrowse consent.',
    ],
    'pending' => [
        ['status' => 'requested', 'consented_at' => null, 'ended_at' => null],
        'Pending consent',
        'Waiting for visitor consent before cobrowsing can start.',
    ],
    'granted' => [
        ['status' => 'granted', 'consented_at' => now()->subMinute(), 'ended_at' => null],
        'Granted',
        'Visitor granted cobrowse consent.',
    ],
    'revoked' => [
        ['status' => 'revoked', 'consented_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()],
        'Revoked',
        'Visitor revoked cobrowse consent.',
    ],
]);

test('agent can see cobrowse telemetry on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TELEMETRY',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'telemetry' => [
                'rtt_ms' => 184,
                'max_rtt_ms' => 240,
                'payload_bytes' => 8192,
                'max_payload_bytes' => 16384,
                'dropped_batches' => 2,
                'reconnects' => 1,
                'samples' => 5,
                'reported_at' => now()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TELEMETRY')
        ->assertOk()
        ->assertSee('Connection telemetry')
        ->assertSee('RTT')
        ->assertSee('184 ms')
        ->assertSee('Max RTT')
        ->assertSee('240 ms')
        ->assertSee('Payload')
        ->assertSee('8,192 bytes')
        ->assertSee('Max payload')
        ->assertSee('16,384 bytes')
        ->assertSee('Dropped batches')
        ->assertSee('2')
        ->assertSee('Reconnects')
        ->assertSee('1')
        ->assertSee('Samples')
        ->assertSee('5');
});

test('agent can reply to their account conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-REPLY1/messages', [
            'body' => 'Thanks, I can help.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLY1');

    $this->assertDatabaseHas('conversation_messages', [
        'conversation_id' => $conversation->id,
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'type' => 'text',
        'body' => 'Thanks, I can help.',
    ]);

    expect($conversation->fresh()->last_message_at->greaterThan(now()->subMinutes(2)))->toBeTrue();

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REPLY1')
        ->assertOk()
        ->assertSee('Ada Agent')
        ->assertSeeInOrder(['The checkout button is stuck.', 'Thanks, I can help.']);
});

test('agent reply requires a message body', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY2',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REPLY2')
        ->post('/dashboard/conversations/WF-REPLY2/messages', [
            'body' => '',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLY2')
        ->assertSessionHasErrors('body');

    $this->assertDatabaseCount('conversation_messages', 0);
});

test('agent cannot reply to another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-OTHER1/messages', [
            'body' => 'Can you see this?',
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('conversation_messages', [
        'body' => 'Can you see this?',
    ]);
});

test('agent cannot view another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-OTHER1')
        ->assertNotFound();
});
