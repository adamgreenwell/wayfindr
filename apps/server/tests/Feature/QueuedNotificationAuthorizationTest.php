<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('queued support emails recheck role and site access before delivery', function (string $notificationType, string $revocation): void {
    $account = Account::factory()->create();
    $alertRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewAlerts->value,
            AccountPermission::ViewConversations->value,
            AccountPermission::ManageTickets->value,
        ],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $recipient = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $alertRole->id,
        'alert_preferences' => [
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
            'mode' => User::ALERT_MODE_ALL,
        ],
    ]);
    $remainingAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($recipient);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $recipient->id,
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($recipient, 'assignee')
        ->create();
    $notification = $notificationType === 'conversation'
        ? new ConversationNeedsReply($message)
        : new TicketAssigned($ticket, $remainingAgent);

    expect($notification->shouldSend($recipient, 'mail'))->toBeTrue();

    if ($revocation === 'role') {
        User::query()->whereKey($recipient->id)->update(['custom_role_id' => $revokedRole->id]);
    } else {
        $site->supportAgents()->sync([$remainingAgent->id]);
    }

    expect($notification->shouldSend($recipient, 'mail'))->toBeFalse();
})->with([
    ['conversation', 'role'],
    ['conversation', 'site'],
    ['ticket', 'role'],
    ['ticket', 'site'],
]);
