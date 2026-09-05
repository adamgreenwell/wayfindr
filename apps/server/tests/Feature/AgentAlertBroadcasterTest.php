<?php

use App\Events\AgentAlertStored;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\AgentAlertBroadcaster;
use App\Support\AgentAlertPublicationFingerprint;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

test('agent alert broadcasts wait for commit and serialize their final send inside a transaction', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ConversationNeedsReply::class,
        'data' => [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
        ],
        'read_at' => null,
    ]);
    $broadcaster = app(AgentAlertBroadcaster::class);
    $dispatchLevels = [];

    Event::listen(AgentAlertStored::class, function () use (&$dispatchLevels): void {
        $dispatchLevels[] = DB::transactionLevel();
    });

    $broadcaster->stored($agent, $alert);
    expect($dispatchLevels)->toBe([1]);

    DB::beginTransaction();
    $alert->forceFill(['data' => [...$alert->data, 'sequence' => 2]])->save();
    $broadcaster->stored($agent, $alert);
    expect($dispatchLevels)->toBe([1]);
    DB::rollBack();
    expect($dispatchLevels)->toBe([1]);

    DB::beginTransaction();
    $alert = $alert->fresh();
    $alert->forceFill(['data' => [...$alert->data, 'sequence' => 3]])->save();
    $broadcaster->stored($agent, $alert);
    expect($dispatchLevels)->toBe([1]);
    DB::commit();
    expect($dispatchLevels)->toBe([1, 1]);
});

test('stale concurrent callbacks publish the current stored state only once', function (): void {
    Event::fake([AgentAlertStored::class]);

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ConversationNeedsReply::class,
        'data' => [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
            'message_count' => 1,
        ],
        'read_at' => null,
    ]);
    $stale = $alert->replicate()->setAttribute('id', $alert->id);

    $alert->forceFill(['data' => [...$alert->data, 'message_count' => 2]])->save();
    $broadcaster = app(AgentAlertBroadcaster::class);

    $broadcaster->stored($agent, $stale);
    $version = $alert->fresh()->getAttribute('agent_alert_version');
    $broadcaster->stored($agent, $alert->fresh());

    Event::assertDispatchedTimes(AgentAlertStored::class, 1);

    $current = $alert->fresh();

    expect($current->getAttribute('agent_alert_version'))->toBe($version)
        ->and($current->getAttribute('agent_alert_fingerprint'))->toBe(
            AgentAlertPublicationFingerprint::for($current->data),
        );
});

test('an obsolete publication claim cannot broadcast a payload committed before its callback reloads', function (): void {
    Event::fake([AgentAlertStored::class]);

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $alert = $agent->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => ConversationNeedsReply::class,
        'data' => [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
            'message_count' => 1,
        ],
        'read_at' => null,
    ]);
    $broadcaster = app(AgentAlertBroadcaster::class);

    // Delay the first after-commit callback, then change the payload after its
    // publication metadata was claimed. The old claim must not publish the new
    // state under its old version before that state gets its own claim.
    DB::beginTransaction();
    $broadcaster->stored($agent, $alert);
    $alert->forceFill(['data' => [...$alert->data, 'message_count' => 2]])->save();
    DB::commit();

    Event::assertNotDispatched(AgentAlertStored::class);

    $broadcaster->stored($agent, $alert->fresh());

    Event::assertDispatchedTimes(AgentAlertStored::class, 1);

    $event = Event::dispatched(AgentAlertStored::class)->sole()[0];
    $current = $alert->fresh();

    expect(data_get($event->alert->data, 'message_count'))->toBe(2)
        ->and($event->alert->getAttribute('agent_alert_version'))->toBe(
            $current->getAttribute('agent_alert_version'),
        )
        ->and($current->getAttribute('agent_alert_fingerprint'))->toBe(
            AgentAlertPublicationFingerprint::for($current->data),
        );
});

test('new and batched conversation alerts both broadcast their durable database state', function (): void {
    Event::fake([AgentAlertStored::class]);

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['public_key' => 'site_alert_stream']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-alert-stream']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-ALERT-STREAM',
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    foreach (['First visitor message.', 'Second visitor message.'] as $body) {
        $this->postJson('/api/conversations/WF-ALERT-STREAM/messages', [
            'site_public_key' => 'site_alert_stream',
            'anonymous_id' => 'anon-alert-stream',
            'visitor_token' => $token,
            'body' => $body,
        ])->assertCreated();
    }

    Event::assertDispatchedTimes(AgentAlertStored::class, 2);

    $events = Event::dispatched(AgentAlertStored::class);

    expect($events->map(fn (array $arguments): int => (int) data_get($arguments[0]->alert->data, 'message_count', 1))->all())
        ->toBe([1, 2])
        ->and($events->every(fn (array $arguments): bool => (int) $arguments[0]->recipient->id === (int) $agent->id))
        ->toBeTrue();
});
