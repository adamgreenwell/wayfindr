<?php

use App\Events\ConversationPresenceUpdated;
use App\Events\ConversationReadReceiptUpdated;
use App\Events\ConversationTypingUpdated;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('widget bootstrap creates a site scoped visitor and returns safe config', function (): void {
    $site = Site::factory()->create([
        'name' => 'Docs Site',
        'domain' => 'docs.example.test',
        'public_key' => 'site_public_docs',
        'settings' => [
            'mask_selectors' => ['input[type="password"]', '[data-secret]'],
            'internal_note' => 'do not leak this',
        ],
    ]);

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-browser-123',
        'page_url' => 'https://docs.example.test/install',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.site.public_key', 'site_public_docs')
        ->assertJsonPath('data.site.name', 'Docs Site')
        ->assertJsonPath('data.site.settings.mask_selectors', ['input[type="password"]', '[data-secret]'])
        ->assertJsonPath('data.visitor.anonymous_id', 'anon-browser-123');

    $payload = $response->json('data');

    expect($payload['site'])
        ->not->toHaveKey('id')
        ->not->toHaveKey('account_id');

    expect($payload['site']['settings'])->not->toHaveKey('internal_note');
    expect($payload['visitor']['token'])->toBeString()->not->toBeEmpty();

    $this->assertDatabaseHas('visitors', [
        'site_id' => $site->id,
        'anonymous_id' => 'anon-browser-123',
    ]);
});

test('widget bootstrap stores safe host context and drops sensitive visitor fields', function (): void {
    $site = Site::factory()->create([
        'public_key' => 'site_public_docs',
    ]);

    $response = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-browser-123',
        'page_url' => 'https://docs.example.test/install',
        'context' => [
            'plan' => 'Team',
            'docs_version' => '2026.05',
            'support_region' => 'EU',
            'username' => 'adam@example.test',
            'password' => 'super-secret',
            'credit_card' => '4111 1111 1111 1111',
            'preferences' => ['nested' => 'nope'],
            'long_note' => str_repeat('a', 220),
        ],
    ]);

    $response->assertCreated();

    $visitor = Visitor::query()
        ->where('site_id', $site->id)
        ->where('anonymous_id', 'anon-browser-123')
        ->firstOrFail();

    expect($visitor->metadata)->toMatchArray([
        'last_page_url' => 'https://docs.example.test/install',
        'context' => [
            'plan' => 'Team',
            'docs_version' => '2026.05',
            'support_region' => 'EU',
            'long_note' => str_repeat('a', 160),
        ],
    ])
        ->and($visitor->metadata['context'])->not->toHaveKey('username')
        ->and($visitor->metadata['context'])->not->toHaveKey('password')
        ->and($visitor->metadata['context'])->not->toHaveKey('credit_card')
        ->and($visitor->metadata['context'])->not->toHaveKey('preferences');
});

test('widget bootstrap stores a safe host visitor identifier', function (): void {
    $site = Site::factory()->create([
        'public_key' => 'site_public_docs',
    ]);

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-browser-123',
        'external_id' => 'customer-123',
    ])->assertCreated();

    $this->assertDatabaseHas('visitors', [
        'site_id' => $site->id,
        'anonymous_id' => 'anon-browser-123',
        'external_id' => 'customer-123',
    ]);
});

test('widget bootstrap ignores sensitive host visitor identifiers', function (): void {
    $site = Site::factory()->create([
        'public_key' => 'site_public_docs',
    ]);

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-browser-123',
        'external_id' => 'ada@example.test',
    ])->assertCreated();

    $this->assertDatabaseHas('visitors', [
        'site_id' => $site->id,
        'anonymous_id' => 'anon-browser-123',
        'external_id' => null,
    ]);
});

test('widget bootstrap rejects an unknown public key', function (): void {
    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'missing_key',
        'anonymous_id' => 'anon-browser-123',
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Site not found.');
});

test('conversation creation uses the site scoped visitor', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $otherSite = Site::factory()->create(['public_key' => 'site_public_other']);

    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'shared-anon']);
    Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'shared-anon']);
    $token = widgetVisitorToken($this, 'site_public_docs', 'shared-anon');

    $response = $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'shared-anon',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
        'page_url' => 'https://docs.example.test/install',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.subject', 'Need help installing')
        ->assertJsonPath('data.visitor.anonymous_id', 'shared-anon');

    $supportCode = $response->json('data.support_code');

    expect($supportCode)->toBeString()->toStartWith('WF-');

    $this->assertDatabaseHas('conversations', [
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'subject' => 'Need help installing',
        'status' => 'open',
    ]);
});

test('conversation creation can refresh safe host context for the visitor', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-docs',
        'metadata' => [
            'context' => [
                'plan' => 'Starter',
            ],
        ],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
        'page_url' => 'https://docs.example.test/install',
        'context' => [
            'plan' => 'Team',
            'account_id' => 'acct_123',
            'session_token' => 'secret-session',
        ],
    ])->assertCreated();

    $visitor = Visitor::query()
        ->where('site_id', $site->id)
        ->where('anonymous_id', 'anon-docs')
        ->firstOrFail();

    expect($visitor->metadata)->toMatchArray([
        'last_page_url' => 'https://docs.example.test/install',
        'context' => [
            'plan' => 'Team',
            'account_id' => 'acct_123',
        ],
    ])
        ->and($visitor->metadata['context'])->not->toHaveKey('session_token');
});

