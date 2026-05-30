<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('dashboard shows conversation read state for the signed in agent', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-reader']);

    $unreadConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-UNREAD1',
        'subject' => 'Needs eyes',
        'status' => 'open',
    ]);
    $unreadMessage = ConversationMessage::factory()->for($unreadConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone look at this?',
        'created_at' => now()->subMinute(),
    ]);
    $unreadConversation->forceFill(['last_message_at' => $unreadMessage->created_at])->save();

    DB::table('conversation_read_states')->insert([
        'conversation_id' => $unreadConversation->id,
        'user_id' => $otherAgent->id,
        'last_read_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $seenConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SEEN01',
        'subject' => 'Already reviewed',
        'status' => 'open',
    ]);
    $seenMessage = ConversationMessage::factory()->for($seenConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This one was already reviewed.',
        'created_at' => now()->subMinutes(5),
    ]);
    $seenConversation->forceFill(['last_message_at' => $seenMessage->created_at])->save();

    DB::table('conversation_read_states')->insert([
        'conversation_id' => $seenConversation->id,
        'user_id' => $agent->id,
        'last_read_at' => now()->subMinute(),
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Read')
        ->assertSeeInOrder(['Needs eyes', 'New activity'])
        ->assertSeeInOrder(['Already reviewed', 'Seen']);
});

test('opening a conversation marks it read for the signed in agent', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-open']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-READIT',
        'subject' => 'Open marks seen',
        'status' => 'open',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Please read me.',
        'created_at' => now()->subMinutes(3),
    ]);
    $conversation->forceFill(['last_message_at' => $message->created_at])->save();

    $readAt = now()->addMinute()->startOfSecond();

    $this->travelTo($readAt);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-READIT')
        ->assertOk();

    $recordedReadAt = DB::table('conversation_read_states')
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $agent->id)
        ->value('last_read_at');

    expect($recordedReadAt)->not->toBeNull();
    expect(Carbon::parse($recordedReadAt)->equalTo($readAt))->toBeTrue();

    $this->get('/dashboard')
        ->assertOk()
        ->assertSeeInOrder(['Open marks seen', 'Seen'])
        ->assertDontSee('New activity');
});

test('new visitor messages after the agent read marker restore new activity state', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-return']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RETURN',
        'subject' => 'Visitor came back',
        'status' => 'open',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Original question.',
        'created_at' => now()->subMinutes(10),
    ]);

    DB::table('conversation_read_states')->insert([
        'conversation_id' => $conversation->id,
        'user_id' => $agent->id,
        'last_read_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $newMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Actually, I have one more thing.',
        'created_at' => now()->subMinute(),
    ]);
    $conversation->forceFill(['last_message_at' => $newMessage->created_at])->save();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeInOrder(['Visitor came back', 'New activity']);
});

test('ticket replies mark the linked conversation read for the sending agent', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-ticket']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKET1',
        'subject' => 'Ticket-linked conversation',
        'status' => 'open',
    ]);
    $visitorMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I need help from a ticket.',
        'created_at' => now()->subMinutes(5),
    ]);
    $conversation->forceFill(['last_message_at' => $visitorMessage->created_at])->save();
    $conversation->markReadFor($agent, now()->subMinutes(3));

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Ticket-linked conversation',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/replies", [
            'message' => 'Replying from the ticket.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}");

    $reply = $conversation->messages()->latest('id')->firstOrFail();
    $recordedReadAt = DB::table('conversation_read_states')
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $agent->id)
        ->value('last_read_at');

    expect($recordedReadAt)->not->toBeNull();
    expect(Carbon::parse($recordedReadAt)->greaterThanOrEqualTo($reply->created_at))->toBeTrue();

    $this->get('/dashboard')
        ->assertOk()
        ->assertSeeInOrder(['Ticket-linked conversation', 'Seen'])
        ->assertDontSee('New activity');
});
