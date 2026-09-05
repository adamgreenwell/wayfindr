<?php

use App\Broadcasting\AccountAgentAlertChannel;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Events\AgentAlertStored;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\AgentAlertPayload;
use App\Support\AgentAlertRealtimeConfig;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('notifications are indexed for recipient alert reconciliation', function (): void {
    $indexes = collect(Schema::getIndexes('notifications'))->pluck('columns');

    expect($indexes)->toContain([
        'notifiable_type',
        'notifiable_id',
        'updated_at',
        'id',
    ]);
});

test('agent alert realtime config uses the existing browser transport and recipient channel', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:05Z'));
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.options.client_host', 'desk.example.test');
    config()->set('broadcasting.connections.reverb.options.client_port', '443');
    config()->set('broadcasting.connections.reverb.options.client_scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => ['sound' => true],
    ]);

    try {
        expect(AgentAlertRealtimeConfig::forAgent($agent))->toBe([
            'appKey' => 'reverb-key',
            'authEndpoint' => 'http://localhost:8000/broadcasting/auth',
            'channelName' => sprintf('private-accounts.%d.agents.%d.alerts', $account->id, $agent->id),
            'eventName' => 'agent.alert.stored',
            'host' => 'desk.example.test',
            'identityChannelName' => 'presence-agents.'.$agent->id,
            'knownAlerts' => [],
            'port' => '443',
            'reconcileEndpoint' => 'http://localhost:8000/dashboard/alerts/reconcile',
            'reconcileOverlapSeconds' => 30,
            'reconcileSince' => '2026-09-05T11:59:35.000000Z',
            'scheme' => 'https',
            'soundEnabled' => true,
        ]);

        config()->set('broadcasting.default', 'null');

        expect(AgentAlertRealtimeConfig::forAgent($agent))->toBeNull();
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('agent alert realtime config is unavailable after alert permission is revoked', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
    config()->set('broadcasting.connections.reverb.options.port', '8080');
    config()->set('broadcasting.connections.reverb.options.scheme', 'http');

    $account = Account::factory()->create();
    $noAlertsRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $noAlertsRole->id,
    ]);

    expect(AgentAlertRealtimeConfig::forAgent($agent))->toBeNull();
});

test('agent alert realtime config remembers visible alert versions already present at its boundary', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:05Z'));
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
    config()->set('broadcasting.connections.reverb.options.port', '8080');
    config()->set('broadcasting.connections.reverb.options.scheme', 'http');
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    try {
        $firstAlert = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
        ]);
        $firstAlert->timestamps = false;
        $firstAlert->forceFill(['updated_at' => CarbonImmutable::parse('2026-09-05T11:59:36Z')])->saveQuietly();

        $lastAlert = null;

        foreach (range(2, 501) as $sequence) {
            $lastAlert = databaseAlertFor($agent, [
                'kind' => 'conversation_needs_reply',
                'conversation_id' => $conversation->id,
                'sequence' => $sequence,
            ]);
        }

        $outsideOverlap = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
        ]);
        $outsideOverlap->timestamps = false;
        $outsideOverlap->forceFill(['updated_at' => CarbonImmutable::parse('2026-09-05T11:59:34Z')])->saveQuietly();

        $knownAlerts = collect(AgentAlertRealtimeConfig::forAgent($agent)['knownAlerts'] ?? []);

        expect($knownAlerts)->toHaveCount(501)
            ->and($knownAlerts->pluck('version'))->toContain(
                AgentAlertPayload::version($firstAlert->fresh()),
                AgentAlertPayload::version($lastAlert),
            )
            ->not->toContain(AgentAlertPayload::version($outsideOverlap->fresh()));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

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
            'alert' => AgentAlertPayload::for($alert),
        ])
        ->and($event->broadcastWhen())->toBeTrue();
});

