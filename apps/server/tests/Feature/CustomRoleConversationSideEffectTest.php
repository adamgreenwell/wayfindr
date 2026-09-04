<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a reply only role can answer an open conversation without claiming it', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ReplyToConversations->value,
        ],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY-ONLY',
        'assigned_agent_id' => null,
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), [
            'body' => 'A bounded reply.',
        ])
        ->assertRedirect();

    expect($conversation->messages()->where('body', 'A bounded reply.')->exists())->toBeTrue()
        ->and($conversation->fresh()->assigned_agent_id)->toBeNull()
        ->and($conversation->fresh()->status)->toBe('open');
});

test('a reply only role cannot reopen a closed conversation by replying', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ReplyToConversations->value,
        ],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY-CLOSED',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), [
            'body' => 'An unauthorized reopen.',
        ])
        ->assertNotFound();

    expect($conversation->messages()->exists())->toBeFalse()
        ->and($conversation->fresh()->status)->toBe('closed')
        ->and($conversation->fresh()->closed_at)->not->toBeNull();
});

test('a ticket manager can create a ticket without claiming its conversation', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ManageTickets->value,
        ],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKET-ONLY',
        'assigned_agent_id' => null,
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.tickets.store', $conversation->support_code))
        ->assertRedirect();

    expect(Ticket::query()->where('conversation_id', $conversation->id)->exists())->toBeTrue()
        ->and($conversation->fresh()->assigned_agent_id)->toBeNull();
});
