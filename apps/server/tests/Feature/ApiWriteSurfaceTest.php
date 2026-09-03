<?php

use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Events\ConversationReadReceiptUpdated;
use App\Mail\ConversationReplyMessage;
use App\Models\Account;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\TicketAssigned;
use App\Support\Reporting\ReportingScope;
use App\Support\Reporting\ReportingWindow;
use App\Support\Reporting\SupportReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

/**
 * @return array{account: Account, site: Site, visitor: Visitor, agent: User, token: ApiToken, plain: string}
 */
function apiWriteWorld(array $abilities = [ApiToken::ABILITY_WRITE]): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $generated = ApiToken::generate();

    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'created_by_id' => $agent->id,
        'name' => 'Warehouse sync',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => $abilities,
        'restricts_sites' => true,
    ]);
    $token->sites()->attach($site->id);

    return compact('account', 'site', 'visitor', 'agent', 'token') + ['plain' => $generated['plain']];
}

/** @return array<string, string> */
function apiWriteHeaders(array $world, ?string $key = 'request-1'): array
{
    $headers = ['Authorization' => 'Bearer '.$world['plain']];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return $headers;
}

function apiWriteVisitorToken($test, array $world): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => $world['visitor']->anonymous_id,
    ])->assertSuccessful()->json('data.visitor.token');
}

test('write is a separate ability in both directions', function (): void {
    $writer = apiWriteWorld();

    $this->postJson('/api/v1/conversations', [
        'site_id' => $writer['site']->id,
        'visitor_id' => $writer['visitor']->id,
        'subject' => 'Printer offline',
    ], apiWriteHeaders($writer))->assertCreated();

    $this->getJson('/api/v1/me', apiWriteHeaders($writer, null))->assertForbidden();

    $reader = apiWriteWorld([ApiToken::ABILITY_READ]);

    $this->getJson('/api/v1/me', apiWriteHeaders($reader, null))->assertOk();
    $this->postJson('/api/v1/conversations', [
        'site_id' => $reader['site']->id,
        'visitor_id' => $reader['visitor']->id,
    ], apiWriteHeaders($reader))->assertForbidden();
});

test('a token opens a conversation for a known visitor in its writable scope', function (): void {
    $world = apiWriteWorld();
    $world['visitor']->forceFill(['presence_only' => true])->save();

    $response = $this->postJson('/api/v1/conversations', [
        'site_id' => $world['site']->id,
        'visitor_id' => $world['visitor']->id,
        'subject' => 'Printer offline',
    ], apiWriteHeaders($world, 'conversation-1'));

    $response->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.site_id', $world['site']->id)
        ->assertJsonPath('data.visitor_id', $world['visitor']->id)
        ->assertJsonPath('data.subject', 'Printer offline')
        ->assertJsonPath('data.status', 'open');

    $conversation = Conversation::query()->sole();

    expect($conversation->metadata)->toBe(['channel' => 'api'])
        ->and($world['visitor']->fresh()->presence_only)->toBeFalse()
        ->and($conversation->auditEvents()->where('action', 'conversation.created')->sole()->actor_type)->toBe(ApiToken::class)
        ->and($conversation->auditEvents()->where('action', 'conversation.created')->sole()->actor_id)->toBe($world['token']->id);
});

test('conversation creation refuses archived, unreachable, and mismatched references alike', function (): void {
    $world = apiWriteWorld();
    $other = apiWriteWorld();

    $world['site']->forceFill(['archived_at' => now()])->save();

    $this->postJson('/api/v1/conversations', [
        'site_id' => $world['site']->id,
        'visitor_id' => $world['visitor']->id,
    ], apiWriteHeaders($world, 'archived'))->assertUnprocessable()->assertJsonValidationErrors('site_id');

    $this->postJson('/api/v1/conversations', [
        'site_id' => $other['site']->id,
        'visitor_id' => $other['visitor']->id,
    ], apiWriteHeaders($world, 'unreachable'))->assertUnprocessable()->assertJsonValidationErrors('site_id');

    $world['site']->forceFill(['archived_at' => null])->save();

    $this->postJson('/api/v1/conversations', [
        'site_id' => $world['site']->id,
        'visitor_id' => $other['visitor']->id,
    ], apiWriteHeaders($world, 'mismatch'))->assertUnprocessable()->assertJsonValidationErrors('visitor_id');

    expect(Conversation::query()->count())->toBe(0);
});