test('conversation creation can refresh a safe host visitor identifier', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-docs',
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'external_id' => 'customer-456',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
    ])->assertCreated();

    $this->assertDatabaseHas('visitors', [
        'site_id' => $site->id,
        'anonymous_id' => 'anon-docs',
        'external_id' => 'customer-456',
    ]);
});

test('conversation creation requires a visitor token', function (): void {
    Site::factory()->create(['public_key' => 'site_public_docs']);

    $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'subject' => 'Need help installing',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Visitor token is required.');
});

test('conversation creation rejects a token for another visitor', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-other');

    $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Visitor token does not match this visitor.');
});

test('conversation creation rejects a token for another site', function (): void {
    Site::factory()->create(['public_key' => 'site_public_docs']);
    Site::factory()->create(['public_key' => 'site_public_other']);
    $token = widgetVisitorToken($this, 'site_public_other', 'anon-other');

    $this->postJson('/api/conversations', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-other',
        'visitor_token' => $token,
        'subject' => 'Need help installing',
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Visitor token does not match this site.');
});

test('visitor message creation cannot cross site boundaries', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $otherSite = Site::factory()->create(['public_key' => 'site_public_other']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BOUNDARY',
    ]);

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_other',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_other', 'anon-other'),
        'body' => 'This should not land in the docs conversation.',
    ])
        ->assertNotFound();

    $this->assertDatabaseMissing('conversation_messages', [
        'conversation_id' => $conversation->id,
        'sender_id' => $otherVisitor->id,
    ]);
});

test('visitor can add a message to their conversation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 11:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-docs',
            'last_seen_at' => now()->subHour(),
        ]);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-MESSAGE',
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');
        $seenAt = Carbon::parse('2026-06-17 12:00:00', 'UTC');
        Carbon::setTestNow($seenAt);

        $response = $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'body' => 'Can you help me with this checkout error?',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.conversation.support_code', 'WF-MESSAGE')
            ->assertJsonPath('data.message.type', 'text')
            ->assertJsonPath('data.message.body', 'Can you help me with this checkout error?');

        $message = ConversationMessage::query()->firstOrFail();

        expect($message->conversation_id)->toBe($conversation->id)
            ->and($message->sender_type)->toBe(Visitor::class)
            ->and($message->sender_id)->toBe($visitor->id)
            ->and($conversation->refresh()->last_message_at)->not->toBeNull()
            ->and($visitor->fresh()->last_seen_at?->toJSON())->toBe($seenAt->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor message fetch broadcasts fresh visitor presence', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-docs',
            'last_seen_at' => now()->subHour(),
        ]);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-PRESENCE',
        ]);

        Event::fake([ConversationPresenceUpdated::class]);

        $response = $this->getJson("/api/conversations/{$conversation->support_code}/messages?".http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.conversation.support_code', 'WF-PRESENCE')
            ->assertJsonPath('data.visitor_presence.state', 'active')
            ->assertJsonPath('data.visitor_presence.label', 'Active recently')
            ->assertJsonPath('data.visitor_presence.detail', 'Seen in the last 2 minutes')
            ->assertJsonPath('data.visitor_presence.last_seen_at', now()->toJSON())
            ->assertJsonPath('data.visitor_presence.last_seen_label', '0 seconds ago');

        Event::assertDispatched(
            ConversationPresenceUpdated::class,
            fn (ConversationPresenceUpdated $event): bool => $event->conversation->id === $conversation->id,
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor message reopens a closed conversation', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REOPEN2',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'body' => 'I am still stuck.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.conversation.support_code', 'WF-REOPEN2')
        ->assertJsonPath('data.conversation.status', 'open');

    $conversation->refresh();

    expect($conversation->status)->toBe('open')
        ->and($conversation->closed_at)->toBeNull()
        ->and($conversation->last_message_at)->not->toBeNull();
});

test('visitor can report a fresh typing signal for their conversation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-docs',
            'last_seen_at' => now()->subHour(),
        ]);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TYPING',
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        Event::fake([
            ConversationPresenceUpdated::class,
            ConversationTypingUpdated::class,
        ]);

        $response = $this->postJson("/api/conversations/{$conversation->support_code}/typing", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'is_typing' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.conversation.support_code', 'WF-TYPING')
            ->assertJsonPath('data.typing.state', 'typing')
            ->assertJsonPath('data.visitor_presence.state', 'active')
            ->assertJsonPath('data.visitor_presence.last_seen_at', now()->toJSON())
            ->assertJsonPath('data.visitor_presence.last_seen_label', '0 seconds ago');

        $conversation->refresh();

        expect($conversation->metadata['visitor_typing_at'])->toBe(now()->toJSON())
            ->and($visitor->fresh()->last_seen_at?->toJSON())->toBe(now()->toJSON());

        Event::assertDispatched(
            ConversationTypingUpdated::class,
            fn (ConversationTypingUpdated $event): bool => $event->conversation->id === $conversation->id,
        );

        Event::assertDispatched(
            ConversationPresenceUpdated::class,
            fn (ConversationPresenceUpdated $event): bool => $event->conversation->id === $conversation->id,
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor typing signal cannot cross site boundaries', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $otherSite = Site::factory()->create(['public_key' => 'site_public_other']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TYPING',
    ]);

    $this->postJson("/api/conversations/{$conversation->support_code}/typing", [
        'site_public_key' => 'site_public_other',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_other', 'anon-other'),
        'is_typing' => true,
    ])->assertNotFound();

    expect($conversation->fresh()->metadata ?? [])->not->toHaveKey('visitor_typing_at');
});

