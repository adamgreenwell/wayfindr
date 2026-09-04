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

test('support replies reauthorize a stale custom role under the account lock', function (string $surface): void {
    $account = Account::factory()->create();
    $replyRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ReplyToConversations->value,
            AccountPermission::ManageTickets->value,
        ],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $replyRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STALE-REPLY',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create();

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::ReplyToConversations))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $before = $conversation->fresh()->getRawOriginal();

    $response = match ($surface) {
        'conversation' => $this->post(route('dashboard.conversations.messages.store', $conversation->support_code), [
            'body' => 'This must not be sent.',
        ]),
        'ticket' => $this->post(route('dashboard.tickets.replies.store', $ticket), [
            'message' => 'This must not be sent.',
        ]),
    };

    $response->assertNotFound();

    expect($conversation->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('conversation_messages', 0);
    $this->assertDatabaseCount('audit_events', 0);
})->with(['conversation', 'ticket']);