test('a POST retry returns the original resource and a changed request conflicts', function (): void {
    $world = apiWriteWorld();
    $payload = [
        'site_id' => $world['site']->id,
        'visitor_id' => $world['visitor']->id,
        'subject' => 'Printer offline',
    ];

    $first = $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, 'same-operation'))
        ->assertCreated();
    $retry = $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, 'same-operation'))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($retry->json('data.support_code'))->toBe($first->json('data.support_code'))
        ->and(Conversation::query()->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->count())->toBe(1);

    $this->postJson('/api/v1/conversations', array_replace($payload, ['subject' => 'Different']), apiWriteHeaders($world, 'same-operation'))
        ->assertConflict();

    expect(Conversation::query()->count())->toBe(1);
});

test('idempotency keys are required, bounded, hashed, and retained for one day', function (): void {
    $world = apiWriteWorld();
    $payload = [
        'site_id' => $world['site']->id,
        'visitor_id' => $world['visitor']->id,
    ];

    $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, null))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, str_repeat('a', 256)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');

    $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, 'log-safe-key'))->assertCreated();

    $receipt = ApiIdempotencyKey::query()->sole();

    expect(json_encode($receipt->getAttributes()))->not->toContain('log-safe-key')
        ->and($receipt->key_hash)->toBe(hash('sha256', 'log-safe-key'))
        ->and($receipt->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
});

test('an idempotent retry survives the site being archived after the accepted write', function (): void {
    $world = apiWriteWorld();
    $payload = [
        'site_id' => $world['site']->id,
        'visitor_id' => $world['visitor']->id,
    ];

    $first = $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, 'accepted-before-archive'))
        ->assertCreated();

    $world['site']->forceFill(['archived_at' => now()])->save();

    $retry = $this->postJson('/api/v1/conversations', $payload, apiWriteHeaders($world, 'accepted-before-archive'))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($retry->json('data.support_code'))->toBe($first->json('data.support_code'))
        ->and(Conversation::query()->count())->toBe(1);
});

test('an API message is integration-authored, reopens the conversation, and broadcasts once', function (): void {
    Event::fake([ConversationMessageCreated::class]);
    $world = apiWriteWorld();
    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'support_code' => 'WF-APIWRITE',
        'status' => 'closed',
        'closed_at' => now()->subHour(),
    ]);

    $response = $this->postJson('/api/v1/conversations/WF-APIWRITE/messages', [
        'body' => 'We have restarted the printer service.',
    ], apiWriteHeaders($world, 'message-1'));

    $response->assertCreated()
        ->assertJsonPath('data.conversation.status', 'open')
        ->assertJsonPath('data.message.sender', 'integration')
        ->assertJsonPath('data.message.sender_id', $world['token']->id)
        ->assertJsonMissingPath('data.conversation.subject')
        ->assertJsonMissingPath('data.conversation.visitor_id');

    $message = ConversationMessage::query()->sole();

    expect($message->sender_type)->toBe(ApiToken::class)
        ->and($message->sender_id)->toBe($world['token']->id)
        ->and($message->metadata)->toBe(['channel' => 'api'])
        ->and($conversation->fresh()->status)->toBe('open')
        ->and($conversation->fresh()->closed_at)->toBeNull();

    $reopen = $conversation->auditEvents()->where('action', 'conversation.reopened')->sole();

    expect($reopen->actor_type)->toBe(ApiToken::class)
        ->and($reopen->metadata['actor'])->toBe('integration');

    $visitorView = $this->getJson('/api/conversations/WF-APIWRITE/messages?'.http_build_query([
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => $world['visitor']->anonymous_id,
        'visitor_token' => apiWriteVisitorToken($this, $world),
    ]));

    $visitorView->assertOk()
        ->assertJsonPath('data.messages.0.sender.kind', 'agent')
        ->assertJsonPath('data.messages.0.sender.name', $world['site']->name);
    expect($visitorView->getContent())->not->toContain('Warehouse sync');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('Integration')
        ->assertSee('Warehouse sync');

    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($world['visitor'], 'requester')
        ->create();

    $this->actingAs($world['agent'])
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Integration reply')
        ->assertSee('Warehouse sync')
        ->assertSee('We have restarted the printer service.');

    $this->actingAs($world['agent'])
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Warehouse sync']))
        ->assertOk()
        ->assertSee('Integration')
        ->assertSee('Warehouse sync')
        ->assertSee('Conversation reopened');

    Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);

    $this->postJson('/api/v1/conversations/WF-APIWRITE/messages', [
        'body' => 'We have restarted the printer service.',
    ], apiWriteHeaders($world, 'message-1'))->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(ConversationMessage::query()->count())->toBe(1)
        ->and($conversation->auditEvents()->where('action', 'conversation.reopened')->count())->toBe(1);
    Event::assertDispatchedTimes(ConversationMessageCreated::class, 1);
});