test('visitor message fetch includes only fresh agent typing state', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-AGENTTYPE',
            'metadata' => [
                'agent_typing' => [
                    (string) $agent->id => [
                        'at' => now()->subSeconds(10)->toJSON(),
                        'name' => 'Ada Agent',
                    ],
                ],
            ],
        ]);

        $response = $this->getJson("/api/conversations/{$conversation->support_code}/messages?".http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.agent_typing.state', 'typing')
            ->assertJsonPath('data.agent_typing.label', 'Support is typing...')
            ->assertJsonPath('data.agent_typing.updated_at', now()->subSeconds(10)->toJSON());

        $conversation->forceFill([
            'metadata' => [
                'agent_typing' => [
                    (string) $agent->id => [
                        'at' => now()->subMinute()->toJSON(),
                        'name' => 'Ada Agent',
                    ],
                ],
            ],
        ])->save();

        $this->getJson("/api/conversations/{$conversation->support_code}/messages?".http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]))
            ->assertOk()
            ->assertJsonPath('data.agent_typing.state', 'idle')
            ->assertJsonPath('data.agent_typing.label', null)
            ->assertJsonPath('data.agent_typing.updated_at', null);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor can grant cobrowse consent for their conversation', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-COBROWSE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-consent", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'granted' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-COBROWSE')
        ->assertJsonPath('data.cobrowse.status', 'granted')
        ->assertJsonPath('data.cobrowse.consent', 'granted');

    $session->refresh();

    expect($session->conversation_id)->toBe($conversation->id)
        ->and($session->site_id)->toBe($site->id)
        ->and($session->visitor_id)->toBe($visitor->id)
        ->and($session->status)->toBe('granted')
        ->and($session->consented_at)->not->toBeNull()
        ->and($session->ended_at)->toBeNull();

    $this->assertDatabaseCount('cobrowse_sessions', 1);
});

test('visitor can revoke cobrowse consent for their conversation', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REVOKE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-consent", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'granted' => false,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-REVOKE')
        ->assertJsonPath('data.cobrowse.status', 'revoked')
        ->assertJsonPath('data.cobrowse.consent', 'revoked');

    expect($session->fresh())
        ->status->toBe('revoked')
        ->consented_at->not->toBeNull()
        ->ended_at->not->toBeNull();
});

test('visitor can read their cobrowse request status', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $agent = User::factory()->create(['name' => 'Ada Agent']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STATUS',
    ]);
    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->for($agent, 'requestedBy')->create([
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);

    $response = $this->getJson('/api/conversations/WF-STATUS/cobrowse?'.http_build_query([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-STATUS')
        ->assertJsonPath('data.cobrowse.status', 'requested')
        ->assertJsonPath('data.cobrowse.consent', 'requested')
        ->assertJsonPath('data.cobrowse.requested_by.name', 'Ada Agent')
        ->assertJsonPath('data.cobrowse.consented_at', null)
        ->assertJsonPath('data.cobrowse.ended_at', null);
});

test('visitor cobrowse status includes a pending agent snapshot resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:10:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC',
        ]);
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->for($agent, 'requestedBy')->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_123',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $response = $this->getJson('/api/conversations/WF-RESYNC/cobrowse?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.cobrowse.status', 'granted')
            ->assertJsonPath('data.cobrowse.resync.requested', true)
            ->assertJsonPath('data.cobrowse.resync.request_id', 'resync_123')
            ->assertJsonPath('data.cobrowse.resync.requested_by.name', 'Ada Agent')
            ->assertJsonPath('data.cobrowse.resync.requested_at', now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse status omits expired agent snapshot resync requests', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:10:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-OLD',
        ]);
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->for($agent, 'requestedBy')->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(8),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_old',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(6)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $response = $this->getJson('/api/conversations/WF-RESYNC-OLD/cobrowse?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.cobrowse.status', 'granted')
            ->assertJsonPath('data.cobrowse.resync.requested', false)
            ->assertJsonPath('data.cobrowse.resync.request_id', null)
            ->assertJsonPath('data.cobrowse.resync.requested_at', null);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse status omits exhausted agent snapshot resync requests', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:10:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-EXHAUSTED-STATUS',
        ]);
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->for($agent, 'requestedBy')->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_exhausted',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_at' => null,
                    'attempts_exhausted_at' => now()->subSeconds(5)->toJSON(),
                ],
            ],
        ]);

        $response = $this->getJson('/api/conversations/WF-RESYNC-EXHAUSTED-STATUS/cobrowse?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('data.cobrowse.status', 'granted')
            ->assertJsonPath('data.cobrowse.resync.requested', false)
            ->assertJsonPath('data.cobrowse.resync.request_id', null)
            ->assertJsonPath('data.cobrowse.resync.requested_at', null);
    } finally {
        Carbon::setTestNow();
    }
});

