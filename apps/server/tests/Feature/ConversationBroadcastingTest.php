<?php

use App\Broadcasting\ConversationChannel;
use App\Events\CobrowseStateUpdated;
use App\Events\ConversationMessageCreated;
use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationReadReceiptUpdated;
use App\Events\ConversationTypingUpdated;
use App\Models\Account;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('conversation message broadcasts use a private conversation channel and safe payload', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BROADCAST',
        'status' => 'open',
    ]);

    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'type' => 'text',
        'body' => 'Can someone see this?',
    ]);

    $event = new ConversationMessageCreated($message->load(['conversation', 'sender']));
    $channels = $event->broadcastOn();

    expect($event)
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('conversation.message.created')
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-conversations.WF-BROADCAST')
        ->and($event->broadcastWith())->toMatchArray([
            'conversation' => [
                'support_code' => 'WF-BROADCAST',
                'status' => 'open',
            ],
            'message' => [
                'id' => $message->id,
                'sender' => [
                    'kind' => 'visitor',
                    'name' => 'Visitor',
                ],
                'type' => 'text',
                'body' => 'Can someone see this?',
                'created_at' => $message->created_at?->toJSON(),
            ],
        ]);
});

test('cobrowse state updates use a private conversation channel and safe payload', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-COBROWSE-LIVE',
        'status' => 'open',
    ]);
    $reportedAt = now()->toJSON();
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'metadata' => [
            'page_state' => [
                'page_url' => 'https://docs.example.test/install',
                'title' => 'Install Guide',
                'reported_at' => $reportedAt,
            ],
            'snapshot' => [
                'html' => '<main><p>Public copy.</p></main>',
                'text' => 'Public copy.',
                'reported_at' => $reportedAt,
            ],
            'mutations' => [
                'recent_batches' => [
                    ['mutations' => [['type' => 'text', 'text' => 'Fresh copy.']]],
                ],
            ],
        ],
    ]);

    expect(class_exists(CobrowseStateUpdated::class))->toBeTrue();

    $event = new CobrowseStateUpdated($session->load('conversation'), 'snapshot');
    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event)
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('conversation.cobrowse.updated')
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-conversations.WF-COBROWSE-LIVE')
        ->and($payload)->toMatchArray([
            'conversation' => [
                'support_code' => 'WF-COBROWSE-LIVE',
                'status' => 'open',
            ],
            'cobrowse' => [
                'status' => 'granted',
            ],
            'update' => [
                'kind' => 'snapshot',
                'reported_at' => $reportedAt,
            ],
        ])
        ->and($payload['summary']['title'])->toBe('Install Guide')
        ->and($payload['summary']['page_url'])->toBe('https://docs.example.test/install')
        ->and(json_encode($payload))->not->toContain('<main>')
        ->and(json_encode($payload))->not->toContain('Fresh copy.');
});

test('cobrowse telemetry broadcasts safe transport and resync summary hints', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TELEMETRY-LIVE',
        'status' => 'open',
    ]);
    $reportedAt = now()->subSeconds(20)->toJSON();
    $mutationReportedAt = now()->subSeconds(15)->toJSON();
    $exhaustedAt = now()->toJSON();
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'metadata' => [
            'snapshot' => [
                'html' => '<main><p>Sensitive page copy should stay local.</p></main>',
                'text' => 'Sensitive page copy should stay local.',
                'reported_at' => $reportedAt,
            ],
            'mutations' => [
                'recent_batches' => [
                    [
                        'dropped_count' => 1,
                        'skipped_count' => 3,
                        'reported_at' => $mutationReportedAt,
                        'mutations' => [['type' => 'text', 'text' => 'Private mutation body.']],
                    ],
                ],
            ],
            'telemetry' => [
                'rtt_ms' => 240,
                'max_rtt_ms' => 480,
                'payload_bytes' => 8192,
                'max_payload_bytes' => 16384,
                'dropped_batches' => 2,
                'reconnects' => 1,
                'samples' => 4,
                'reported_at' => $reportedAt,
                'resync_request_id' => 'resync_exhausted',
                'resync_attempts_exhausted' => true,
            ],
            'resync_request' => [
                'id' => 'resync_exhausted',
                'requested_at' => now()->subMinute()->toJSON(),
                'attempts_exhausted_at' => $exhaustedAt,
                'fulfilled_at' => null,
            ],
        ],
    ]);

    $event = new CobrowseStateUpdated($session->load('conversation'), 'telemetry');
    $payload = $event->broadcastWith();

    expect($payload)->toMatchArray([
        'conversation' => [
            'support_code' => 'WF-TELEMETRY-LIVE',
            'status' => 'open',
        ],
        'cobrowse' => [
            'status' => 'granted',
        ],
        'update' => [
            'kind' => 'telemetry',
            'reported_at' => $exhaustedAt,
        ],
        'summary' => [
            'resync_request_id' => 'resync_exhausted',
            'transport_pressure' => [
                'dropped_batches' => 3,
                'skipped_mutations' => 3,
                'reported_at' => $mutationReportedAt,
            ],
            'telemetry' => [
                'rtt_ms' => 240,
                'max_rtt_ms' => 480,
                'payload_bytes' => 8192,
                'max_payload_bytes' => 16384,
                'dropped_batches' => 2,
                'reconnects' => 1,
                'samples' => 4,
                'reported_at' => $reportedAt,
                'resync_attempts_exhausted' => true,
            ],
        ],
    ])
        ->and(json_encode($payload))->not->toContain('<main>')
        ->and(json_encode($payload))->not->toContain('Sensitive page copy')
        ->and(json_encode($payload))->not->toContain('Private mutation body');
});

