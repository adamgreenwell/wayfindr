<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

// Reverb is a separate service, and it restarts, runs out of memory, or is
// misconfigured after an upgrade while the database carries on. The message is
// the durable record and polling is the fallback, so an unreachable broadcaster
// has to cost the live update and nothing else. It used to cost the agent alert:
// Laravel broadcasts a ShouldBroadcastNow event BEFORE it runs the listeners, so
// the throw skipped NotifyAgentsOfVisitorMessage, the visitor saw a 500 for a
// message that had been stored, and the retry took the idempotent branch, which
// never announces. Nobody was ever told the visitor was waiting.

/**
 * Point broadcasting at a driver that fails the way an unreachable Reverb does,
 * after the bootstrap request, so only the request under test meets the outage.
 */
function broadcastOutageBegin(): void
{
    Broadcast::extend('broadcast-outage', fn () => new class extends Broadcaster
    {
        public function auth($request)
        {
            return null;
        }

        public function validAuthenticationResponse($request, $result)
        {
            return null;
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new BroadcastException('Pusher error: cURL error 7: Failed to connect to reverb port 8080.');
        }
    });

    config([
        'broadcasting.connections.broadcast-outage' => ['driver' => 'broadcast-outage'],
        'broadcasting.default' => 'broadcast-outage',
    ]);
}

function broadcastOutageVisitorToken($test, string $sitePublicKey, string $anonymousId): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $sitePublicKey,
        'anonymous_id' => $anonymousId,
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');
}

test('a visitor message sent while the broadcaster is down still alerts the agents', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['public_key' => 'site_outage']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-outage']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OUTAGE']);
    $token = broadcastOutageVisitorToken($this, 'site_outage', 'anon-outage');
    conversationOwnedBySession($conversation, $token);

    broadcastOutageBegin();

    $this->postJson('/api/conversations/WF-OUTAGE/messages', [
        'site_public_key' => 'site_outage',
        'anonymous_id' => 'anon-outage',
        'visitor_token' => $token,
        'body' => 'Is anyone there?',
        'client_message_id' => 'outage-1',
    ])->assertCreated();

    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1);

    $alerts = $agent->fresh()->notifications()->where('type', ConversationNeedsReply::class)->count();

    expect($alerts)->toBe(1, 'The agent was never alerted to a visitor message stored during a broadcaster outage.');
});

test('the widget keeps polling for replies while the broadcaster is down', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['public_key' => 'site_outage']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-outage']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-OUTPOLL']);
    $conversation->messages()->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'type' => 'text',
        'body' => 'Here is the answer.',
    ]);
    $token = broadcastOutageVisitorToken($this, 'site_outage', 'anon-outage');
    conversationOwnedBySession($conversation, $token);

    broadcastOutageBegin();

    // Polling is how the visitor reads replies when realtime is unavailable, so
    // it cannot be the request the outage breaks. The unseen agent reply makes
    // this poll announce a read receipt as well as presence.
    $this->getJson('/api/conversations/WF-OUTPOLL/messages?'.http_build_query([
        'site_public_key' => 'site_outage',
        'anonymous_id' => 'anon-outage',
        'visitor_token' => $token,
        'mark_seen' => 1,
    ]))
        ->assertOk()
        ->assertJsonPath('data.messages.0.body', 'Here is the answer.');

    expect($conversation->messages()->first()->seen_at)->not->toBeNull();
});

test('an agent reply sent while the broadcaster is down is not reported as a failure', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OUTREPLY',
        'status' => 'open',
    ]);

    broadcastOutageBegin();

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-OUTREPLY')
        ->post('/dashboard/conversations/WF-OUTREPLY/messages', ['body' => 'Still here, and on it.'])
        ->assertRedirect('/dashboard/conversations/WF-OUTREPLY')
        ->assertSessionHas('status', 'conversations.flash.reply_sent');

    expect(ConversationMessage::query()->where('body', 'Still here, and on it.')->count())->toBe(1);
});
