<?php

use App\Broadcasting\AccountAgentAlertChannel;
use App\Enums\AccountRole;
use App\Events\AgentAlertStored;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the account agent alert channel admits only its active recipient with alert access', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $crossAccountAgent = User::factory()->for($otherAccount)->create();
    $noAlertsRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $noAlertsAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $noAlertsRole->id,
    ]);
    $channel = new AccountAgentAlertChannel;

    expect($channel->join($agent, $account->id, $agent->id))->toBeTrue()
        ->and($channel->join($agent, $account->id, $otherAgent->id))->toBeFalse()
        ->and($channel->join($agent, $otherAccount->id, $agent->id))->toBeFalse()
        ->and($channel->join($crossAccountAgent, $account->id, $agent->id))->toBeFalse()
        ->and($channel->join($noAlertsAgent, $account->id, $noAlertsAgent->id))->toBeFalse()
        ->and($channel->join($agent, 'not-an-id', $agent->id))->toBeFalse()
        ->and($channel->join($agent, $account->id, 'not-an-id'))->toBeFalse();

    $agent->forceFill(['deactivated_at' => now()])->save();

    expect($channel->join($agent->fresh(), $account->id, $agent->id))->toBeFalse();
});

test('agent broadcast auth signs only the recipients account alert channel', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    Broadcast::purge('reverb');
    Broadcast::connection('reverb')->channel(
        'accounts.{accountId}.agents.{agentId}.alerts',
        AccountAgentAlertChannel::class,
    );

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $channelName = sprintf('private-accounts.%d.agents.%d.alerts', $account->id, $agent->id);
    $response = $this->actingAs($agent)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channelName,
    ]);
    $signature = hash_hmac('sha256', '1234.5678:'.$channelName, 'reverb-secret');

    $response->assertOk()->assertJson(['auth' => 'reverb-key:'.$signature]);

    $other = User::factory()->for($account)->create();

    $this->actingAs($other)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => $channelName,
    ])->assertForbidden();
});

test('stored agent alerts broadcast the existing database payload on the recipient channel', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = databaseAlertFor($agent, [
        'kind' => 'conversation_needs_reply',
        'conversation_id' => $conversation->id,
        'support_code' => 'WF-LIVE-ALERT',
        'message_preview' => 'The visitor-authored preview.',
        'url' => '/dashboard/conversations/WF-LIVE-ALERT',
    ]);
    $event = new AgentAlertStored($agent, $alert);
    $channels = $event->broadcastOn();

    expect($event)
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('agent.alert.stored')
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe(sprintf(
            'private-accounts.%d.agents.%d.alerts',
            $account->id,
            $agent->id,
        ))
        ->and($event->broadcastWith())->toBe([
            'alert' => [
                'id' => (string) $alert->id,
                'data' => $alert->data,
                'created_at' => $alert->created_at?->toJSON(),
                'updated_at' => $alert->updated_at?->toJSON(),
            ],
        ])
        ->and($event->broadcastWhen())->toBeTrue();
});

test('stored alert broadcasts recheck current recipient access and ownership', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = databaseAlertFor($agent, [
        'kind' => 'conversation_needs_reply',
        'conversation_id' => $conversation->id,
    ]);
    $event = new AgentAlertStored($agent, $alert);
    $noAlertsRole = CustomRole::factory()->for($account)->create(['permissions' => []]);

    User::query()->whereKey($agent->id)->update(['custom_role_id' => $noAlertsRole->id]);

    expect($event->broadcastWhen())->toBeFalse();

    User::query()->whereKey($agent->id)->update(['custom_role_id' => null]);
    DatabaseNotification::query()->whereKey($alert->id)->delete();

    expect($event->broadcastWhen())->toBeFalse();
});

/** @param array<string, mixed> $data */
function databaseAlertFor(User $agent, array $data): DatabaseNotification
{
    /** @var DatabaseNotification $notification */
    $notification = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ConversationNeedsReply::class,
        'data' => $data,
        'read_at' => null,
    ]);

    return $notification;
}