test('cobrowse broadcasts do not carry stale resync exhaustion onto replacement requests', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RESYNC-FRESH',
        'status' => 'open',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'metadata' => [
            'telemetry' => [
                'rtt_ms' => 240,
                'resync_request_id' => 'resync_old',
                'resync_attempts_exhausted' => true,
            ],
            'resync_request' => [
                'id' => 'resync_new',
                'requested_at' => now()->toJSON(),
                'fulfilled_at' => null,
            ],
        ],
    ]);

    $payload = (new CobrowseStateUpdated($session->load('conversation'), 'resync_requested'))->broadcastWith();

    expect($payload['summary']['resync_request_id'])->toBe('resync_new')
        ->and($payload['summary']['telemetry'])->toHaveKey('rtt_ms')
        ->and($payload['summary']['telemetry'])->not->toHaveKey('resync_attempts_exhausted');
});

test('conversation typing updates use a private conversation channel and safe payload', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $typingAt = now()->toJSON();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TYPING-LIVE',
        'status' => 'open',
        'metadata' => [
            'visitor_typing_at' => $typingAt,
            'agent_typing' => [
                (string) $agent->id => [
                    'at' => $typingAt,
                    'name' => 'Ada Agent',
                ],
            ],
        ],
    ]);

    $event = new ConversationTypingUpdated($conversation->fresh());
    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($event)
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('conversation.typing.updated')
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-conversations.WF-TYPING-LIVE')
        ->and($payload)->toMatchArray([
            'conversation' => [
                'support_code' => 'WF-TYPING-LIVE',
                'status' => 'open',
            ],
            'agent_typing' => [
                'state' => 'typing',
                'label' => 'Support is typing...',
                'updated_at' => $typingAt,
            ],
            'visitor_typing' => [
                'state' => 'typing',
                'label' => 'Typing now',
                'updated_at' => $typingAt,
            ],
        ])
        ->and(json_encode($payload))->not->toContain('Ada Agent');
});

test('conversation presence updates use a private conversation channel and safe payload', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create();
        $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-docs',
            'last_seen_at' => now(),
        ]);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-PRESENCE',
            'status' => 'open',
        ]);

        expect(class_exists(ConversationPresenceUpdated::class))->toBeTrue();

        $event = new ConversationPresenceUpdated($conversation->load('visitor'));
        $channels = $event->broadcastOn();
        $payload = $event->broadcastWith();

        expect($event)
            ->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastAs())->toBe('conversation.presence.updated')
            ->and($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($channels[0]->name)->toBe('private-conversations.WF-PRESENCE')
            ->and($payload)->toMatchArray([
                'conversation' => [
                    'support_code' => 'WF-PRESENCE',
                    'status' => 'open',
                ],
                'visitor_presence' => [
                    'state' => 'active',
                    'label' => 'Active recently',
                    'detail' => 'Seen in the last 2 minutes',
                    'last_seen_at' => now()->toJSON(),
                    'last_seen_label' => '0 seconds ago',
                ],
            ])
            ->and(json_encode($payload))->not->toContain('anon-docs');
    } finally {
        Carbon::setTestNow();
    }
});

test('conversation read receipt updates use a private conversation channel and safe payload', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create();
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-READ-LIVE',
            'status' => 'open',
        ]);

        $message = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Try refreshing the billing page.',
            'created_at' => now()->subMinute(),
            'seen_at' => now(),
        ]);

        expect(class_exists(ConversationReadReceiptUpdated::class))->toBeTrue();

        $event = new ConversationReadReceiptUpdated($conversation->load('latestAgentMessage'));
        $channels = $event->broadcastOn();
        $payload = $event->broadcastWith();

        expect($event)
            ->toBeInstanceOf(ShouldBroadcastNow::class)
            ->and($event->broadcastAs())->toBe('conversation.read.updated')
            ->and($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and($channels[0]->name)->toBe('private-conversations.WF-READ-LIVE')
            ->and($payload)->toMatchArray([
                'conversation' => [
                    'support_code' => 'WF-READ-LIVE',
                    'status' => 'open',
                ],
                'visitor_read' => [
                    'message_id' => $message->id,
                    'state' => 'seen',
                    'label' => 'Visitor saw reply',
                    'detail' => 'Seen 0 seconds ago',
                    'seen_at' => now()->toJSON(),
                    'seen_label' => '0 seconds ago',
                ],
            ])
            ->and(json_encode($payload))->not->toContain('Try refreshing')
            ->and(json_encode($payload))->not->toContain('anon-docs')
            ->and(json_encode($payload))->not->toContain('Ada Agent');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent replies dispatch conversation message broadcasts', function (): void {
    Event::fake([ConversationMessageCreated::class]);

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AGENT1',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-AGENT1/messages', [
            'body' => 'I can help with that.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-AGENT1');

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->conversation_id === $conversation->id
            && $event->message->sender_type === User::class
            && $event->message->sender_id === $agent->id
            && $event->message->body === 'I can help with that.',
    );
});