test('cobrowse metadata updates merge against current metadata before saving', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $agent = User::factory()->create(['name' => 'Ada Agent']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RESYNC',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'telemetry' => [
                'samples' => 1,
            ],
        ],
    ]);

    $staleSession = CobrowseSession::query()->findOrFail($session->id);

    $session->forceFill([
        'metadata' => [
            'telemetry' => [
                'samples' => 1,
            ],
            'resync_request' => [
                'id' => 'resync_123',
                'requested_by_id' => $agent->id,
                'requested_by_name' => 'Ada Agent',
                'requested_at' => now()->toJSON(),
                'fulfilled_at' => null,
            ],
        ],
    ])->save();

    $staleSession->updateMetadataAtomically(function (array $metadata): array {
        $metadata['page_state'] = [
            'page_url' => 'https://docs.example.test/install',
            'reported_at' => now()->toJSON(),
        ];

        return $metadata;
    });

    expect($session->fresh()->metadata)
        ->toHaveKey('resync_request')
        ->toHaveKey('page_state')
        ->and($session->fresh()->metadata['resync_request']['id'])->toBe('resync_123');
});

test('visitor cobrowse snapshot fulfills a matching agent resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:15:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_123',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(10)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'page_url' => 'https://docs.example.test/install?step=2',
            'title' => 'Install Guide',
            'html' => '<main><h1>Install Guide</h1><p>Hello visitor.</p></main>',
            'text' => 'Install Guide Hello visitor.',
            'node_count' => 3,
            'masked_count' => 0,
            'resync_request_id' => 'resync_123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.snapshot.resync_request_id', 'resync_123');

        expect($session->fresh()->metadata['resync_request'])
            ->id->toBe('resync_123')
            ->fulfilled_at->toBe(now()->toJSON())
            ->fulfilled_snapshot_reported_at->toBe(now()->toJSON());

        $this->getJson('/api/conversations/WF-RESYNC/cobrowse?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
        ]))
            ->assertOk()
            ->assertJsonPath('data.cobrowse.resync.requested', false)
            ->assertJsonPath('data.cobrowse.resync.request_id', null);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse snapshot does not fulfill an expired agent resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:15:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-LATE',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(8),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_late',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(6)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'page_url' => 'https://docs.example.test/install?step=3',
            'title' => 'Install Guide',
            'html' => '<main><h1>Install Guide</h1><p>Late page.</p></main>',
            'text' => 'Install Guide Late page.',
            'node_count' => 3,
            'masked_count' => 0,
            'resync_request_id' => 'resync_late',
        ])
            ->assertOk()
            ->assertJsonPath('data.snapshot.resync_request_id', 'resync_late');

        $resyncRequest = $session->fresh()->metadata['resync_request'];

        expect($resyncRequest)
            ->id->toBe('resync_late')
            ->fulfilled_at->toBeNull()
            ->fulfilled_snapshot_reported_at->toBeNull()
            ->and($resyncRequest['ignored_responses'])->toHaveCount(1)
            ->and($resyncRequest['ignored_responses'][0]['request_id'])->toBe('resync_late')
            ->and($resyncRequest['ignored_responses'][0]['reason'])->toBe('expired')
            ->and($resyncRequest['ignored_responses'][0]['ignored_at'])->toBe(now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse snapshot records mismatched resync responses without fulfilling the request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:20:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-MISMATCH',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_current',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'page_url' => 'https://docs.example.test/install?step=4',
            'title' => 'Install Guide',
            'html' => '<main><h1>Install Guide</h1><p>Wrong request.</p></main>',
            'text' => 'Install Guide Wrong request.',
            'node_count' => 3,
            'masked_count' => 0,
            'resync_request_id' => 'resync_wrong',
        ])
            ->assertOk()
            ->assertJsonPath('data.snapshot.resync_request_id', 'resync_wrong');

        $resyncRequest = $session->fresh()->metadata['resync_request'];

        expect($resyncRequest)
            ->id->toBe('resync_current')
            ->fulfilled_at->toBeNull()
            ->fulfilled_snapshot_reported_at->toBeNull()
            ->and($resyncRequest['ignored_responses'])->toHaveCount(1)
            ->and($resyncRequest['ignored_responses'][0]['request_id'])->toBe('resync_wrong')
            ->and($resyncRequest['ignored_responses'][0]['reason'])->toBe('mismatched')
            ->and($resyncRequest['ignored_responses'][0]['ignored_at'])->toBe(now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse snapshot records duplicate fulfilled resync responses without replacing the fulfillment', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:25:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-DUPE',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_done',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinute()->toJSON(),
                    'fulfilled_at' => now()->subSeconds(20)->toJSON(),
                    'fulfilled_snapshot_reported_at' => now()->subSeconds(20)->toJSON(),
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'page_url' => 'https://docs.example.test/install?step=5',
            'title' => 'Install Guide',
            'html' => '<main><h1>Install Guide</h1><p>Duplicate request.</p></main>',
            'text' => 'Install Guide Duplicate request.',
            'node_count' => 3,
            'masked_count' => 0,
            'resync_request_id' => 'resync_done',
        ])
            ->assertOk()
            ->assertJsonPath('data.snapshot.resync_request_id', 'resync_done');

        $resyncRequest = $session->fresh()->metadata['resync_request'];

        expect($resyncRequest)
            ->id->toBe('resync_done')
            ->fulfilled_at->toBe(now()->subSeconds(20)->toJSON())
            ->fulfilled_snapshot_reported_at->toBe(now()->subSeconds(20)->toJSON())
            ->and($resyncRequest['ignored_responses'])->toHaveCount(1)
            ->and($resyncRequest['ignored_responses'][0]['request_id'])->toBe('resync_done')
            ->and($resyncRequest['ignored_responses'][0]['reason'])->toBe('already_fulfilled')
            ->and($resyncRequest['ignored_responses'][0]['ignored_at'])->toBe(now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor cobrowse status is unavailable without a request', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NOCOBROWSE',
    ]);

    $response = $this->getJson('/api/conversations/WF-NOCOBROWSE/cobrowse?'.http_build_query([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-NOCOBROWSE')
        ->assertJsonPath('data.cobrowse.status', 'unavailable')
        ->assertJsonPath('data.cobrowse.consent', 'unavailable')
        ->assertJsonPath('data.cobrowse.requested_by', null);
});

test('visitor cannot grant cobrowse without an active request', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NOREQUEST',
    ]);

    $this->postJson('/api/conversations/WF-NOREQUEST/cobrowse-consent', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        'granted' => true,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Cobrowse session not active.');

    $this->assertDatabaseCount('cobrowse_sessions', 0);
});

