<?php

// A proactive opener is marked automated to the visitor; nothing else is.
//
// Three senders answer on the support side of the widget. A person on the desk
// answers under their own name. A proactive opener and an API integration both
// answer under the SITE's name, so to the visitor a canned invitation read
// exactly like a reply somebody had typed for them. The widget now tags the
// opener -- words the site set up in advance -- and leaves the integration
// alone, because an integration may be relaying a person, and calling that
// automated could be the claim that is untrue.
//
// Both places that describe a sender to the widget carry the flag: the
// transcript the widget polls, and the realtime event it receives live.

use App\Events\ConversationMessageCreated;
use App\Models\ApiToken;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A conversation with one message from each support-side sender, in order:
 * the proactive opener, an API integration's post, and a person's reply.
 *
 * @return array{site: Site, visitor: Visitor, conversation: Conversation, token: string, opener: ConversationMessage, integration: ConversationMessage, person: ConversationMessage}
 */
function widgetAutomatedSenderWorld(): array
{
    $site = Site::factory()->create(['public_key' => 'site_public_automated', 'name' => 'Shop support']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-automated']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);
    $token = app(VisitorSessionToken::class)->issue($site, $visitor);

    conversationOwnedBySession($conversation, $token);

    $rule = ProactiveMessageRule::factory()->for($site)->create();
    $apiToken = ApiToken::factory()->create(['account_id' => $site->account_id, 'name' => 'Warehouse sync']);
    $agent = User::factory()->create(['name' => 'Ada Agent']);

    $opener = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => ProactiveMessageRule::class,
        'sender_id' => $rule->id,
        'body' => 'Questions about plans?',
        'created_at' => now()->subMinutes(3),
    ]);
    $integration = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => ApiToken::class,
        'sender_id' => $apiToken->id,
        'body' => 'Your order has shipped.',
        'created_at' => now()->subMinutes(2),
    ]);
    $person = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Anything else I can help with?',
        'created_at' => now()->subMinute(),
    ]);

    return compact('site', 'visitor', 'conversation', 'token', 'opener', 'integration', 'person');
}

test('the widget transcript marks the proactive opener automated and no other sender', function (): void {
    $world = widgetAutomatedSenderWorld();

    $response = $this->getJson('/api/conversations/'.$world['conversation']->support_code.'/messages?'.http_build_query([
        'site_public_key' => $world['site']->public_key,
        'anonymous_id' => $world['visitor']->anonymous_id,
        'visitor_token' => $world['token'],
    ]))->assertOk();

    $senders = collect($response->json('data.messages'))->pluck('sender', 'body');

    // Asserted first, so a reordered or empty transcript cannot pass the
    // checks below by having nothing at the paths they read.
    expect($senders->keys()->all())->toBe([
        'Questions about plans?',
        'Your order has shipped.',
        'Anything else I can help with?',
    ]);

    expect($senders['Questions about plans?'])->toBe(
        ['kind' => 'agent', 'name' => 'Shop support', 'automated' => true],
        'the proactive opener reached the widget looking like a reply somebody typed',
    );
    expect($senders['Your order has shipped.'])->toBe(
        ['kind' => 'agent', 'name' => 'Shop support'],
        'an integration post was called automated, but it may be relaying a person',
    );
    expect($senders['Anything else I can help with?'])->toBe(
        ['kind' => 'agent', 'name' => 'Ada Agent'],
        'a person on the desk was called automated',
    );
});

test('the realtime event marks the proactive opener automated and no other sender', function (): void {
    $world = widgetAutomatedSenderWorld();

    $sender = fn (ConversationMessage $message): array => (new ConversationMessageCreated($message))
        ->broadcastWith()['message']['sender'];

    expect($sender($world['opener']))->toBe(
        ['kind' => 'agent', 'name' => 'Shop support', 'automated' => true],
        'a live delivery of the opener described it differently from the transcript',
    );
    expect($sender($world['integration']))->toBe(
        ['kind' => 'agent', 'name' => 'Shop support'],
        'a live integration post was called automated, but it may be relaying a person',
    );
    expect($sender($world['person']))->toBe(
        ['kind' => 'agent', 'name' => 'Ada Agent'],
        'a live reply from a person was called automated',
    );
});
