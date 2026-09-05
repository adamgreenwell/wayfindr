<?php

use App\Events\AgentAlertStored;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\AgentAlertBroadcaster;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

test('agent alert broadcasts run immediately without a transaction and only after a commit', function (): void {
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

    Event::fake([AgentAlertStored::class]);
    $broadcaster->stored($agent, $alert);
    Event::assertDispatchedTimes(AgentAlertStored::class, 1);

    Event::fake([AgentAlertStored::class]);
    DB::beginTransaction();
    $broadcaster->stored($agent, $alert);
    Event::assertNotDispatched(AgentAlertStored::class);
    DB::rollBack();
    Event::assertNotDispatched(AgentAlertStored::class);

    DB::beginTransaction();
    $broadcaster->stored($agent, $alert);
    Event::assertNotDispatched(AgentAlertStored::class);
    DB::commit();
    Event::assertDispatched(
        AgentAlertStored::class,
        fn (AgentAlertStored $event): bool => (string) $event->alert->id === (string) $alert->id
            && (int) $event->recipient->id === (int) $agent->id,
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