test('an API reply to an email conversation is queued exactly once', function (): void {
    Mail::fake();
    Event::fake([ConversationMessageCreated::class]);
    $world = apiWriteWorld();
    $world['site']->forceFill(['inbound_address' => 'support@example.test'])->save();
    $world['visitor']->forceFill(['email' => 'visitor@example.test'])->save();
    Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'support_code' => 'WF-APIEMAIL',
        'metadata' => ['channel' => 'email'],
    ]);
    $payload = ['body' => 'Your replacement is on its way.'];

    $this->postJson(
        '/api/v1/conversations/WF-APIEMAIL/messages',
        $payload,
        apiWriteHeaders($world, 'email-message'),
    )->assertCreated();

    Mail::assertQueued(ConversationReplyMessage::class, function (ConversationReplyMessage $mail): bool {
        return $mail->hasTo('visitor@example.test')
            && $mail->message->sender_type === ApiToken::class;
    });
    Mail::assertQueuedCount(1);

    $this->postJson(
        '/api/v1/conversations/WF-APIEMAIL/messages',
        $payload,
        apiWriteHeaders($world, 'email-message'),
    )->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    Mail::assertQueuedCount(1);
    expect(ConversationMessage::query()->sole()->email_message_id)->not->toBeNull();
});

test('an integration message can bound a visitor read receipt without becoming human work', function (): void {
    Event::fake([ConversationMessageCreated::class, ConversationReadReceiptUpdated::class]);
    $world = apiWriteWorld();
    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'support_code' => 'WF-APISEEN',
    ]);
    $humanMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $world['agent']->id,
        'body' => 'A human reply rendered before the integration update.',
    ]);

    $integrationMessageId = $this->postJson('/api/v1/conversations/WF-APISEEN/messages', [
        'body' => 'An automated follow-up rendered last.',
    ], apiWriteHeaders($world, 'read-boundary'))->assertCreated()->json('data.message.id');

    $this->getJson('/api/conversations/WF-APISEEN/messages?'.http_build_query([
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => $world['visitor']->anonymous_id,
        'visitor_token' => apiWriteVisitorToken($this, $world),
        'mark_seen' => true,
        'seen_message_id' => $integrationMessageId,
    ]))->assertOk();

    expect($humanMessage->fresh()->seen_at)->not->toBeNull()
        ->and(ConversationMessage::query()->findOrFail($integrationMessageId)->seen_at)->toBeNull();

    Event::assertDispatchedTimes(ConversationReadReceiptUpdated::class, 1);
});

test('an API message does not impersonate a human response in reporting', function (): void {
    $world = apiWriteWorld();
    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create([
        'support_code' => 'WF-NOHUMAN',
        'created_at' => now()->subMinutes(10),
    ]);
    $ticket = Ticket::factory()
        ->for($world['account'])
        ->for($world['site'])
        ->for($conversation)
        ->for($world['visitor'], 'requester')
        ->for($world['agent'], 'assignee')
        ->create(['status' => 'pending']);

    $this->postJson('/api/v1/conversations/WF-NOHUMAN/messages', [
        'body' => 'Automated acknowledgement.',
    ], apiWriteHeaders($world, 'message-report'))->assertCreated();

    $report = new SupportReport(
        ReportingScope::for($world['account'], $world['agent']),
        ReportingWindow::ofDays(30),
    );

    expect($report->firstResponse()['summary']->count)->toBe(0)
        ->and($report->firstResponse()['awaiting'])->toBe(1)
        ->and($conversation->fresh()->attentionState())->toBe('needs_reply')
        ->and($ticket->fresh()->attentionState())->toBe('needs_reply')
        ->and(Ticket::query()->whereKey($ticket->id)->whereAttentionState('needs_reply')->exists())->toBeTrue();
});