test('visitor cannot read cobrowse status for another visitors conversation', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIVATE',
    ]);

    $this->getJson('/api/conversations/WF-PRIVATE/cobrowse?'.http_build_query([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-other'),
    ]))
        ->assertNotFound()
        ->assertJsonPath('message', 'Conversation not found.');
});

test('visitor can report cobrowse telemetry for their active session', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TELEMETRY',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'rtt_ms' => 184,
        'payload_bytes' => 8192,
        'dropped_batches' => 2,
        'reconnects' => 1,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-TELEMETRY')
        ->assertJsonPath('data.cobrowse.status', 'granted')
        ->assertJsonPath('data.payload_budget.snapshot_html_max_characters', 65535)
        ->assertJsonPath('data.payload_budget.mutation_batch_max_items', 50)
        ->assertJsonPath('data.payload_budget.telemetry_payload_max_bytes', 10485760)
        ->assertJsonPath('data.telemetry.rtt_ms', 184)
        ->assertJsonPath('data.telemetry.max_rtt_ms', 184)
        ->assertJsonPath('data.telemetry.payload_bytes', 8192)
        ->assertJsonPath('data.telemetry.max_payload_bytes', 8192)
        ->assertJsonPath('data.telemetry.dropped_batches', 2)
        ->assertJsonPath('data.telemetry.reconnects', 1)
        ->assertJsonPath('data.telemetry.samples', 1);

    $secondResponse = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'rtt_ms' => 90,
        'payload_bytes' => 1024,
        'dropped_batches' => 3,
        'reconnects' => 1,
    ]);

    $secondResponse
        ->assertOk()
        ->assertJsonPath('data.telemetry.rtt_ms', 90)
        ->assertJsonPath('data.telemetry.max_rtt_ms', 184)
        ->assertJsonPath('data.telemetry.payload_bytes', 1024)
        ->assertJsonPath('data.telemetry.max_payload_bytes', 8192)
        ->assertJsonPath('data.telemetry.dropped_batches', 3)
        ->assertJsonPath('data.telemetry.reconnects', 1)
        ->assertJsonPath('data.telemetry.samples', 2);

    expect($session->fresh()->metadata['telemetry'])
        ->rtt_ms->toBe(90)
        ->max_rtt_ms->toBe(184)
        ->payload_bytes->toBe(1024)
        ->max_payload_bytes->toBe(8192)
        ->dropped_batches->toBe(3)
        ->reconnects->toBe(1)
        ->samples->toBe(2)
        ->reported_at->not->toBeNull()
        ->and($session->fresh()->metadata['payload_budget']['telemetry_payload_max_bytes'])->toBe(10485760);
});

