<?php

// The live-transcript refresh endpoint. The agent conversation page listens for
// conversation.message.created and refetches this partial so new visitor
// messages append without a reload, and a reconnecting socket catches up on
// anything missed. It must honour the same 'view' authorization as the full
// page and stay a pure read.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function messagesEndpointConversation(Account $account): Conversation
{
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    return Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVE001',
        'status' => 'open',
    ]);
}

test('an agent gets the rendered transcript with per-message ids', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $conversation = messagesEndpointConversation($account);

    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $conversation->visitor_id,
        'body' => 'The live transcript should show this.',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.messages.index', $conversation->support_code))
        ->assertOk()
        ->assertSee('The live transcript should show this.')
        // The client dedups/scrolls by this id, so it must be present.
        ->assertSee('data-message-id="'.$message->id.'"', false);
});

test('the endpoint renders the empty state when there are no messages', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $conversation = messagesEndpointConversation($account);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.messages.index', $conversation->support_code))
        ->assertOk()
        ->assertSee('No messages yet.');
});

test('an agent from another account cannot read the transcript', function (): void {
    $account = Account::factory()->create();
    $conversation = messagesEndpointConversation($account);

    $otherAgent = User::factory()->for(Account::factory())->create();

    $this->actingAs($otherAgent)
        ->get(route('dashboard.conversations.messages.index', $conversation->support_code))
        ->assertNotFound();
});

test('the transcript endpoint does not mark the conversation read', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $conversation = messagesEndpointConversation($account);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $conversation->visitor_id,
        'body' => 'Unread on purpose.',
    ]);

    // No read state exists yet; a frequently-polled refresh must not create one
    // (that side effect belongs to the full page view, not this endpoint).
    expect($conversation->readStateFor($agent))->toBeNull();

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.messages.index', $conversation->support_code))
        ->assertOk();

    expect($conversation->fresh()->readStateFor($agent))->toBeNull();
});