test('messages cannot be posted to archived or unreachable conversations', function (): void {
    $world = apiWriteWorld();
    $other = apiWriteWorld();
    $conversation = Conversation::factory()->for($world['site'])->for($world['visitor'])->create(['support_code' => 'WF-ARCHIVED']);
    $outside = Conversation::factory()->for($other['site'])->for($other['visitor'])->create(['support_code' => 'WF-OUTSIDE']);

    $world['site']->forceFill(['archived_at' => now()])->save();

    $this->postJson('/api/v1/conversations/'.$conversation->support_code.'/messages', ['body' => 'Nope'], apiWriteHeaders($world, 'archived-message'))
        ->assertNotFound();
    $this->postJson('/api/v1/conversations/'.$outside->support_code.'/messages', ['body' => 'Nope'], apiWriteHeaders($world, 'outside-message'))
        ->assertNotFound();

    expect(ConversationMessage::query()->count())->toBe(0);
});

test('one idempotency key cannot be moved to another conversation', function (): void {
    $world = apiWriteWorld();
    $first = Conversation::factory()->for($world['site'])->for($world['visitor'])->create(['support_code' => 'WF-FIRST']);
    $second = Conversation::factory()->for($world['site'])->for($world['visitor'])->create(['support_code' => 'WF-SECOND']);

    $this->postJson('/api/v1/conversations/'.$first->support_code.'/messages', ['body' => 'Same body'], apiWriteHeaders($world, 'one-message'))
        ->assertCreated();
    $this->postJson('/api/v1/conversations/'.$second->support_code.'/messages', ['body' => 'Same body'], apiWriteHeaders($world, 'one-message'))
        ->assertConflict();

    expect(ConversationMessage::query()->count())->toBe(1);
});

test('a token creates an idempotent ticket for a visitor in its writable scope', function (): void {
    $world = apiWriteWorld();
    $world['visitor']->forceFill(['presence_only' => true])->save();
    $payload = [
        'site_id' => $world['site']->id,
        'requester_id' => $world['visitor']->id,
        'subject' => 'Replace printer toner',
        'description' => 'The cyan cartridge is empty.',
        'priority' => 'high',
    ];

    $first = $this->postJson('/api/v1/tickets', $payload, apiWriteHeaders($world, 'ticket-1'))
        ->assertCreated()
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.requester_id', $world['visitor']->id);

    // A replay is still a receipt for THIS create, not a back-door read of
    // changes made afterwards by a person.
    Ticket::query()->sole()->forceFill([
        'subject' => 'Edited privately in the dashboard',
        'description' => 'This was not in the API request.',
    ])->save();

    $retry = $this->postJson('/api/v1/tickets', $payload, apiWriteHeaders($world, 'ticket-1'))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.subject', 'Replace printer toner')
        ->assertJsonPath('data.description', 'The cyan cartridge is empty.');

    expect($retry->json('data.id'))->toBe($first->json('data.id'))
        ->and(Ticket::query()->count())->toBe(1);

    $ticket = Ticket::query()->sole();
    $created = $ticket->auditEvents()->where('action', 'ticket.created')->sole();

    expect($ticket->metadata)->toBe(['source' => 'api'])
        ->and($world['visitor']->fresh()->presence_only)->toBeFalse()
        ->and($created->actor_type)->toBe(ApiToken::class)
        ->and($created->actor_id)->toBe($world['token']->id);
});

test('ticket creation refuses archived sites and requesters from another site', function (): void {
    $world = apiWriteWorld();
    $other = apiWriteWorld();

    $this->postJson('/api/v1/tickets', [
        'site_id' => $world['site']->id,
        'requester_id' => $other['visitor']->id,
        'subject' => 'Nope',
    ], apiWriteHeaders($world, 'wrong-requester'))->assertUnprocessable()->assertJsonValidationErrors('requester_id');

    $world['site']->forceFill(['archived_at' => now()])->save();

    $this->postJson('/api/v1/tickets', [
        'site_id' => $world['site']->id,
        'subject' => 'Nope',
    ], apiWriteHeaders($world, 'archived-ticket'))->assertUnprocessable()->assertJsonValidationErrors('site_id');

    expect(Ticket::query()->count())->toBe(0);
});