test('visitor telemetry can mark a matching cobrowse resync request as attempt exhausted', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 15:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-EXHAUSTED',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_exhausted',
                    'requested_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'resync_request_id' => 'resync_exhausted',
            'resync_attempts_exhausted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.telemetry.resync_attempts_exhausted', true);

        expect($session->fresh()->metadata['resync_request'])
            ->id->toBe('resync_exhausted')
            ->fulfilled_at->toBeNull()
            ->attempts_exhausted_at->toBe(now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('resync exhaustion telemetry does not overwrite existing cobrowse transport metrics', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-20 15:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-EXHAUSTED-METRICS',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'rtt_ms' => 184,
                    'max_rtt_ms' => 240,
                    'payload_bytes' => 8192,
                    'max_payload_bytes' => 9000,
                    'dropped_batches' => 3,
                    'reconnects' => 2,
                    'samples' => 5,
                    'reported_at' => now()->subSeconds(20)->toJSON(),
                ],
                'resync_request' => [
                    'id' => 'resync_exhausted',
                    'requested_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);
        $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

        $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-telemetry", [
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => $token,
            'resync_request_id' => 'resync_exhausted',
            'resync_attempts_exhausted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.telemetry.resync_attempts_exhausted', true)
            ->assertJsonPath('data.telemetry.rtt_ms', 184)
            ->assertJsonPath('data.telemetry.samples', 5);

        expect($session->fresh()->metadata)
            ->telemetry->rtt_ms->toBe(184)
            ->telemetry->max_rtt_ms->toBe(240)
            ->telemetry->payload_bytes->toBe(8192)
            ->telemetry->max_payload_bytes->toBe(9000)
            ->telemetry->dropped_batches->toBe(3)
            ->telemetry->reconnects->toBe(2)
            ->telemetry->samples->toBe(5)
            ->telemetry->reported_at->toBe(now()->subSeconds(20)->toJSON())
            ->resync_request->attempts_exhausted_at->toBe(now()->toJSON());
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor can report cobrowse page state for their active session', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PAGESTATE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-page-state", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install?step=2',
        'title' => 'Install Guide',
        'viewport_width' => 1366,
        'viewport_height' => 768,
        'scroll_x' => 0,
        'scroll_y' => 420,
        'visibility_state' => 'visible',
        'focused' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-PAGESTATE')
        ->assertJsonPath('data.cobrowse.status', 'granted')
        ->assertJsonPath('data.page_state.page_url', 'https://docs.example.test/install?step=2')
        ->assertJsonPath('data.page_state.title', 'Install Guide')
        ->assertJsonPath('data.page_state.viewport_width', 1366)
        ->assertJsonPath('data.page_state.viewport_height', 768)
        ->assertJsonPath('data.page_state.scroll_x', 0)
        ->assertJsonPath('data.page_state.scroll_y', 420)
        ->assertJsonPath('data.page_state.visibility_state', 'visible')
        ->assertJsonPath('data.page_state.focused', true);

    expect($session->fresh()->metadata['page_state'])
        ->page_url->toBe('https://docs.example.test/install?step=2')
        ->title->toBe('Install Guide')
        ->viewport_width->toBe(1366)
        ->viewport_height->toBe(768)
        ->scroll_x->toBe(0)
        ->scroll_y->toBe(420)
        ->visibility_state->toBe('visible')
        ->focused->toBeTrue()
        ->reported_at->not->toBeNull();
});

test('visitor can report a cobrowse snapshot for their active session', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SNAPSHOT',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'page_state' => [
                'page_url' => 'https://docs.example.test/old',
                'reported_at' => now()->subMinute()->toJSON(),
            ],
        ],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install?step=2',
        'title' => 'Install Guide',
        'html' => '<main><h1>Install Guide</h1><p>Hello visitor.</p><input value="[masked]"></main>',
        'text' => 'Install Guide Hello visitor. [masked]',
        'node_count' => 4,
        'masked_count' => 1,
        'mutation_sequence' => 7,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-SNAPSHOT')
        ->assertJsonPath('data.cobrowse.status', 'granted')
        ->assertJsonPath('data.payload_budget.snapshot_html_max_characters', 65535)
        ->assertJsonPath('data.payload_budget.snapshot_text_max_characters', 10000)
        ->assertJsonPath('data.payload_budget.mutation_batch_max_items', 50)
        ->assertJsonPath('data.payload_budget.telemetry_payload_max_bytes', 10485760)
        ->assertJsonPath('data.snapshot.page_url', 'https://docs.example.test/install?step=2')
        ->assertJsonPath('data.snapshot.title', 'Install Guide')
        ->assertJsonPath('data.snapshot.node_count', 4)
        ->assertJsonPath('data.snapshot.masked_count', 1)
        ->assertJsonPath('data.snapshot.mutation_sequence', 7)
        ->assertJsonPath('data.snapshot.html_length', 80)
        ->assertJsonPath('data.snapshot.text_length', 37);

    expect($session->fresh()->metadata)
        ->page_state->page_url->toBe('https://docs.example.test/old')
        ->snapshot->page_url->toBe('https://docs.example.test/install?step=2')
        ->snapshot->title->toBe('Install Guide')
        ->snapshot->html->toBe('<main><h1>Install Guide</h1><p>Hello visitor.</p><input value="[masked]"></main>')
        ->snapshot->text->toBe('Install Guide Hello visitor. [masked]')
        ->snapshot->node_count->toBe(4)
        ->snapshot->masked_count->toBe(1)
        ->snapshot->mutation_sequence->toBe(7)
        ->snapshot->reported_at->not->toBeNull()
        ->payload_budget->snapshot_html_max_characters->toBe(65535)
        ->payload_budget->snapshot_text_max_characters->toBe(10000)
        ->payload_budget->mutation_batch_max_items->toBe(50)
        ->payload_budget->telemetry_payload_max_bytes->toBe(10485760);
});