test('visitor cobrowse updates dispatch cobrowse state broadcasts', function (string $endpoint, array $payload, string $kind): void {
    Event::fake([CobrowseStateUpdated::class]);

    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-COBROWSE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    $this->postJson("/api/conversations/WF-COBROWSE/{$endpoint}", array_merge([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
    ], $payload))->assertOk();

    Event::assertDispatched(
        CobrowseStateUpdated::class,
        fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->id === $session->id
            && $event->kind === $kind,
    );
})->with([
    'page state' => [
        'cobrowse-page-state',
        [
            'page_url' => 'https://docs.example.test/install',
            'title' => 'Install Guide',
            'viewport_width' => 1280,
            'viewport_height' => 720,
            'scroll_x' => 0,
            'scroll_y' => 220,
            'visibility_state' => 'visible',
            'focused' => true,
        ],
        'page_state',
    ],
    'snapshot' => [
        'cobrowse-snapshot',
        [
            'page_url' => 'https://docs.example.test/install',
            'title' => 'Install Guide',
            'html' => '<main><p>Public copy.</p></main>',
            'text' => 'Public copy.',
            'node_count' => 2,
            'masked_count' => 0,
        ],
        'snapshot',
    ],
    'mutations' => [
        'cobrowse-mutations',
        [
            'page_url' => 'https://docs.example.test/install',
            'sequence' => 1,
            'dropped_count' => 0,
            'skipped_count' => 0,
            'mutations' => [
                [
                    'type' => 'text',
                    'path' => 'body:nth-of-type(1) > main:nth-of-type(1) > p:nth-of-type(1)',
                    'text' => 'Fresh copy.',
                ],
            ],
        ],
        'mutations',
    ],
    'telemetry' => [
        'cobrowse-telemetry',
        [
            'rtt_ms' => 120,
            'payload_bytes' => 2048,
            'dropped_batches' => 1,
            'reconnects' => 2,
        ],
        'telemetry',
    ],
]);

test('visitor messages dispatch conversation message broadcasts', function (): void {
    Event::fake([ConversationMessageCreated::class]);

    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-VISITOR',
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    $this->postJson('/api/conversations/WF-VISITOR/messages', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'Hello from the widget.',
    ])
        ->assertCreated();

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->conversation_id === $conversation->id
            && $event->message->sender_type === Visitor::class
            && $event->message->sender_id === $visitor->id
            && $event->message->body === 'Hello from the widget.',
    );
});

test('visitor broadcast auth signs their private conversation channel', function (): void {
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    Broadcast::purge('reverb');

    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVE',
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    $response = $this->postJson('/api/widget/broadcasting/auth', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'socket_id' => '1234.5678',
        'channel_name' => 'private-conversations.WF-LIVE',
    ]);

    $signature = hash_hmac('sha256', '1234.5678:private-conversations.WF-LIVE', 'reverb-secret');

    $response
        ->assertOk()
        ->assertJson([
            'auth' => 'reverb-key:'.$signature,
        ]);
});

test('visitor broadcast auth rejects another visitors conversation channel', function (): void {
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    Broadcast::purge('reverb');

    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $otherVisitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVE',
    ]);
    $token = app(VisitorSessionToken::class)->issue($site, $otherVisitor);

    $this->postJson('/api/widget/broadcasting/auth', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-other',
        'visitor_token' => $token,
        'socket_id' => '1234.5678',
        'channel_name' => 'private-conversations.WF-LIVE',
    ])->assertForbidden();
});

test('conversation channel authorizes account agents and matching visitors', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($otherAccount)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $otherVisitor = Visitor::factory()->for($site)->create();

    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CHANNEL',
    ]);

    $channel = app(ConversationChannel::class);

    expect($channel->join($agent, $conversation->support_code))->toBeTrue()
        ->and($channel->join($otherAgent, $conversation->support_code))->toBeFalse()
        ->and($channel->join($visitor, $conversation->support_code))->toBeTrue()
        ->and($channel->join($otherVisitor, $conversation->support_code))->toBeFalse()
        ->and($channel->join($agent, 'WF-MISSING'))->toBeFalse();
});

test('conversation channel denies deactivated agents with stale sessions', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DEACTIVATED',
    ]);

    $channel = app(ConversationChannel::class);

    expect($channel->join($agent, $conversation->support_code))->toBeFalse();
});
