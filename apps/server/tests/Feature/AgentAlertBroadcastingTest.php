<?php

use App\Broadcasting\AccountAgentAlertChannel;
use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Events\AgentAlertStored;
use App\Jobs\BroadcastReconciledAgentAlert;
use App\Jobs\ReconcileAgentAlertPublicationsAfterDrain;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\AgentAlertBroadcaster;
use App\Support\AgentAlertPayload;
use App\Support\AgentAlertPublicationFingerprint;
use App\Support\AgentAlertPublicationSweep;
use App\Support\AgentAlertRealtimeConfig;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('notifications are indexed for recipient alert reconciliation', function (): void {
    $indexes = collect(Schema::getIndexes('notifications'))->pluck('columns');

    expect($indexes)->toContain([
        'notifiable_type',
        'notifiable_id',
        'agent_alerted_at',
        'id',
    ])
        ->and(Schema::hasColumns('notifications', [
            'agent_alerted_at',
            'agent_alert_version',
            'agent_alert_broadcast_claim_version',
            'agent_alert_broadcast_pending_version',
            'agent_alert_fingerprint',
        ]))->toBeTrue();
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
        $firstAlert->forceFill(['agent_alerted_at' => CarbonImmutable::parse('2026-09-05T11:59:36Z')])->saveQuietly();

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
        $outsideOverlap->forceFill(['agent_alerted_at' => CarbonImmutable::parse('2026-09-05T11:59:34Z')])->saveQuietly();

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
        app(AgentAlertBroadcaster::class)->stored($agent, $alert);

        expect($alert->fresh()->updated_at?->toJSON())->toBe($alert->created_at?->toJSON())
            ->and(AgentAlertPayload::version($alert->fresh()))->not->toBe($firstVersion);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('read state and email bookkeeping do not create reconciliation alert versions', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:00Z'));
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();

    try {
        $alert = databaseAlertFor($agent, [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
        ]);
        $version = AgentAlertPayload::version($alert);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:10Z'));
        $alert->markAsRead();
        $alert->forceFill([
            'data' => [...$alert->data, 'unattended_emailed_at' => now()->toISOString()],
        ])->save();

        expect(AgentAlertPayload::version($alert->fresh()))->toBe($version);

        $this->actingAs($agent)
            ->getJson(route('dashboard.alerts.reconcile', [
                'since' => '2026-09-05T12:00:09Z',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.alerts');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('publication sweep closes the zero downtime writer window without replaying bookkeeping', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:00Z'));
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
        $initialVersion = AgentAlertPayload::version($alert);

        // The previous release refreshes the payload after the migration's
        // first pass, without knowing about publication metadata.
        DB::table('notifications')->where('id', $alert->id)->update([
            'data' => json_encode([...$alert->data, 'message_count' => 2]),
            'updated_at' => CarbonImmutable::parse('2026-09-05T12:00:05Z'),
        ]);

        expect(AgentAlertPublicationSweep::run())->toBe(1);

        $refreshed = $alert->fresh();

        expect(AgentAlertPayload::alertedAt($refreshed)?->toJSON())->toBe('2026-09-05T12:00:05.000000Z')
            ->and(AgentAlertPayload::version($refreshed))->not->toBe($initialVersion)
            ->and($refreshed->getAttribute('agent_alert_fingerprint'))->toBe(
                AgentAlertPublicationFingerprint::for($refreshed->data),
            );

        // Read and delivery metadata can also land during activation. They do
        // not change the alert-bearing fingerprint and must stay silent.
        DB::table('notifications')->where('id', $alert->id)->update([
            'data' => json_encode([
                ...$refreshed->data,
                'unattended_emailed_at' => '2026-09-05T12:00:06Z',
                'digest_queued_at' => '2026-09-05T12:00:07Z',
            ]),
            'read_at' => CarbonImmutable::parse('2026-09-05T12:00:08Z'),
            'updated_at' => CarbonImmutable::parse('2026-09-05T12:00:08Z'),
        ]);

        expect(AgentAlertPublicationSweep::run())->toBe(0)
            ->and(AgentAlertPayload::version($alert->fresh()))->toBe(AgentAlertPayload::version($refreshed))
            ->and(AgentAlertPayload::alertedAt($alert->fresh())?->toJSON())->toBe('2026-09-05T12:00:05.000000Z');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('a swept refresh remains claimable by a live listener exactly once', function (): void {
    Event::fake([AgentAlertStored::class]);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = databaseAlertFor($agent, [
        'kind' => 'conversation_needs_reply',
        'conversation_id' => $conversation->id,
        'message_count' => 1,
    ]);
    $originalClaimedVersion = $alert->getAttribute('agent_alert_broadcast_claim_version');

    DB::table('notifications')->where('id', $alert->id)->update([
        'data' => json_encode([...$alert->data, 'message_count' => 2]),
        'updated_at' => now(),
    ]);

    expect(AgentAlertPublicationSweep::run())->toBe(1);

    $swept = $alert->fresh();
    $sweptVersion = $swept->getAttribute('agent_alert_version');

    expect($sweptVersion)->not->toBe($originalClaimedVersion)
        ->and($swept->getAttribute('agent_alert_broadcast_claim_version'))->toBe($originalClaimedVersion);

    app(AgentAlertBroadcaster::class)->stored($agent, $swept);
    app(AgentAlertBroadcaster::class)->stored($agent, $alert->fresh());

    Event::assertDispatchedTimes(AgentAlertStored::class, 1);

    $event = Event::dispatched(AgentAlertStored::class)->sole()[0];

    expect(AgentAlertPayload::version($event->alert))->toBe($sweptVersion)
        ->and($alert->fresh()->getAttribute('agent_alert_broadcast_claim_version'))->toBe($sweptVersion);
});

test('publication sweep backfills alerts inserted by the previous release', function (): void {
    Queue::fake();
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $id = (string) Str::uuid();
    $data = ['kind' => 'conversation_needs_reply', 'message_count' => 1];

    // Omit all four new fields exactly as the pre-deploy code does. The
    // database timestamp default prevents a permanent reconciliation gap.
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => ConversationNeedsReply::class,
        'notifiable_type' => $agent->getMorphClass(),
        'notifiable_id' => $agent->id,
        'data' => json_encode($data),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('notifications')->where('id', $id)->value('agent_alerted_at'))->not->toBeNull();

    $this->artisan('wayfindr:reconcile-agent-alert-publications')
        ->expectsOutput('Reconciled 1 agent alert publication(s).')
        ->assertSuccessful();

    $alert = DatabaseNotification::query()->findOrFail($id);

    expect(AgentAlertPayload::version($alert))->toBe($id)
        ->and($alert->getAttribute('agent_alert_broadcast_pending_version'))->toBe($id)
        ->and($alert->getAttribute('agent_alert_fingerprint'))->toBe(AgentAlertPublicationFingerprint::for($data));

    Queue::assertPushed(
        BroadcastReconciledAgentAlert::class,
        fn (BroadcastReconciledAgentAlert $job): bool => $job->notificationId === $id,
    );
});

test('a first live publication keeps the version already exposed by concurrent reconciliation', function (): void {
    Event::fake([AgentAlertStored::class]);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $id = (string) Str::uuid();
    $since = now()->subMinute()->toJSON();
    $data = [
        'kind' => 'conversation_needs_reply',
        'conversation_id' => $conversation->id,
        'message_count' => 1,
    ];

    // This is the normal insert shape: the database default makes the row
    // catch-up-visible before NotificationSent claims its metadata.
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => ConversationNeedsReply::class,
        'notifiable_type' => $agent->getMorphClass(),
        'notifiable_id' => $agent->id,
        'data' => json_encode($data),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A rolling-deploy sweep may win the row lock before the synchronous
    // current-release listener. It records the fingerprint for future legacy
    // refresh detection but must leave first live delivery claimable.
    expect(AgentAlertPublicationSweep::run())->toBe(1);

    $preclaimed = DatabaseNotification::query()->findOrFail($id);

    expect($preclaimed->getAttribute('agent_alert_version'))->toBe($id)
        ->and($preclaimed->getAttribute('agent_alert_broadcast_claim_version'))->toBeNull()
        ->and($preclaimed->getAttribute('agent_alert_fingerprint'))->toBe(
            AgentAlertPublicationFingerprint::for($data),
        );

    $catchUpVersion = $this->actingAs($agent)
        ->getJson(route('dashboard.alerts.reconcile', ['since' => $since]))
        ->assertOk()
        ->json('data.alerts.0.version');

    $alert = DatabaseNotification::query()->findOrFail($id);
    app(AgentAlertBroadcaster::class)->stored($agent, $alert);

    Event::assertDispatchedTimes(AgentAlertStored::class, 1);

    $liveEvent = Event::dispatched(AgentAlertStored::class)->sole()[0];

    expect($catchUpVersion)->toBe($id)
        ->and(AgentAlertPayload::version($liveEvent->alert))->toBe($catchUpVersion)
        ->and(AgentAlertPayload::version($alert->fresh()))->toBe($catchUpVersion)
        ->and($alert->fresh()->getAttribute('agent_alert_broadcast_claim_version'))->toBe($catchUpVersion);
});

test('publication compatibility sweep remains scheduled for late old workers', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($commands->filter(
        fn (string $command): bool => str_contains($command, 'wayfindr:reconcile-agent-alert-publications'),
    ))->toHaveCount(1);
});

test('deploys can queue a final publication sweep after old workers drain', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05T12:00:00Z'));

    try {
        $this->artisan('wayfindr:reconcile-agent-alert-publications --after-worker-drain')
            ->expectsOutput('Queued a final agent alert publication pass for 120 seconds after activation.')
            ->assertSuccessful();

        Queue::assertPushed(
            ReconcileAgentAlertPublicationsAfterDrain::class,
            fn (ReconcileAgentAlertPublicationsAfterDrain $job): bool => $job->delay?->equalTo(
                CarbonImmutable::parse('2026-09-05T12:02:00Z'),
            ) === true,
        );
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('the post-drain sweep publishes old-writer alerts to already-connected agents', function (): void {
    Queue::fake();
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $id = (string) Str::uuid();
    $data = [
        'kind' => 'conversation_needs_reply',
        'conversation_id' => $conversation->id,
        'message_count' => 1,
    ];

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => ConversationNeedsReply::class,
        'notifiable_type' => $agent->getMorphClass(),
        'notifiable_id' => $agent->id,
        'data' => json_encode($data),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ReconcileAgentAlertPublicationsAfterDrain)->handle();

    Queue::assertPushed(
        BroadcastReconciledAgentAlert::class,
        fn (BroadcastReconciledAgentAlert $job): bool => $job->notificationId === $id,
    );

    $pending = DatabaseNotification::query()->findOrFail($id);

    expect($pending->getAttribute('agent_alert_broadcast_pending_version'))->toBe($id)
        ->and($pending->getAttribute('agent_alert_broadcast_claim_version'))->toBeNull();

    Event::listen(AgentAlertStored::class, function (): never {
        throw new RuntimeException('Synthetic Reverb failure.');
    });

    expect(fn () => (new BroadcastReconciledAgentAlert($id))->handle(app(AgentAlertBroadcaster::class)))
        ->toThrow(RuntimeException::class, 'Synthetic Reverb failure.');

    $failed = DatabaseNotification::query()->findOrFail($id);

    expect($failed->getAttribute('agent_alert_broadcast_pending_version'))->toBe($id)
        ->and($failed->getAttribute('agent_alert_broadcast_claim_version'))->toBeNull();

    Event::fake([AgentAlertStored::class]);

    (new BroadcastReconciledAgentAlert($id))->handle(app(AgentAlertBroadcaster::class));
    (new BroadcastReconciledAgentAlert($id))->handle(app(AgentAlertBroadcaster::class));

    Event::assertDispatchedTimes(AgentAlertStored::class, 1);
    Event::assertDispatched(
        AgentAlertStored::class,
        fn (AgentAlertStored $event): bool => (string) $event->alert->id === $id,
    );

    $alert = DatabaseNotification::query()->findOrFail($id);

    expect($alert->getAttribute('agent_alert_version'))->toBe($id)
        ->and($alert->getAttribute('agent_alert_broadcast_claim_version'))->toBe($id)
        ->and($alert->getAttribute('agent_alert_broadcast_pending_version'))->toBeNull()
        ->and($alert->getAttribute('agent_alert_fingerprint'))->toBe(
            AgentAlertPublicationFingerprint::for($data),
        );
});

test('a failed publication enqueue remains pending for the cursor retry', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $alert = databaseAlertFor($agent, [
        'kind' => 'conversation_needs_reply',
        'message_count' => 1,
    ]);
    $originalClaimedVersion = $alert->getAttribute('agent_alert_broadcast_claim_version');

    DB::table('notifications')->where('id', $alert->id)->update([
        'data' => json_encode([...$alert->data, 'message_count' => 2]),
        'updated_at' => now(),
    ]);

    expect(fn () => AgentAlertPublicationSweep::runBatch(
        null,
        ReconcileAgentAlertPublicationsAfterDrain::BATCH_SIZE,
        true,
        function (): never {
            throw new RuntimeException('Synthetic queue failure.');
        },
    ))->toThrow(RuntimeException::class, 'Synthetic queue failure.');

    $pending = $alert->fresh();
    $pendingVersion = $pending->getAttribute('agent_alert_broadcast_pending_version');

    expect($pendingVersion)->toBeString()->not->toBe($originalClaimedVersion)
        ->and($pending->getAttribute('agent_alert_version'))->toBe($pendingVersion)
        ->and($pending->getAttribute('agent_alert_broadcast_claim_version'))->toBe($originalClaimedVersion);

    $enqueued = [];
    $retry = AgentAlertPublicationSweep::runBatch(
        null,
        ReconcileAgentAlertPublicationsAfterDrain::BATCH_SIZE,
        true,
        function (string $notificationId) use (&$enqueued): void {
            $enqueued[] = $notificationId;
        },
    );

    expect($retry['reconciled'])->toBe(0)
        ->and($enqueued)->toBe([(string) $alert->id]);
});

test('the post-drain sweep continues through bounded notification pages', function (): void {
    Queue::fake();
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $data = ['kind' => 'conversation_needs_reply', 'message_count' => 1];
    $fingerprint = AgentAlertPublicationFingerprint::for($data);
    $rows = collect(range(1, ReconcileAgentAlertPublicationsAfterDrain::BATCH_SIZE + 1))
        ->map(function (int $index) use ($agent, $data, $fingerprint): array {
            $id = sprintf('00000000-0000-4000-8000-%012d', $index);

            return [
                'id' => $id,
                'type' => ConversationNeedsReply::class,
                'notifiable_type' => $agent->getMorphClass(),
                'notifiable_id' => $agent->id,
                'data' => json_encode($data),
                'read_at' => null,
                'agent_alerted_at' => now(),
                'agent_alert_version' => $id,
                'agent_alert_broadcast_claim_version' => $id,
                'agent_alert_fingerprint' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })
        ->all();

    DB::table('notifications')->insert($rows);

    (new ReconcileAgentAlertPublicationsAfterDrain)->handle();

    $expectedCursor = sprintf(
        '00000000-0000-4000-8000-%012d',
        ReconcileAgentAlertPublicationsAfterDrain::BATCH_SIZE,
    );

    Queue::assertPushed(
        ReconcileAgentAlertPublicationsAfterDrain::class,
        fn (ReconcileAgentAlertPublicationsAfterDrain $job): bool => $job->afterId() === $expectedCursor,
    );

    (new ReconcileAgentAlertPublicationsAfterDrain($expectedCursor))->handle();

    Queue::assertPushedTimes(ReconcileAgentAlertPublicationsAfterDrain::class, 1);
});

test('Forge recipes request the delayed publication pass after restarting workers', function (string $recipe): void {
    $script = file_get_contents(base_path('../../deploy/forge/'.$recipe));
    $restart = strpos($script, 'forge_php artisan queue:restart');
    $immediate = strpos($script, 'forge_php artisan wayfindr:reconcile-agent-alert-publications', $restart);
    $delayed = strpos(
        $script,
        'forge_php artisan wayfindr:reconcile-agent-alert-publications --after-worker-drain',
        $immediate + 1,
    );

    expect($restart)->toBeInt()
        ->and($immediate)->toBeInt()->toBeGreaterThan($restart)
        ->and($delayed)->toBeInt()->toBeGreaterThan($immediate);
})->with([
    'standard-deploy.sh',
    'zero-downtime-deploy.forge',
]);

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
            'agent_alerted_at' => CarbonImmutable::parse('2026-09-05T11:59:00Z'),
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
    $version = (string) Str::uuid();

    /** @var DatabaseNotification $notification */
    $notification = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ConversationNeedsReply::class,
        'data' => $data,
        'read_at' => null,
        'agent_alerted_at' => now(),
        'agent_alert_version' => $version,
        'agent_alert_broadcast_claim_version' => $version,
        'agent_alert_fingerprint' => AgentAlertPublicationFingerprint::for($data),
    ]);

    return $notification;
}