test('cobrowse snapshot rejects oversized html payloads', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SNAPSHOT',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install',
        'title' => 'Install Guide',
        'html' => str_repeat('x', 65536),
        'text' => 'Install Guide',
        'node_count' => 1,
        'masked_count' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('html');

    expect($session->fresh()->metadata)->not->toHaveKey('snapshot');
});

test('visitor can report bounded cobrowse mutation batches for their active session', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MUTATE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'mutations' => [
                'batch_count' => 20,
                'mutation_count' => 20,
                'dropped_count' => 1,
                'skipped_count' => 2,
                'last_sequence' => 20,
                'recent_batches' => collect(range(1, 20))->map(fn (int $sequence): array => [
                    'sequence' => $sequence,
                    'mutation_count' => 1,
                    'dropped_count' => 0,
                    'skipped_count' => 0,
                    'page_url' => 'https://docs.example.test/install',
                    'reported_at' => now()->subSeconds(30)->toJSON(),
                    'mutations' => [
                        [
                            'type' => 'text',
                            'path' => 'body > main > p',
                            'text' => "Old text {$sequence}",
                        ],
                    ],
                ])->all(),
            ],
        ],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $response = $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-mutations", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install?step=2',
        'sequence' => 21,
        'dropped_count' => 1,
        'skipped_count' => 3,
        'mutations' => [
            [
                'type' => 'text',
                'path' => 'body > main > p:nth-child(2)',
                'text' => 'Public copy changed.',
            ],
            [
                'type' => 'attribute',
                'path' => 'body > main > button',
                'attribute_name' => 'aria-expanded',
                'attribute_value' => 'true',
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-MUTATE')
        ->assertJsonPath('data.cobrowse.status', 'granted')
        ->assertJsonPath('data.payload_budget.snapshot_html_max_characters', 65535)
        ->assertJsonPath('data.payload_budget.mutation_batch_max_items', 50)
        ->assertJsonPath('data.payload_budget.mutation_text_max_characters', 5000)
        ->assertJsonPath('data.payload_budget.mutation_html_max_characters', 10000)
        ->assertJsonPath('data.payload_budget.mutation_recent_batches_retained', 20)
        ->assertJsonPath('data.mutations.last_sequence', 21)
        ->assertJsonPath('data.mutations.batch_count', 21)
        ->assertJsonPath('data.mutations.mutation_count', 22)
        ->assertJsonPath('data.mutations.dropped_count', 2)
        ->assertJsonPath('data.mutations.skipped_count', 5)
        ->assertJsonPath('data.mutations.recent_batches_count', 20);

    $mutations = $session->fresh()->metadata['mutations'];

    expect($mutations)
        ->last_sequence->toBe(21)
        ->batch_count->toBe(21)
        ->mutation_count->toBe(22)
        ->dropped_count->toBe(2)
        ->skipped_count->toBe(5)
        ->last_page_url->toBe('https://docs.example.test/install?step=2')
        ->last_reported_at->not->toBeNull()
        ->and($mutations['recent_batches'])->toHaveCount(20)
        ->and($mutations['recent_batches'][0]['sequence'])->toBe(2)
        ->and($mutations['recent_batches'][19]['sequence'])->toBe(21)
        ->and($mutations['recent_batches'][19]['mutations'][0]['text'])->toBe('Public copy changed.')
        ->and($session->fresh()->metadata['payload_budget']['mutation_batch_max_items'])->toBe(50)
        ->and($session->fresh()->metadata['payload_budget']['mutation_recent_batches_retained'])->toBe(20);
});

test('cobrowse mutations reject oversized batches', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MUTATE',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);
    $token = widgetVisitorToken($this, 'site_public_docs', 'anon-docs');

    $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-mutations", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install',
        'sequence' => 1,
        'mutations' => collect(range(1, 51))->map(fn (int $index): array => [
            'type' => 'text',
            'path' => "body > main > p:nth-child({$index})",
            'text' => "Changed {$index}",
        ])->all(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mutations');

    expect($session->fresh()->metadata)->not->toHaveKey('mutations');
});

test('visitor cannot change cobrowse consent for another visitors conversation', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIVATE',
    ]);

    $this->postJson("/api/conversations/{$conversation->support_code}/cobrowse-consent", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-other'),
        'granted' => true,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'Conversation not found.');

    $this->assertDatabaseCount('cobrowse_sessions', 0);
});

test('visitor message creation rejects an invalid token', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MESSAGE',
    ]);

    $this->postJson("/api/conversations/{$conversation->support_code}/messages", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => 'not-a-real-token',
        'body' => 'Can you help me with this checkout error?',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Visitor token is invalid.');
});