test('a token transitions and assigns a ticket without attributing either action to its issuer', function (): void {
    Notification::fake();
    $world = apiWriteWorld();
    $assignee = User::factory()->for($world['account'])->create();
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create([
        'status' => 'open',
        'assignee_id' => null,
    ]);

    $this->patchJson('/api/v1/tickets/'.$ticket->id, [
        'status' => 'pending',
        'assignee_id' => $assignee->id,
    ], apiWriteHeaders($world, null))
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.assignee_id', $assignee->id)
        ->assertJsonMissingPath('data.subject')
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.requester_id');

    $fresh = $ticket->fresh();
    $events = $fresh->auditEvents()->whereIn('action', ['ticket.pending', 'ticket.assignee_updated'])->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('actor_type')->unique()->all())->toBe([ApiToken::class])
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$world['token']->id])
        ->and($events->pluck('metadata')->every(fn (array $metadata): bool => $metadata['source'] === 'api'))->toBeTrue();

    Notification::assertSentTo($assignee, TicketAssigned::class, function (TicketAssigned $notification): bool {
        return str_contains($notification->toArray(new stdClass)['assigned_by_name'], 'Warehouse sync');
    });
});

test('ticket status transitions preserve lifecycle meaning and repeat PATCHes add no events', function (): void {
    $world = apiWriteWorld();
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create([
        'status' => 'closed',
        'closed_at' => now()->subDay(),
    ]);

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'pending'], apiWriteHeaders($world, null))->assertOk();

    expect($ticket->fresh()->closed_at)->toBeNull()
        ->and($ticket->auditEvents()->orderBy('id')->pluck('action')->all())->toBe(['ticket.reopened', 'ticket.pending']);

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'pending'], apiWriteHeaders($world, null))->assertOk();

    expect($ticket->auditEvents()->count())->toBe(2);

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'closed'], apiWriteHeaders($world, null))->assertOk();
    $closedAt = $ticket->fresh()->closed_at;

    expect($closedAt)->not->toBeNull()
        ->and($ticket->auditEvents()->where('action', 'ticket.closed')->count())->toBe(1);

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'closed'], apiWriteHeaders($world, null))->assertOk();

    expect($ticket->fresh()->closed_at->equalTo($closedAt))->toBeTrue()
        ->and($ticket->auditEvents()->where('action', 'ticket.closed')->count())->toBe(1);
});

test('ticket updates refuse archived records, foreign assignees, and empty changes', function (): void {
    $world = apiWriteWorld();
    $other = apiWriteWorld();
    $ticket = Ticket::factory()->for($world['account'])->for($world['site'])->create();

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['assignee_id' => $other['agent']->id], apiWriteHeaders($world, null))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assignee_id');

    $this->patchJson('/api/v1/tickets/'.$ticket->id, [], apiWriteHeaders($world, null))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $world['site']->forceFill(['archived_at' => now()])->save();

    $this->patchJson('/api/v1/tickets/'.$ticket->id, ['status' => 'pending'], apiWriteHeaders($world, null))
        ->assertNotFound();
});

test('expired idempotency receipts are pruned without touching live ones', function (): void {
    $world = apiWriteWorld();

    foreach ([now()->subMinute(), now()->addHour()] as $index => $expiresAt) {
        ApiIdempotencyKey::query()->create([
            'api_token_id' => $world['token']->id,
            'key_hash' => hash('sha256', 'key-'.$index),
            'request_hash' => hash('sha256', 'request-'.$index),
            'resource_type' => 'ticket',
            'resource_id' => $index + 1,
            'expires_at' => $expiresAt,
        ]);
    }

    $this->artisan('wayfindr:prune-api-idempotency-keys')
        ->expectsOutput('Pruned 1 expired API idempotency receipt.')
        ->assertSuccessful();

    expect(ApiIdempotencyKey::query()->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->sole()->expires_at->isFuture())->toBeTrue()
        ->and(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, 'wayfindr:prune-api-idempotency-keys')
                && $event->getExpression() === '0 * * * *',
        ))->toBeTrue();
});