test('alert payload versions change when a batched alert is refreshed within the same second', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:05Z'));
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    try {
        $alert = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
            'message_count' => 1,
        ]);
        $firstVersion = AgentAlertPayload::version($alert);
        $alert->forceFill(['data' => array_merge($alert->data, ['message_count' => 2])])->save();

        expect($alert->fresh()->updated_at?->toJSON())->toBe($alert->created_at?->toJSON())
            ->and(AgentAlertPayload::version($alert->fresh()))->not->toBe($firstVersion);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('alert reconciliation returns only recipient alerts that are current and still visible', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:05Z'));
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewAlerts->value,
            AccountPermission::ViewConversations->value,
        ],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $otherAgent = User::factory()->for($account)->create();
    $visibleSite = Site::factory()->for($account)->create();
    $hiddenSite = Site::factory()->for($account)->create();
    $visibleSite->supportAgents()->attach($agent);
    $hiddenSite->supportAgents()->attach($otherAgent);
    $visibleVisitor = Visitor::factory()->for($visibleSite)->create();
    $hiddenVisitor = Visitor::factory()->for($hiddenSite)->create();
    $visibleConversation = Conversation::factory()->for($visibleSite)->for($visibleVisitor)->create();
    $hiddenConversation = Conversation::factory()->for($hiddenSite)->for($hiddenVisitor)->create();

    try {
        $oldAlert = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $visibleConversation->id,
        ]);
        $oldAlert->forceFill([
            'created_at' => CarbonImmutable::parse('2026-09-05T11:59:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-09-05T11:59:00Z'),
        ]);
        $oldAlert->timestamps = false;
        $oldAlert->saveQuietly();
        $visibleAlert = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $visibleConversation->id,
        ]);
        databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $hiddenConversation->id,
        ]);
        databaseAlertFor($otherAgent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $visibleConversation->id,
        ]);

        $this->actingAs($agent)
            ->getJson(route('dashboard.alerts.reconcile', [
                'since' => '2026-09-05T12:00:00Z',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.alerts')
            ->assertJsonPath('data.alerts.0.id', (string) $visibleAlert->id)
            ->assertJsonPath('data.alerts.0.version', AgentAlertPayload::version($visibleAlert))
            ->assertJsonPath('data.next_cursor', null)
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.watermark', '2026-09-05T12:00:05.000000Z');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('alert reconciliation pages a fixed outage window without skipping its backlog', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:05Z'));
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $expectedIds = collect();

    try {
        foreach (range(1, 105) as $sequence) {
            $expectedIds->push((string) databaseAlertFor($agent, [
                'kind' => 'conversation_needs_reply',
                'conversation_id' => $conversation->id,
                'sequence' => $sequence,
            ])->id);
        }

        $receivedIds = collect();
        $parameters = ['since' => '2026-09-05T12:00:00Z'];
        $pages = 0;

        do {
            $response = $this->actingAs($agent)
                ->getJson(route('dashboard.alerts.reconcile', $parameters))
                ->assertOk();
            $data = $response->json('data');
            $pages++;
            $receivedIds->push(...collect($data['alerts'])->pluck('id'));

            if ($data['next_cursor'] !== null) {
                expect($data['truncated'])->toBeTrue();
                $parameters = [
                    'since' => '2026-09-05T12:00:00Z',
                    'through' => $data['watermark'],
                    'cursor' => $data['next_cursor'],
                ];
            }
        } while ($data['next_cursor'] !== null);

        expect($pages)->toBe(3)
            ->and($data['truncated'])->toBeFalse()
            ->and($data['watermark'])->toBe('2026-09-05T12:00:05.000000Z')
            ->and($receivedIds->sort()->values()->all())->toBe($expectedIds->sort()->values()->all());
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('alert reconciliation requires alert access', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);

    $this->actingAs($agent)
        ->getJson(route('dashboard.alerts.reconcile', ['since' => now()->toJSON()]))
        ->assertForbidden();
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