test('visitor can read their conversation messages', function (): void {
    Event::fake([ConversationReadReceiptUpdated::class]);

    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $agent = User::factory()->create(['name' => 'Ada Agent']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MESSAGES',
    ]);

    $visitorMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Hello from the visitor.',
        'created_at' => now()->subMinute(),
    ]);

    $agentMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Hello from support.',
        'created_at' => now(),
    ]);

    $seenAt = Carbon::parse('2026-06-01 12:00:00', 'UTC');
    $this->travelTo($seenAt);

    $response = $this->getJson('/api/conversations/WF-MESSAGES/messages?'.http_build_query([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-docs',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        'mark_seen' => true,
    ]));

    $response
        ->assertOk()
        ->assertJsonPath('data.conversation.support_code', 'WF-MESSAGES')
        ->assertJsonPath('data.messages.0.sender.kind', 'visitor')
        ->assertJsonPath('data.messages.0.sender.name', 'Visitor')
        ->assertJsonPath('data.messages.0.body', 'Hello from the visitor.')
        ->assertJsonPath('data.messages.1.sender.kind', 'agent')
        ->assertJsonPath('data.messages.1.sender.name', 'Ada Agent')
        ->assertJsonPath('data.messages.1.body', 'Hello from support.');

    $payload = $response->json('data.messages.0');

    expect($payload)
        ->not->toHaveKey('sender_id')
        ->not->toHaveKey('sender_type');

    expect($visitorMessage->fresh()->seen_at)->toBeNull();
    expect($agentMessage->fresh()->seen_at?->toJSON())->toBe($seenAt->toJSON());

    Event::assertDispatched(
        ConversationReadReceiptUpdated::class,
        fn (ConversationReadReceiptUpdated $event): bool => $event->conversation->id === $conversation->id
    );
});

test('visitor read receipt can be limited to a rendered agent message', function (): void {
    Event::fake([ConversationReadReceiptUpdated::class]);

    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-MESSAGES',
        ]);

        $seenAgentMessage = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'This reply was rendered.',
            'created_at' => now()->subMinute(),
        ]);

        $unrenderedAgentMessage = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'This reply is newer but was not rendered.',
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/conversations/WF-MESSAGES/messages?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
            'mark_seen' => true,
            'seen_message_id' => $seenAgentMessage->id,
        ]));

        $response->assertOk();

        expect($seenAgentMessage->fresh()->seen_at?->toJSON())->toBe(now()->toJSON())
            ->and($unrenderedAgentMessage->fresh()->seen_at)->toBeNull();

        Event::assertDispatched(
            ConversationReadReceiptUpdated::class,
            fn (ConversationReadReceiptUpdated $event): bool => $event->conversation->id === $conversation->id
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor message fetch does not mark agent replies seen without a read signal', function (): void {
    Event::fake([ConversationReadReceiptUpdated::class]);

    Carbon::setTestNow(Carbon::parse('2026-06-17 11:00:00', 'UTC'));

    try {
        $site = Site::factory()->create(['public_key' => 'site_public_docs']);
        $agent = User::factory()->create(['name' => 'Ada Agent']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-docs',
            'last_seen_at' => now()->subHour(),
        ]);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-MESSAGES',
        ]);

        $agentMessage = ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Hello from support.',
            'created_at' => now(),
        ]);
        $seenAt = Carbon::parse('2026-06-17 12:00:00', 'UTC');
        Carbon::setTestNow($seenAt);

        $this->getJson('/api/conversations/WF-MESSAGES/messages?'.http_build_query([
            'site_public_key' => 'site_public_docs',
            'anonymous_id' => 'anon-docs',
            'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-docs'),
        ]))->assertOk();

        expect($agentMessage->fresh()->seen_at)->toBeNull()
            ->and($visitor->fresh()->last_seen_at?->toJSON())->toBe($seenAt->toJSON());

        Event::assertNotDispatched(ConversationReadReceiptUpdated::class);
    } finally {
        Carbon::setTestNow();
    }
});

test('visitor message read cannot cross site boundaries', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $otherSite = Site::factory()->create(['public_key' => 'site_public_other']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BOUNDARY',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This should stay private to the docs visitor.',
    ]);

    $this->getJson('/api/conversations/WF-BOUNDARY/messages?'.http_build_query([
        'site_public_key' => 'site_public_other',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_other', 'anon-other'),
    ]))
        ->assertNotFound();
});

test('visitor message read rejects a token for another visitor', function (): void {
    $site = Site::factory()->create(['public_key' => 'site_public_docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-docs']);
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIVATE',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'This should stay private to the docs visitor.',
    ]);

    $this->getJson('/api/conversations/WF-PRIVATE/messages?'.http_build_query([
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-other',
        'visitor_token' => widgetVisitorToken($this, 'site_public_docs', 'anon-other'),
    ]))
        ->assertNotFound();
});

function widgetVisitorToken($test, string $sitePublicKey, string $anonymousId): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $sitePublicKey,
        'anonymous_id' => $anonymousId,
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');
}
