<?php

use App\Events\CobrowseStateUpdated;
use App\Events\ConversationMessageCreated;
use App\Events\ConversationTypingUpdated;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\CobrowseConsentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('dashboard lists open conversations for the agent account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create();

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ACME123',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => now()->subMinute(),
    ]);

    $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CLOSED1',
        'subject' => 'Closed conversation',
        'status' => 'closed',
    ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
        'subject' => 'Other account problem',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Conversations')
        ->assertSee('Checkout trouble')
        ->assertSee('Acme Docs')
        ->assertSee('/dashboard/sites/'.$site->id, false)
        ->assertSee('anon-acme')
        ->assertSee('WF-ACME123')
        ->assertSee(route('dashboard.support-code.lookup', ['support_code' => 'WF-ACME123']), false)
        ->assertDontSee($closedConversation->subject)
        ->assertDontSee('Other account problem')
        ->assertDontSee('Other Docs');
});

test('conversation queue shows bounded latest message previews', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Riley Agent']);

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-preview']);
    $visitorConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PREVIEW1',
        'subject' => 'Visitor needs help',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    $agentConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PREVIEW2',
        'subject' => 'Agent replied last',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    $emptyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PREVIEW3',
        'subject' => 'No messages yet',
        'status' => 'open',
    ]);

    ConversationMessage::factory()->for($visitorConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button fails after billing details are saved, and the visitor needs a calm next step before they abandon the order. '
            .str_repeat('Extra diagnostic context that should stay clipped. ', 4)
            .'private-tail-that-should-not-render',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->for($agentConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I can help from the support desk.',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Latest visitor message')
        ->assertSee('The checkout button fails after billing details are saved')
        ->assertDontSee('private-tail-that-should-not-render')
        ->assertSee('Latest agent reply')
        ->assertSee('I can help from the support desk.')
        ->assertSee('No activity preview yet')
        ->assertSee('No messages have been sent yet.')
        ->assertSee('WF-PREVIEW1')
        ->assertSee('WF-PREVIEW2')
        ->assertSee('WF-PREVIEW3');
});

test('conversation queue shows timing context for active triage', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-22 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Riley Agent']);

        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-timing']);
        $visitorConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TIMING1',
            'subject' => 'Visitor is waiting',
            'status' => 'open',
            'created_at' => now()->subHours(2),
            'last_message_at' => now()->subMinutes(15),
        ]);
        $agentConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TIMING2',
            'subject' => 'Agent replied',
            'status' => 'open',
            'created_at' => now()->subHour(),
            'last_message_at' => now()->subMinutes(8),
        ]);
        $emptyConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TIMING3',
            'subject' => 'Empty chat',
            'status' => 'open',
            'created_at' => now()->subMinutes(5),
        ]);
        $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TIMING4',
            'subject' => 'Closed chat',
            'status' => 'closed',
            'created_at' => now()->subHours(3),
            'closed_at' => now()->subMinutes(4),
            'last_message_at' => now()->subMinutes(20),
        ]);

        ConversationMessage::factory()->for($visitorConversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Can someone help with the setup?',
            'created_at' => now()->subMinutes(15),
        ]);
        ConversationMessage::factory()->for($agentConversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'I sent the next step.',
            'created_at' => now()->subMinutes(8),
        ]);
        ConversationMessage::factory()->for($closedConversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Closing this out.',
            'created_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_filter=closed')
            ->assertOk()
            ->assertSee('Timing')
            ->assertSee('Opened 3 hours ago')
            ->assertSee('Closed 4 minutes ago')
            ->assertSee('WF-TIMING4');

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertSee('Timing')
            ->assertSee('Opened 2 hours ago')
            ->assertSee('Waiting on reply for 15 minutes')
            ->assertSee('Opened 1 hour ago')
            ->assertSee('Waiting on visitor for 8 minutes')
            ->assertSee('Opened 5 minutes ago')
            ->assertSee('No messages yet')
            ->assertSee('WF-TIMING1')
            ->assertSee('WF-TIMING2')
            ->assertSee('WF-TIMING3')
            ->assertDontSee('WF-TIMING4');
    } finally {
        Carbon::setTestNow();
    }
});

test('conversation detail shows bounded latest activity previews', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Riley Agent']);

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-preview']);
    $visitorConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAIL1',
        'subject' => 'Visitor detail preview',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    $agentConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAIL2',
        'subject' => 'Agent detail preview',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    $emptyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAIL3',
        'subject' => 'Empty detail preview',
        'status' => 'open',
    ]);

    ConversationMessage::factory()->for($visitorConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button fails after billing details are saved, and the visitor needs a calm next step before they abandon the order. '
            .str_repeat('Extra diagnostic context that should stay clipped. ', 4)
            .'private-tail-that-should-not-render',
        'created_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->for($agentConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I can help from the support desk.',
        'created_at' => now()->subMinute(),
    ]);

    $visitorResponse = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $visitorConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Context',
            'Latest activity',
            'Latest visitor message',
            'The checkout button fails after billing details are saved',
            'Visitor at a glance',
        ]);

    $conversationContext = str($visitorResponse->getContent())
        ->after('id="conversation-context-heading"')
        ->before('id="visitor-context-heading"');

    expect($conversationContext->toString())->not->toContain('private-tail-that-should-not-render');

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $agentConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Context',
            'Latest activity',
            'Latest agent reply',
            'I can help from the support desk.',
            'Visitor at a glance',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $emptyConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Context',
            'Latest activity',
            'No activity preview yet',
            'No messages have been sent yet.',
            'Visitor at a glance',
        ]);
});

test('conversation detail recommends the next action for the conversation state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Riley Agent']);

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-next-action']);
    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVNEXT1',
        'subject' => 'Visitor needs a reply',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(3),
    ]);
    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVNEXT2',
        'subject' => 'Agent replied last',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    $emptyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVNEXT3',
        'subject' => 'New empty conversation',
        'status' => 'open',
    ]);
    $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVNEXT4',
        'subject' => 'Closed conversation',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help me finish this setup?',
        'created_at' => now()->subMinutes(3),
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I sent the next step.',
        'created_at' => now()->subMinutes(2),
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $needsReplyConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Next action',
            'Reply to visitor',
            'Visitor replied last. Send a clear response or create a ticket when the request needs durable follow-up.',
            'Jump to reply',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $waitingConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Next action',
            'Wait on visitor',
            'Agent replied last. Keep the conversation visible and respond when the visitor comes back.',
            'Review messages',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $emptyConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Next action',
            'Start the conversation',
            'No messages have landed yet. Use the current visitor context to decide whether to greet, wait, or create a ticket.',
            'Review context',
        ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $closedConversation->support_code))
        ->assertOk()
        ->assertSeeInOrder([
            'Next action',
            'Review closed conversation',
            'This conversation is closed. Reopen it only if the visitor returns or the outcome changes.',
            'Review status actions',
        ]);
});

test('dashboard shows an empty conversation state', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('No active conversations yet.');
});

test('dashboard can filter closed conversations', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create();

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $openConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OPEN1',
        'subject' => 'Open checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(10),
    ]);
    $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CLOSED1',
        'subject' => 'Closed checkout trouble',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
        'last_message_at' => now()->subMinute(),
    ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHERCLOSED',
        'subject' => 'Other closed problem',
        'status' => 'closed',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=closed')
        ->assertOk()
        ->assertSee('1 closed')
        ->assertSee('Closed checkout trouble')
        ->assertSee('Acme Docs')
        ->assertSee('WF-CLOSED1')
        ->assertSee(route('dashboard.support-code.lookup', ['support_code' => $closedConversation->support_code]), false)
        ->assertDontSee('Open checkout trouble')
        ->assertDontSee($openConversation->support_code)
        ->assertDontSee('Other closed problem')
        ->assertDontSee('Other Docs');
});

test('dashboard searches conversations by subject support code and visitor references', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create();

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon_conversation_search',
        'external_id' => 'customer-lookup-42',
        'name' => 'Casey Conversation',
        'email' => 'billing-contact@example.test',
    ]);
    $matchingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVSEARCH',
        'subject' => 'Invoice export problem',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    $closedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVCLOSED',
        'subject' => 'Closed billing conversation',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
        'last_message_at' => now()->subMinute(),
    ]);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PASSWORD',
        'subject' => 'Password reset request',
        'status' => 'open',
    ]);
    $wildcardVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anonXconversationXsearch',
    ]);
    Conversation::factory()->for($site)->for($wildcardVisitor)->create([
        'support_code' => 'WF-WILDCARD',
        'subject' => 'Wildcard visitor mismatch',
        'status' => 'open',
    ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create([
        'anonymous_id' => 'anon-private-search',
        'email' => 'billing-contact@example.test',
    ]);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-PRIVATESEARCH',
        'subject' => 'Private invoice issue',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_search=invoice')
        ->assertOk()
        ->assertSee('1 open matching')
        ->assertSee('Active conversation filters')
        ->assertSee('Invoice export problem')
        ->assertSee($matchingConversation->support_code)
        ->assertDontSee('Password reset request')
        ->assertDontSee('Private invoice issue')
        ->assertDontSee('WF-PRIVATESEARCH');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_search=WF-CONVSEARCH')
        ->assertOk()
        ->assertSee('Invoice export problem')
        ->assertDontSee('Password reset request');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_search=billing-contact@example.test')
        ->assertOk()
        ->assertSee('Invoice export problem')
        ->assertDontSee('Private invoice issue');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_search=customer-lookup-42')
        ->assertOk()
        ->assertSee('Invoice export problem')
        ->assertDontSee('Private invoice issue');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_search=anon_conversation_search')
        ->assertOk()
        ->assertSee('Invoice export problem')
        ->assertDontSee('Wildcard visitor mismatch');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=closed&conversation_search=closed')
        ->assertOk()
        ->assertSee('1 closed')
        ->assertSee('Closed billing conversation')
        ->assertSee($closedConversation->support_code)
        ->assertDontSee('Invoice export problem');
});

test('dashboard filters conversations by visible site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();

    $docsSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $storeSite = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Site']);
    $restrictedSite->supportAgents()->attach($otherAgent);

    $docsVisitor = Visitor::factory()->for($docsSite)->create(['anonymous_id' => 'anon-docs']);
    $storeVisitor = Visitor::factory()->for($storeSite)->create(['anonymous_id' => 'anon-store']);
    $restrictedVisitor = Visitor::factory()->for($restrictedSite)->create(['anonymous_id' => 'anon-restricted']);

    Conversation::factory()->for($docsSite)->for($docsVisitor)->create([
        'support_code' => 'WF-DOCSITE',
        'subject' => 'Docs checkout question',
        'status' => 'open',
    ]);
    Conversation::factory()->for($storeSite)->for($storeVisitor)->create([
        'support_code' => 'WF-STORESITE',
        'subject' => 'Store checkout question',
        'status' => 'open',
    ]);
    $closedStoreConversation = Conversation::factory()->for($storeSite)->for($storeVisitor)->create([
        'support_code' => 'WF-STORECLOSED',
        'subject' => 'Closed store question',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);
    Conversation::factory()->for($restrictedSite)->for($restrictedVisitor)->create([
        'support_code' => 'WF-RESTRICTED',
        'subject' => 'Restricted site question',
        'status' => 'open',
    ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Account Site']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-other-site']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHERSITE',
        'subject' => 'Other account site question',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_site='.$storeSite->id)
        ->assertOk()
        ->assertSee('Store checkout question')
        ->assertSee('Acme Store')
        ->assertDontSee('Docs checkout question')
        ->assertDontSee('Restricted site question')
        ->assertDontSee('Other account site question');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=closed&conversation_site='.$storeSite->id.'&conversation_search=closed')
        ->assertOk()
        ->assertSee('1 closed')
        ->assertSee('Closed store question')
        ->assertSee($closedStoreConversation->support_code)
        ->assertDontSee('Docs checkout question');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_site='.$restrictedSite->id)
        ->assertOk()
        ->assertSee('Docs checkout question')
        ->assertSee('Store checkout question')
        ->assertDontSee('Restricted site question');
});

test('conversation detail links preserve conversation queue return context', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-return']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RETURN',
        'subject' => 'Return context question',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=closed&conversation_site='.$site->id.'&conversation_search=return&ignored=yes')
        ->assertOk()
        ->assertSee('/dashboard/conversations/WF-RETURN?conversation_filter=closed&amp;conversation_search=return&amp;conversation_site='.$site->id, false)
        ->assertDontSee('ignored=yes', false);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/'.$conversation->support_code.'?conversation_filter=closed&conversation_site='.$site->id.'&conversation_search=return&ignored=yes')
        ->assertOk()
        ->assertSee('Back to conversations')
        ->assertSee('/dashboard/conversations?conversation_filter=closed&amp;conversation_search=return&amp;conversation_site='.$site->id, false)
        ->assertDontSee('ignored=yes', false);
});

test('conversation actions preserve conversation queue return context', function (): void {
    Event::fake([
        CobrowseStateUpdated::class,
        ConversationMessageCreated::class,
    ]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-return']);
    $returnQuery = [
        'conversation_filter' => 'closed',
        'conversation_search' => 'return',
        'conversation_site' => $site->id,
    ];
    $expectedRedirect = fn (string $supportCode): string => route('dashboard.conversations.show', ['supportCode' => $supportCode] + $returnQuery);
    $makeConversation = fn (string $supportCode, array $attributes = []): Conversation => Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create(array_merge([
            'support_code' => $supportCode,
            'subject' => 'Return context question',
            'status' => 'open',
            'closed_at' => null,
        ], $attributes));

    $makeConversation('WF-RETACT', [
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETACT/messages', $returnQuery + [
            'body' => 'I can keep helping here.',
        ])
        ->assertRedirect($expectedRedirect('WF-RETACT'));

    $makeConversation('WF-RETCLOSE');

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETCLOSE/close', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETCLOSE'));

    $makeConversation('WF-RETREOPEN', [
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETREOPEN/reopen', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETREOPEN'));

    $makeConversation('WF-RETCLAIM', [
        'assigned_agent_id' => null,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETCLAIM/claim', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETCLAIM'));

    $makeConversation('WF-RETRELEASE', [
        'assigned_agent_id' => $agent->id,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETRELEASE/release', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETRELEASE'));

    $makeConversation('WF-RETTICKET');

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETTICKET/tickets', $returnQuery + [
            'category' => 'bug',
            'priority' => 'high',
        ])
        ->assertRedirect($expectedRedirect('WF-RETTICKET'));

    $requestConversation = $makeConversation('WF-RETCOBRO');

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETCOBRO/cobrowse/request', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETCOBRO'));

    $endConversation = $makeConversation('WF-RETEND');

    CobrowseSession::factory()->for($endConversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETEND/cobrowse/end', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETEND'));

    $resyncConversation = $makeConversation('WF-RETRESYNC');

    CobrowseSession::factory()->for($resyncConversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RETRESYNC/cobrowse/resync', $returnQuery)
        ->assertRedirect($expectedRedirect('WF-RETRESYNC'));

    expect($requestConversation->fresh()->cobrowseSessions()->exists())->toBeTrue();
});

test('dashboard shows conversation assignment state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-ASSIGNED',
        'subject' => 'Assigned checkout trouble',
        'status' => 'open',
    ]);

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-UNASSIGN',
        'subject' => 'Unassigned checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Assigned')
        ->assertSee('Assigned checkout trouble')
        ->assertSee('Bea Builder')
        ->assertSee('Unassigned checkout trouble')
        ->assertSee('Unassigned');
});

test('dashboard shows conversation attention state from the latest message', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NEEDS1',
        'subject' => 'Visitor latest',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I still need help.',
        'created_at' => now()->subMinutes(2),
    ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-WAITING',
        'subject' => 'Agent latest',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Can you try again?',
        'created_at' => now()->subMinute(),
    ]);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-FRESH',
        'subject' => 'Fresh conversation',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('Attention')
        ->assertSeeInOrder(['Visitor latest', 'Needs reply'])
        ->assertSeeInOrder(['Agent latest', 'Waiting on visitor'])
        ->assertSeeInOrder(['Fresh conversation', 'Needs reply']);
});

test('conversation queue summarizes visible support lanes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 14:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $activeVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-active',
            'last_seen_at' => now()->subMinute(),
        ]);
        $recentVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-recent',
            'last_seen_at' => now()->subMinutes(8),
        ]);
        $quietVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-quiet',
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $needsReplyConversation = Conversation::factory()->for($site)->for($activeVisitor)->for($agent, 'assignedAgent')->create([
            'support_code' => 'WF-SNAPSHOT1',
            'subject' => 'Visitor is waiting',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);
        ConversationMessage::factory()->for($needsReplyConversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $activeVisitor->id,
            'body' => 'Can someone help?',
            'created_at' => now()->subMinutes(2),
        ]);

        $waitingConversation = Conversation::factory()->for($site)->for($recentVisitor)->for($agent, 'assignedAgent')->create([
            'support_code' => 'WF-SNAPSHOT2',
            'subject' => 'Agent replied',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);
        ConversationMessage::factory()->for($waitingConversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Try this next.',
            'created_at' => now()->subMinute(),
        ]);
        $waitingConversation->markReadFor($agent, now());

        Conversation::factory()->for($site)->for($quietVisitor)->create([
            'support_code' => 'WF-SNAPSHOT3',
            'subject' => 'Fresh unassigned chat',
            'status' => 'open',
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertSee('Queue snapshot')
            ->assertSee('Needs attention: 2')
            ->assertSee('Needs reply: 2')
            ->assertSee('Assigned to me: 2')
            ->assertSee('Unassigned: 1')
            ->assertSee('Active visitors: 1')
            ->assertSee('Recently active: 1')
            ->assertSee('/dashboard/conversations?conversation_filter=new_activity', false)
            ->assertSee('/dashboard/conversations?conversation_filter=needs_reply', false)
            ->assertSee('/dashboard/conversations?conversation_filter=assigned_to_me', false)
            ->assertSee('/dashboard/conversations?conversation_filter=unassigned', false)
            ->assertSee('/dashboard/conversations?conversation_presence=active', false)
            ->assertSee('/dashboard/conversations?conversation_presence=recent', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('conversation queue snapshot respects current site and search context', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $otherSite = Site::factory()->for($account)->create(['name' => 'Other Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-checkout',
        'last_seen_at' => now()->subMinute(),
    ]);
    $profileVisitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-profile',
        'last_seen_at' => now()->subMinute(),
    ]);
    $otherVisitor = Visitor::factory()->for($otherSite)->create([
        'anonymous_id' => 'anon-other',
        'last_seen_at' => now()->subMinute(),
    ]);

    $matchingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONTEXT1',
        'subject' => 'Checkout question',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    ConversationMessage::factory()->for($matchingConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Checkout is stuck.',
        'created_at' => now()->subMinute(),
    ]);

    $sameSiteDifferentSearch = Conversation::factory()->for($site)->for($profileVisitor)->create([
        'support_code' => 'WF-CONTEXT2',
        'subject' => 'Profile question',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->for($sameSiteDifferentSearch)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $profileVisitor->id,
        'body' => 'Profile is stuck.',
        'created_at' => now()->subMinutes(2),
    ]);

    $otherSiteConversation = Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-CONTEXT3',
        'subject' => 'Checkout from other site',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(3),
    ]);
    ConversationMessage::factory()->for($otherSiteConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $otherVisitor->id,
        'body' => 'Checkout is stuck elsewhere.',
        'created_at' => now()->subMinutes(3),
    ]);

    $query = [
        'conversation_site' => $site->id,
        'conversation_search' => 'Checkout',
    ];

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index', $query))
        ->assertOk()
        ->assertSee('Queue snapshot')
        ->assertSee('Needs attention: 1')
        ->assertSee('Needs reply: 1')
        ->assertSee('Active visitors: 1')
        ->assertSee(e(route('dashboard.conversations.index', [
            'conversation_search' => 'Checkout',
            'conversation_site' => $site->id,
            'conversation_filter' => 'needs_reply',
        ])), false)
        ->assertDontSee('Profile question')
        ->assertDontSee('Checkout from other site');
});

test('dashboard shows visitor presence state in the conversation queue', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-acme',
            'last_seen_at' => now()->subMinute(),
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-PRESENT',
            'subject' => 'Visitor is still nearby',
            'status' => 'open',
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertSee('Presence')
            ->assertSeeInOrder(['Visitor is still nearby', 'Active recently']);
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard can filter the conversation queue by visitor presence', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

        $activeVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-active',
            'last_seen_at' => now()->subMinute(),
        ]);
        Conversation::factory()->for($site)->for($activeVisitor)->create([
            'support_code' => 'WF-ACTIVE',
            'subject' => 'Active visitor question',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);

        $recentVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-recent',
            'last_seen_at' => now()->subMinutes(8),
        ]);
        Conversation::factory()->for($site)->for($recentVisitor)->create([
            'support_code' => 'WF-RECENT',
            'subject' => 'Recently active visitor question',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);

        $quietVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-quiet',
            'last_seen_at' => now()->subMinutes(30),
        ]);
        Conversation::factory()->for($site)->for($quietVisitor)->create([
            'support_code' => 'WF-QUIET',
            'subject' => 'Quiet visitor question',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(3),
        ]);

        $unknownVisitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-unknown',
            'last_seen_at' => null,
        ]);
        Conversation::factory()->for($site)->for($unknownVisitor)->create([
            'support_code' => 'WF-UNKNOWN',
            'subject' => 'Unreported visitor question',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_presence=active')
            ->assertOk()
            ->assertSee('Presence: Active recently')
            ->assertSee('Active visitor question')
            ->assertDontSee('Recently active visitor question')
            ->assertDontSee('Quiet visitor question')
            ->assertDontSee('Unreported visitor question');

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_presence=recent')
            ->assertOk()
            ->assertSee('Presence: Recently active')
            ->assertSee('Recently active visitor question')
            ->assertDontSee('Active visitor question')
            ->assertDontSee('Quiet visitor question')
            ->assertDontSee('Unreported visitor question');

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_presence=quiet')
            ->assertOk()
            ->assertSee('Presence: Quiet')
            ->assertSee('Quiet visitor question')
            ->assertDontSee('Active visitor question')
            ->assertDontSee('Recently active visitor question')
            ->assertDontSee('Unreported visitor question');

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_presence=not_reported')
            ->assertOk()
            ->assertSee('Presence: Not reported')
            ->assertSee('Unreported visitor question')
            ->assertDontSee('Active visitor question')
            ->assertDontSee('Recently active visitor question')
            ->assertDontSee('Quiet visitor question');
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard preserves visitor presence filters when opening a conversation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create([
            'anonymous_id' => 'anon-active',
            'last_seen_at' => now()->subMinute(),
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-PRESERVE',
            'subject' => 'Active filtered conversation',
            'status' => 'open',
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_filter=needs_reply&conversation_site='.$site->id.'&conversation_presence=active&conversation_search=Active')
            ->assertOk()
            ->assertSee('/dashboard/conversations/WF-PRESERVE?conversation_filter=needs_reply&amp;conversation_search=Active&amp;conversation_site='.$site->id.'&amp;conversation_presence=active', false);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-PRESERVE?conversation_filter=needs_reply&conversation_site='.$site->id.'&conversation_presence=active&conversation_search=Active')
            ->assertOk()
            ->assertSee('/dashboard/conversations?conversation_filter=needs_reply&amp;conversation_search=Active&amp;conversation_site='.$site->id.'&amp;conversation_presence=active', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard shows cobrowse transport health in the conversation queue', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

        $degradedConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COBDEGRADE',
            'subject' => 'Degraded cobrowse session',
            'status' => 'open',
            'last_message_at' => now()->subSeconds(30),
        ]);
        CobrowseSession::factory()->for($degradedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(3),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 2,
                ],
            ],
        ]);

        $liveConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COBLIVE',
            'subject' => 'Live cobrowse session',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);
        CobrowseSession::factory()->for($liveConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(3),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(20)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $staleConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COBSTALE',
            'subject' => 'Stale cobrowse session',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);
        CobrowseSession::factory()->for($staleConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(10),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subMinutes(4)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-NOCOB',
            'subject' => 'No cobrowse session',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(3),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertSee('Cobrowse')
            ->assertSeeInOrder(['Degraded cobrowse session', 'Degraded', 'Pressure 2 dropped batches'])
            ->assertSeeInOrder(['Live cobrowse session', 'Live', '20 seconds ago'])
            ->assertSeeInOrder(['Stale cobrowse session', 'Stale', '4 minutes ago'])
            ->assertSeeInOrder(['No cobrowse session', 'Unavailable', 'Not reported']);
    } finally {
        Carbon::setTestNow();
    }
});

test('cobrowse queue transport exposes stable machine states', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $transportState = app(CobrowseConsentState::class);

        $makeConversation = fn (string $supportCode): Conversation => Conversation::factory()
            ->for($site)
            ->for($visitor)
            ->create([
                'support_code' => $supportCode,
                'subject' => $supportCode,
                'status' => 'open',
            ]);

        $noSessionConversation = $makeConversation('WF-STATE-NONE');

        $endedConversation = $makeConversation('WF-STATE-END');
        CobrowseSession::factory()->for($endedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinute(),
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(15)->toJSON(),
                ],
            ],
        ]);

        $unreportedConversation = $makeConversation('WF-STATE-UNREP');
        CobrowseSession::factory()->for($unreportedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [],
        ]);

        $staleConversation = $makeConversation('WF-STATE-STALE');
        CobrowseSession::factory()->for($staleConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(10),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subMinutes(4)->toJSON(),
                ],
            ],
        ]);

        $reconnectingConversation = $makeConversation('WF-STATE-RECON');
        CobrowseSession::factory()->for($reconnectingConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 1,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $degradedConversation = $makeConversation('WF-STATE-DEGRADE');
        CobrowseSession::factory()->for($degradedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 2,
                ],
            ],
        ]);

        $liveConversation = $makeConversation('WF-STATE-LIVE');
        CobrowseSession::factory()->for($liveConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        expect($transportState->queueTransportForConversation($noSessionConversation)['state'])->toBe('unavailable')
            ->and($transportState->queueTransportForConversation($endedConversation)['state'])->toBe('unavailable')
            ->and($transportState->queueTransportForConversation($unreportedConversation)['state'])->toBe('unavailable')
            ->and($transportState->queueTransportForConversation($staleConversation)['state'])->toBe('stale')
            ->and($transportState->queueTransportForConversation($reconnectingConversation)['state'])->toBe('reconnecting')
            ->and($transportState->queueTransportForConversation($degradedConversation)['state'])->toBe('degraded')
            ->and($transportState->queueTransportForConversation($liveConversation)['state'])->toBe('live');
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard keeps visitor read state in conversation detail instead of the queue', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

        $seenConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-SEEN',
            'subject' => 'Reply was reviewed',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(3),
        ]);
        ConversationMessage::factory()->for($seenConversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Here is the answer.',
            'created_at' => now()->subMinutes(3),
            'seen_at' => now()->subMinute(),
        ]);

        $unseenConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-UNSEEN',
            'subject' => 'Visitor has not read the reply',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);
        ConversationMessage::factory()->for($unseenConversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Can you try this?',
            'created_at' => now()->subMinutes(2),
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-NOREPLY',
            'subject' => 'Visitor needs the first reply',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertDontSee('Visitor read')
            ->assertSee('Visitor needs the first reply')
            ->assertSee('Visitor has not read the reply')
            ->assertSee('Reply was reviewed')
            ->assertDontSee('No agent reply yet')
            ->assertDontSee('Not seen yet')
            ->assertDontSee('Visitor saw reply');

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-SEEN')
            ->assertOk()
            ->assertSee('Visitor read')
            ->assertSee('Visitor saw reply')
            ->assertSee('Seen 1 minute ago');
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard keeps visitor typing out of queue and conversation detail context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-TYPING',
            'subject' => 'Visitor is composing',
            'status' => 'open',
            'metadata' => [
                'visitor_typing_at' => now()->subSeconds(10)->toJSON(),
            ],
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-STALETYP',
            'subject' => 'Visitor paused',
            'status' => 'open',
            'metadata' => [
                'visitor_typing_at' => now()->subMinute()->toJSON(),
            ],
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-NOTYPING',
            'subject' => 'Quiet visitor',
            'status' => 'open',
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertDontSee('Visitor typing')
            ->assertSee('Visitor is composing')
            ->assertSee('Visitor paused')
            ->assertSee('Quiet visitor')
            ->assertDontSee('Typing now')
            ->assertDontSee('Not typing');

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-TYPING')
            ->assertOk()
            ->assertSee('Visitor is composing')
            ->assertDontSee('Visitor typing')
            ->assertDontSee('Typing now')
            ->assertDontSee('Updated 10 seconds ago')
            ->assertDontSee('data-visitor-typing-label', false)
            ->assertDontSee('data-visitor-typing-detail', false);
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can report a fresh typing signal for a conversation they can reply to', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-AGENTTYPE',
            'subject' => 'Visitor needs a reply',
            'status' => 'open',
        ]);

        Event::fake([ConversationTypingUpdated::class]);

        $this->actingAs($agent)
            ->postJson('/dashboard/conversations/WF-AGENTTYPE/typing', [
                'is_typing' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.conversation.support_code', 'WF-AGENTTYPE')
            ->assertJsonPath('data.agent_typing.state', 'typing')
            ->assertJsonPath('data.agent_typing.label', 'Support is typing...');

        $typing = $conversation->fresh()->metadata['agent_typing'][(string) $agent->id] ?? null;

        expect($typing['at'] ?? null)->toBe(now()->toJSON())
            ->and($typing['name'] ?? null)->toBe('Ada Agent');

        Event::assertDispatched(
            ConversationTypingUpdated::class,
            fn (ConversationTypingUpdated $event): bool => $event->conversation->id === $conversation->id,
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('agent typing signal cannot cross account boundaries', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OTHERTYPE',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->postJson('/dashboard/conversations/WF-OTHERTYPE/typing', [
            'is_typing' => true,
        ])
        ->assertNotFound();

    expect($conversation->fresh()->metadata ?? [])->not->toHaveKey('agent_typing');
});

test('dashboard filters conversations by attention state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NEEDS2',
        'subject' => 'Visitor needs a hand',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(2),
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help?',
        'created_at' => now()->subMinutes(2),
    ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-WAIT2',
        'subject' => 'Agent is waiting',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Can you try again?',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=needs_reply')
        ->assertOk()
        ->assertSee('Needs reply')
        ->assertSee('Visitor needs a hand')
        ->assertDontSee('Agent is waiting');
});

test('dashboard filters conversations by assignment state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-MINE',
        'subject' => 'Mine to answer',
        'status' => 'open',
    ]);

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-OPEN',
        'subject' => 'Ready to claim',
        'status' => 'open',
    ]);

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $otherAgent->id,
        'support_code' => 'WF-THEIRS',
        'subject' => 'Assigned elsewhere',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=assigned_to_me')
        ->assertOk()
        ->assertSee('Assigned to me')
        ->assertSee('Mine to answer')
        ->assertDontSee('Ready to claim')
        ->assertDontSee('Assigned elsewhere');

    $this->actingAs($agent)
        ->get('/dashboard/conversations?conversation_filter=unassigned')
        ->assertOk()
        ->assertSee('Unassigned')
        ->assertSee('Ready to claim')
        ->assertDontSee('Mine to answer')
        ->assertDontSee('Assigned elsewhere');
});

test('dashboard filters conversations by cobrowse transport attention', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

        $liveConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-LIVEFILTER',
            'subject' => 'Live cobrowse is fine',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);
        CobrowseSession::factory()->for($liveConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(15)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $staleConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-STALEFILTER',
            'subject' => 'Stale cobrowse needs a look',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);
        CobrowseSession::factory()->for($staleConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(10),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subMinutes(4)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $reconnectingConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RECONNECTFILTER',
            'subject' => 'Reconnecting cobrowse needs patience',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(3),
        ]);
        CobrowseSession::factory()->for($reconnectingConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(10)->toJSON(),
                    'reconnects' => 1,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $degradedConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-DEGRADEFILTER',
            'subject' => 'Degraded cobrowse needs confirmation',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(4),
        ]);
        CobrowseSession::factory()->for($degradedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 2,
                ],
            ],
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-NOCOBFILTER',
            'subject' => 'No cobrowse requested',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations?conversation_filter=cobrowse_attention')
            ->assertOk()
            ->assertSee('Cobrowse attention')
            ->assertSee('Stale cobrowse needs a look')
            ->assertSee('Stale')
            ->assertSee('Reconnecting cobrowse needs patience')
            ->assertSee('Reconnecting')
            ->assertSee('Degraded cobrowse needs confirmation')
            ->assertSee('Degraded')
            ->assertDontSee('Live cobrowse is fine')
            ->assertDontSee('No cobrowse requested');
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard shows cobrowse transport attention counts', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

        $liveConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COUNTLIVE',
            'subject' => 'Live cobrowse should not count',
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);
        CobrowseSession::factory()->for($liveConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(15)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $staleConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COUNTSTALE',
            'subject' => 'Stale cobrowse should count',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(2),
        ]);
        CobrowseSession::factory()->for($staleConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(10),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subMinutes(4)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 0,
                ],
            ],
        ]);

        $degradedConversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COUNTDEGRADE',
            'subject' => 'Degraded cobrowse should count',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(3),
        ]);
        CobrowseSession::factory()->for($degradedConversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinutes(5),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'reported_at' => now()->subSeconds(12)->toJSON(),
                    'reconnects' => 0,
                    'dropped_batches' => 2,
                ],
            ],
        ]);

        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-COUNTNOCOB',
            'subject' => 'No cobrowse should not count',
            'status' => 'open',
            'last_message_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations')
            ->assertOk()
            ->assertSee('2 cobrowse sessions need attention');
    } finally {
        Carbon::setTestNow();
    }
});

test('dashboard exposes conversation queue filter links', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('All open')
        ->assertSee('New activity')
        ->assertSee('Needs reply')
        ->assertSee('Assigned to me')
        ->assertSee('Unassigned')
        ->assertSee('/dashboard/conversations?conversation_filter=new_activity', false)
        ->assertSee('/dashboard/conversations?conversation_filter=needs_reply', false)
        ->assertSee('/dashboard/conversations?conversation_filter=assigned_to_me', false)
        ->assertSee('/dashboard/conversations?conversation_filter=unassigned', false)
        ->assertSee('/dashboard/conversations?conversation_filter=cobrowse_attention', false);
});

test('dashboard lists open tickets for the agent account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETDB',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'priority' => 'high',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Closed account issue',
            'status' => 'closed',
        ]);

    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'subject' => 'Other account issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Tickets')
        ->assertSee('1 open')
        ->assertSee('Escalated checkout issue')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false)
        ->assertSee('Acme Docs')
        ->assertSee('High')
        ->assertSee('WF-TICKETDB')
        ->assertSee(route('dashboard.support-code.lookup', ['support_code' => 'WF-TICKETDB']), false)
        ->assertDontSee('Closed account issue')
        ->assertDontSee('Other account issue')
        ->assertDontSee('Other Docs');
});

test('dashboard filters tickets by assignee state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Mine to resolve',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Ready for an owner',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($otherAgent, 'assignee')
        ->create([
            'subject' => 'Someone else is handling it',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_filter=assigned_to_me')
        ->assertOk()
        ->assertSee('Assigned to me')
        ->assertSee('Mine to resolve')
        ->assertDontSee('Ready for an owner')
        ->assertDontSee('Someone else is handling it');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_filter=unassigned')
        ->assertOk()
        ->assertSee('Unassigned')
        ->assertSee('Ready for an owner')
        ->assertDontSee('Mine to resolve')
        ->assertDontSee('Someone else is handling it');
});

test('dashboard filters tickets by status', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Active checkout issue',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Pending billing handoff',
            'status' => 'pending',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Resolved billing question',
            'status' => 'closed',
        ]);

    Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'subject' => 'Other account waiting',
            'status' => 'pending',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('1 open')
        ->assertSee('Active checkout issue')
        ->assertDontSee('Pending billing handoff')
        ->assertDontSee('Resolved billing question');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=pending')
        ->assertOk()
        ->assertSee('1 pending')
        ->assertSee('Pending billing handoff')
        ->assertDontSee('Active checkout issue')
        ->assertDontSee('Resolved billing question')
        ->assertDontSee('Other account waiting');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=closed')
        ->assertOk()
        ->assertSee('1 closed')
        ->assertSee('Resolved billing question')
        ->assertDontSee('Active checkout issue')
        ->assertDontSee('Pending billing handoff');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=all')
        ->assertOk()
        ->assertSee('3 total')
        ->assertSee('Active checkout issue')
        ->assertSee('Pending billing handoff')
        ->assertSee('Resolved billing question')
        ->assertDontSee('Other account waiting');
});

test('dashboard filters tickets by site without leaking unsupported sites', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $docsSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $storeSite = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Ops']);
    $restrictedSite->supportAgents()->attach($otherAgent);

    Ticket::factory()
        ->for($account)
        ->for($docsSite)
        ->create([
            'subject' => 'Docs checkout issue',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($storeSite)
        ->create([
            'subject' => 'Store order issue',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($restrictedSite)
        ->create([
            'subject' => 'Restricted escalation',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets?ticket_site={$docsSite->id}")
        ->assertOk()
        ->assertSee('Docs checkout issue')
        ->assertDontSee('Store order issue')
        ->assertDontSee('Restricted escalation')
        ->assertDontSee('Restricted Ops');

    $this->actingAs($agent)
        ->get("/dashboard/tickets?ticket_site={$restrictedSite->id}")
        ->assertOk()
        ->assertDontSee('Restricted escalation')
        ->assertDontSee('Restricted Ops');
});

test('dashboard filters tickets by priority', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Urgent production issue',
            'priority' => 'urgent',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Normal docs question',
            'priority' => 'normal',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_priority=urgent')
        ->assertOk()
        ->assertSee('Urgent production issue')
        ->assertDontSee('Normal docs question');
});

test('dashboard filters tickets by category', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'category' => 'bug',
            'subject' => 'Broken checkout flow',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'category' => 'question',
            'subject' => 'How do I export invoices?',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'category' => null,
            'subject' => 'Needs triage',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_category=bug')
        ->assertOk()
        ->assertSee('Broken checkout flow')
        ->assertDontSee('How do I export invoices?')
        ->assertDontSee('Needs triage');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_category=uncategorized')
        ->assertOk()
        ->assertSee('Needs triage')
        ->assertDontSee('Broken checkout flow')
        ->assertDontSee('How do I export invoices?');
});

test('dashboard searches tickets by subject description support code and requester references', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'email' => 'billing-contact@example.test',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SEARCH777',
        'status' => 'open',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Checkout handoff',
            'description' => 'Customer cannot export their invoice PDF.',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Password reset request',
            'description' => 'Visitor needs a login reset.',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_search=invoice')
        ->assertOk()
        ->assertSee('Checkout handoff')
        ->assertDontSee('Password reset request');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_search=WF-SEARCH777')
        ->assertOk()
        ->assertSee('Checkout handoff')
        ->assertDontSee('Password reset request');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_search=billing-contact@example.test')
        ->assertOk()
        ->assertSee('Checkout handoff')
        ->assertDontSee('Password reset request');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_search=anon-acme')
        ->assertOk()
        ->assertSee('Checkout handoff')
        ->assertDontSee('Password reset request');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_search=Ticket+%23'.$ticket->id)
        ->assertOk()
        ->assertSee('Checkout handoff')
        ->assertDontSee('Password reset request');
});

test('dashboard summarizes active ticket filters', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Store']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'category' => 'bug',
            'priority' => 'urgent',
            'status' => 'pending',
            'subject' => 'Checkout outage',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets?ticket_status=pending&ticket_filter=assigned_to_me&ticket_site={$site->id}&ticket_priority=urgent&ticket_category=bug&ticket_search=checkout")
        ->assertOk()
        ->assertSee('Active ticket filters')
        ->assertSee('Status: Pending')
        ->assertSee('Assignee: Assigned to me')
        ->assertSee('Site: Acme Store')
        ->assertSee('Priority: Urgent')
        ->assertSee('Category: Bug')
        ->assertSee('Search: checkout')
        ->assertSee('Clear all ticket filters')
        ->assertSee('/dashboard/tickets', false);
});

test('dashboard explains when ticket filters have no matches', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'priority' => 'low',
            'status' => 'open',
            'subject' => 'Docs typo',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_priority=urgent')
        ->assertOk()
        ->assertSee('No tickets match those filters.')
        ->assertSee('Clear all ticket filters')
        ->assertDontSee('No open tickets yet.');
});

test('dashboard surfaces ticket attention signals', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NEEDSREPLY',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can I get an update?',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Visitor is waiting',
            'status' => 'open',
        ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-WAITING',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I sent the next step.',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Customer has the next step',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Ready for an owner',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Waiting on customer answer',
            'status' => 'pending',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Resolved billing question',
            'status' => 'closed',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=all')
        ->assertOk()
        ->assertSee('Next step')
        ->assertSee('Needs reply')
        ->assertSee('Visitor replied last.')
        ->assertSee('Waiting on customer')
        ->assertSee('Agent replied last.')
        ->assertSee('Needs owner')
        ->assertSee('Assign this ticket to keep it moving.')
        ->assertSee('Marked pending.')
        ->assertSee('Resolved')
        ->assertSee('Ticket is closed.');
});

test('dashboard prioritizes tickets that need agent attention', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-WAITFIRST',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I already replied.',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Agent already replied',
            'status' => 'open',
            'updated_at' => now(),
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Ready for an owner',
            'status' => 'open',
            'updated_at' => now()->subMinutes(5),
        ]);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLYFIRST',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone look at this?',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Visitor response needed',
            'status' => 'open',
            'updated_at' => now()->subMinutes(10),
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSeeInOrder([
            'Visitor response needed',
            'Ready for an owner',
            'Agent already replied',
        ]);
});

test('dashboard filters tickets by next step', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NEEDSREPLY',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help?',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Visitor needs a reply',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Needs someone to own it',
            'status' => 'open',
        ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-WAITING',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I sent the next step.',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Waiting on the customer',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_attention=needs_reply')
        ->assertOk()
        ->assertSee('Next step: Needs reply')
        ->assertSee('Visitor needs a reply')
        ->assertDontSee('Needs someone to own it')
        ->assertDontSee('Waiting on the customer');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_attention=needs_owner')
        ->assertOk()
        ->assertSee('Next step: Needs owner')
        ->assertSee('Needs someone to own it')
        ->assertDontSee('Visitor needs a reply')
        ->assertDontSee('Waiting on the customer');

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_attention=waiting_on_customer')
        ->assertOk()
        ->assertSee('Next step: Waiting on customer')
        ->assertSee('Waiting on the customer')
        ->assertDontSee('Visitor needs a reply')
        ->assertDontSee('Needs someone to own it');
});

test('dashboard filters and labels recently escalated tickets', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $escalatingAgent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $site->supportAgents()->attach([$agent->id, $escalatingAgent->id]);

    $escalatedTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated billing question',
            'status' => 'open',
        ]);
    $escalatedTicket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $escalatingAgent->id,
        'action' => 'ticket.escalated',
        'metadata' => [
            'old_assignee_name' => 'Ada Agent',
            'target_agent_id' => $agent->id,
            'target_agent_name' => 'Bea Builder',
            'reason' => 'Customer needs a billing specialist.',
        ],
        'occurred_at' => now(),
    ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Ordinary owned issue',
            'status' => 'open',
        ]);

    $staleEscalatedTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Stale escalated issue',
            'status' => 'open',
        ]);
    $staleEscalatedTicket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $escalatingAgent->id,
        'action' => 'ticket.escalated',
        'metadata' => [
            'target_agent_id' => $agent->id,
            'target_agent_name' => 'Bea Builder',
        ],
        'occurred_at' => now()->subDays(2),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_attention=escalated')
        ->assertOk()
        ->assertSee('Next step: Recently escalated')
        ->assertSee('Recently escalated: 1')
        ->assertSee('Escalated billing question')
        ->assertSee('Escalated to you')
        ->assertDontSee('Ordinary owned issue')
        ->assertDontSee('Stale escalated issue');
});

test('dashboard summarizes the ticket queue next steps before the active next step filter is applied', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $needsReplyConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SNAPSHOTREPLY',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($needsReplyConversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Can someone help?',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($needsReplyConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Visitor needs a reply',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Needs someone to own it',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Ready for an agent update',
            'status' => 'open',
        ]);

    $waitingConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SNAPSHOTWAIT',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($waitingConversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I sent the next step.',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($waitingConversation)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Waiting on the customer',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'closed_at' => now(),
            'subject' => 'Resolved billing question',
            'status' => 'closed',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_status=all&ticket_attention=needs_reply')
        ->assertOk()
        ->assertSee('Queue snapshot')
        ->assertSee('Next-step counts respect the current queue filters before the next-step filter narrows the table.')
        ->assertSee('Needs reply: 1')
        ->assertSee('Needs owner: 1')
        ->assertSee('Needs agent: 1')
        ->assertSee('Waiting on customer: 1')
        ->assertSee('Resolved: 1')
        ->assertSee(route('dashboard.tickets.index', ['ticket_status' => 'all', 'ticket_attention' => 'needs_owner']))
        ->assertSee('Visitor needs a reply')
        ->assertDontSee('Needs someone to own it')
        ->assertDontSee('Ready for an agent update')
        ->assertDontSee('Waiting on the customer')
        ->assertDontSee('Resolved billing question');
});

test('dashboard resolved next step defaults to closed tickets', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'subject' => 'Active checkout issue',
            'status' => 'open',
        ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'closed_at' => now(),
            'subject' => 'Resolved billing question',
            'status' => 'closed',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_attention=resolved')
        ->assertOk()
        ->assertSee('1 closed')
        ->assertSee('Next step: Resolved')
        ->assertSee('Resolved billing question')
        ->assertDontSee('Active checkout issue');
});

test('dashboard exposes ticket queue filter links', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('All open')
        ->assertSee('Pending')
        ->assertSee('Closed')
        ->assertSee('All tickets')
        ->assertSee('Assigned to me')
        ->assertSee('Unassigned')
        ->assertSee('/dashboard/tickets?ticket_status=pending', false)
        ->assertSee('/dashboard/tickets?ticket_status=closed', false)
        ->assertSee('/dashboard/tickets?ticket_status=all', false)
        ->assertSee('/dashboard/tickets?ticket_filter=assigned_to_me', false)
        ->assertSee('/dashboard/tickets?ticket_filter=unassigned', false);
});

test('dashboard lists account agents with active workload counts', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create([
        'email' => 'ada@example.test',
        'name' => 'Ada Agent',
    ]);
    $teammate = User::factory()->for($account)->create([
        'email' => 'bea@example.test',
        'name' => 'Bea Builder',
    ]);
    $otherAgent = User::factory()->for($otherAccount)->create([
        'email' => 'otto@example.test',
        'name' => 'Otto Outside',
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create();
    $otherSite = Site::factory()->for($otherAccount)->create(['name' => 'Other Docs']);
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'status' => 'open',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'status' => 'closed',
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'closed']);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($teammate, 'assignee')
        ->count(2)
        ->create(['status' => 'open']);
    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'assigned_agent_id' => $otherAgent->id,
        'status' => 'open',
    ]);
    Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherAgent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Team')
        ->assertSee('Open conversations')
        ->assertSee('Open tickets')
        ->assertSeeInOrder(['Ada Agent', 'ada@example.test', '1', '1'])
        ->assertSeeInOrder(['Bea Builder', 'bea@example.test', '0', '2'])
        ->assertDontSee('Otto Outside')
        ->assertDontSee('otto@example.test');
});

test('dashboard shows ready realtime status when reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Ready')
        ->assertSee('Reverb broadcasts are configured.')
        ->assertSee('Broadcast driver')
        ->assertSee('reverb')
        ->assertSee('wayfindr.test:443')
        ->assertSee('https')
        ->assertSee('App ID')
        ->assertSee('Secret')
        ->assertSee('Set');
});

test('dashboard shows realtime setup guidance when reverb is incomplete', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', null);
    config()->set('broadcasting.connections.reverb.secret', null);
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', null);
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Needs setup')
        ->assertSee('Add Reverb app credentials and public host settings before enabling live updates.')
        ->assertSee('App key')
        ->assertSee('Missing')
        ->assertSee('App ID')
        ->assertSee('Set')
        ->assertSee('Secret')
        ->assertSee('Missing')
        ->assertSee('Endpoint')
        ->assertSee('Incomplete');
});

test('dashboard shows realtime disabled when broadcasting is not using reverb', function (): void {
    config()->set('broadcasting.default', 'log');

    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Realtime')
        ->assertSee('Disabled')
        ->assertSee('Set BROADCAST_CONNECTION=reverb to deliver live updates.')
        ->assertSee('Broadcast driver')
        ->assertSee('log');
});

test('dashboard reminds operators to respect retained visitor data', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Data responsibility')
        ->assertSee('Retaining visitor-supplied data may create privacy, security, and legal obligations.')
        ->assertSee('Keep only what you need, set a retention period you can justify, and make sure your privacy notice matches how this Wayfindr installation is used.');
});

test('agent can view their account conversation timeline', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-30 14:11:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-DETAIL1',
            'subject' => 'Checkout trouble',
            'status' => 'open',
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'First visitor message.',
            'created_at' => Carbon::parse('2026-05-30 14:00:00', 'UTC'),
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Visitor follow-up.',
            'created_at' => Carbon::parse('2026-05-30 14:02:00', 'UTC'),
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'First agent note.',
            'created_at' => Carbon::parse('2026-05-30 14:03:00', 'UTC'),
            'seen_at' => Carbon::parse('2026-05-30 14:04:00', 'UTC'),
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Later agent follow-up.',
            'created_at' => Carbon::parse('2026-05-30 14:10:00', 'UTC'),
        ]);

        $response = $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-DETAIL1')
            ->assertOk()
            ->assertSee('Checkout trouble')
            ->assertSee('Acme Docs')
            ->assertSee('anon-acme')
            ->assertSee('WF-DETAIL1')
            ->assertSee('Send reply')
            ->assertSee('name="body"', false)
            ->assertSee('datetime="2026-05-30T14:00:00.000000Z"', false)
            ->assertSee('datetime="2026-05-30T14:02:00.000000Z"', false)
            ->assertSee('Seen by visitor 7 minutes ago')
            ->assertSee('message visitor grouped', false)
            ->assertSeeInOrder(['First visitor message.', 'Visitor follow-up.', 'First agent note.', 'Later agent follow-up.']);

        expect(substr_count($response->content(), 'message visitor grouped'))->toBe(1);
        expect(substr_count($response->content(), 'message agent grouped'))->toBe(0);
        expect(substr_count($response->content(), 'Seen by visitor 7 minutes ago'))->toBe(1);
        expect(substr_count($response->content(), 'Not seen yet'))->toBe(2);
    } finally {
        Carbon::setTestNow();
    }
});

test('agent reply composer exposes progressive submission affordances', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLYUX',
        'subject' => 'Reply composer check',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REPLYUX')
        ->assertOk()
        ->assertSee('data-reply-composer', false)
        ->assertSee('data-submitting-label="Sending reply..."', false)
        ->assertSee('data-reply-body', false)
        ->assertSee('data-reply-submit', false)
        ->assertSee('data-reply-status', false)
        ->assertSee('aria-live="polite"', false);
});

test('agent can see latest reply read state while replying', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-READCTX',
            'subject' => 'Read context check',
            'last_message_at' => now()->subMinutes(4),
        ]);

        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'body' => 'Can you try this step?',
            'created_at' => now()->subMinutes(4),
            'seen_at' => now()->subMinute(),
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-READCTX')
            ->assertOk()
            ->assertSee('Visitor read')
            ->assertSee('Visitor saw reply')
            ->assertSee('Seen 1 minute ago');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can view safe visitor context on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'last_seen_at' => now()->subMinutes(7),
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/billing',
        ],
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONTEXT',
        'subject' => 'Billing confusion',
        'metadata' => [
            'started_page_url' => 'https://docs.example.test/pricing',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CONTEXT')
        ->assertOk()
        ->assertSee('Visitor at a glance')
        ->assertSee('Safe context only')
        ->assertSee('Presence')
        ->assertSee('Recently active')
        ->assertSee('Seen 7 minutes ago')
        ->assertSee('Last seen')
        ->assertSee('7 minutes ago')
        ->assertSee('Latest page')
        ->assertSee('https://docs.example.test/billing')
        ->assertSee('Entry page')
        ->assertSee('https://docs.example.test/pricing')
        ->assertSee('History on this site')
        ->assertSee('0 previous')
        ->assertSee('Data boundary')
        ->assertSee('Use this context to answer the current request. Do not collect, export, or infer extra visitor data without consent.');
});

test('agent can view safe host-provided visitor context', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'metadata' => [
            'context' => [
                'plan' => 'Team',
                'support_region' => 'EU',
                'password' => 'super-secret',
            ],
        ],
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-HOSTCTX',
        'subject' => 'Billing confusion',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-HOSTCTX')
        ->assertOk()
        ->assertSee('Host context')
        ->assertSee('plan')
        ->assertSee('Team')
        ->assertSee('support_region')
        ->assertSee('EU')
        ->assertDontSee('super-secret');
});

test('agent can view a safe host visitor identifier on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'external_id' => 'customer-123',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-HOSTID',
        'subject' => 'Billing confusion',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-HOSTID')
        ->assertOk()
        ->assertSee('Host visitor ID')
        ->assertSee('customer-123');
});

test('agent conversation page surfaces a support reference trail', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-reference',
        'external_id' => 'customer-789',
    ]);
    $otherVisitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PASTREF',
        'subject' => 'Earlier checkout issue',
        'created_at' => now()->subDays(2),
        'last_message_at' => now()->subDay(),
    ]);
    Conversation::factory()->for($site)->for($otherVisitor)->create([
        'support_code' => 'WF-NOTTHIS',
        'subject' => 'Different visitor issue',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CURRENTREF',
        'subject' => 'Current checkout issue',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CURRENTREF')
        ->assertOk()
        ->assertSee('Support references')
        ->assertSee('Use these references when the visitor or another agent needs to find this support trail again.')
        ->assertSee('Current support code')
        ->assertSee('WF-CURRENTREF')
        ->assertSee(route('dashboard.support-code.lookup', ['support_code' => 'WF-CURRENTREF']), false)
        ->assertSee('Visitor reference')
        ->assertSee('customer-789')
        ->assertSee('Same visitor support codes')
        ->assertSee('WF-PASTREF')
        ->assertSee('Earlier checkout issue')
        ->assertDontSee('WF-NOTTHIS')
        ->assertDontSee('Different visitor issue');
});

test('agent visitor context hides sensitive host visitor identifiers', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'external_id' => 'ada@example.test',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-HOSTPII',
        'subject' => 'Billing confusion',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-HOSTPII')
        ->assertOk()
        ->assertSee('Host visitor ID')
        ->assertSee('Not provided')
        ->assertDontSee('ada@example.test');
});

test('agent can view prior conversations for the same visitor and site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $otherSite = Site::factory()->for($account)->create(['name' => 'Acme Store']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $otherVisitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-other']);
    $otherSiteVisitor = Visitor::factory()->for($otherSite)->create(['anonymous_id' => 'anon-acme']);

    $ticketedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-OLDONE',
        'subject' => 'Earlier billing question',
        'status' => 'closed',
        'created_at' => now()->subDays(3),
        'last_message_at' => now()->subDays(2),
    ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($ticketedConversation)
        ->create([
            'assignee_id' => $assignedAgent->id,
            'subject' => 'Billing refund follow-up',
            'status' => 'pending',
        ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OLDER2',
        'subject' => 'Second earlier question',
        'status' => 'open',
        'created_at' => now()->subDays(5),
        'last_message_at' => now()->subDays(4),
    ]);
    Conversation::factory()->for($site)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
        'subject' => 'Different visitor question',
    ]);
    Conversation::factory()->for($otherSite)->for($otherSiteVisitor)->create([
        'support_code' => 'WF-OTHERS',
        'subject' => 'Other site question',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CURRENT',
        'subject' => 'Current billing question',
        'created_at' => now(),
        'last_message_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CURRENT')
        ->assertOk()
        ->assertSee('Prior conversations')
        ->assertSee('2 previous')
        ->assertSee('Owner')
        ->assertSee('Linked ticket')
        ->assertSee('Earlier billing question')
        ->assertSee('WF-OLDONE')
        ->assertSee('Bea Builder')
        ->assertSee('Billing refund follow-up')
        ->assertSee('Pending')
        ->assertSee('Second earlier question')
        ->assertSee('WF-OLDER2')
        ->assertSee('Unassigned')
        ->assertSee('No ticket')
        ->assertDontSee('Different visitor question')
        ->assertDontSee('Other site question');
});

test('agent can close an open conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CLOSE1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'closed_at' => null,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CLOSE1')
        ->assertOk()
        ->assertSee('Close conversation');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-CLOSE1')
        ->post('/dashboard/conversations/WF-CLOSE1/close')
        ->assertRedirect('/dashboard/conversations/WF-CLOSE1')
        ->assertSessionHas('status', 'Conversation closed.');

    $conversation->refresh();

    expect($conversation->status)->toBe('closed')
        ->and($conversation->closed_at)->not->toBeNull();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertDontSee('Checkout trouble');
});

test('agent can reopen a closed conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REOPEN1',
        'subject' => 'Checkout trouble',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REOPEN1')
        ->assertOk()
        ->assertSee('Reopen conversation');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REOPEN1')
        ->post('/dashboard/conversations/WF-REOPEN1/reopen')
        ->assertRedirect('/dashboard/conversations/WF-REOPEN1')
        ->assertSessionHas('status', 'Conversation reopened.');

    $conversation->refresh();

    expect($conversation->status)->toBe('open')
        ->and($conversation->closed_at)->toBeNull();
});

test('agent reply reopens a closed conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLYOP',
        'subject' => 'Checkout trouble',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REPLYOP')
        ->post('/dashboard/conversations/WF-REPLYOP/messages', [
            'body' => 'I can keep helping here.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLYOP')
        ->assertSessionHas('status', 'Reply sent.');

    $conversation->refresh();

    expect($conversation->status)->toBe('open')
        ->and($conversation->closed_at)->toBeNull()
        ->and($conversation->last_message_at)->not->toBeNull();
});

test('agent reply clears that agents typing signal', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $otherAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLYTYPE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'metadata' => [
            'agent_typing' => [
                (string) $agent->id => [
                    'at' => now()->toJSON(),
                    'name' => 'Ada Agent',
                ],
                (string) $otherAgent->id => [
                    'at' => now()->toJSON(),
                    'name' => 'Bea Builder',
                ],
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REPLYTYPE')
        ->post('/dashboard/conversations/WF-REPLYTYPE/messages', [
            'body' => 'I can help with that.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLYTYPE')
        ->assertSessionHas('status', 'Reply sent.');

    $typingSignals = $conversation->fresh()->metadata['agent_typing'] ?? [];

    expect($typingSignals)->not->toHaveKey((string) $agent->id)
        ->and($typingSignals)->toHaveKey((string) $otherAgent->id);
});

test('agent can claim an unassigned conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-CLAIM1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CLAIM1')
        ->assertOk()
        ->assertSee('Assigned to')
        ->assertSee('Unassigned')
        ->assertSee('Claim conversation');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-CLAIM1')
        ->post('/dashboard/conversations/WF-CLAIM1/claim')
        ->assertRedirect('/dashboard/conversations/WF-CLAIM1')
        ->assertSessionHas('status', 'Conversation claimed.');

    expect($conversation->fresh()->assigned_agent_id)->toBe($agent->id);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CLAIM1')
        ->assertOk()
        ->assertSee('Ada Agent')
        ->assertSee('Release conversation');
});

test('assigned agent can release a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-RELEASE1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-RELEASE1')
        ->post('/dashboard/conversations/WF-RELEASE1/release')
        ->assertRedirect('/dashboard/conversations/WF-RELEASE1')
        ->assertSessionHas('status', 'Conversation released.');

    expect($conversation->fresh()->assigned_agent_id)->toBeNull();
});

test('agent cannot release another agents conversation assignment', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-RELEASE2',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-RELEASE2/release')
        ->assertForbidden();

    expect($conversation->fresh()->assigned_agent_id)->toBe($assignedAgent->id);
});

test('agent reply claims an unassigned conversation without stealing assigned conversations', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignedAgent = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $unassignedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-AUTOSIGN',
        'subject' => 'Unassigned checkout trouble',
        'status' => 'open',
    ]);
    $assignedConversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $assignedAgent->id,
        'support_code' => 'WF-NOSTEAL',
        'subject' => 'Assigned checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-AUTOSIGN/messages', [
            'body' => 'I can help with this.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-AUTOSIGN');

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-NOSTEAL/messages', [
            'body' => 'Adding a note without taking ownership.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-NOSTEAL');

    expect($unassignedConversation->fresh()->assigned_agent_id)->toBe($agent->id)
        ->and($assignedConversation->fresh()->assigned_agent_id)->toBe($assignedAgent->id);
});

test('message direction changes the conversation attention state', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ATTEND1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinute(),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I am stuck.',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-ATTEND1')
        ->assertOk()
        ->assertSee('Attention')
        ->assertSee('Needs reply');

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-ATTEND1/messages', [
            'body' => 'Can you try that again?',
        ])
        ->assertRedirect('/dashboard/conversations/WF-ATTEND1');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-ATTEND1')
        ->assertOk()
        ->assertSee('Waiting on visitor');

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-acme',
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');

    $this->postJson('/api/conversations/WF-ATTEND1/messages', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-acme',
        'visitor_token' => $token,
        'body' => 'Still seeing the same issue.',
    ])->assertCreated();

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-ATTEND1')
        ->assertOk()
        ->assertSee('Needs reply');
});

test('visitor reply reopens a pending linked ticket for agent attention', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create([
        'name' => 'Acme Docs',
        'public_key' => 'site_public_docs',
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-CUSTOMER',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(5),
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Can you send a screenshot?',
        'created_at' => now()->subMinutes(5),
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'status' => 'pending',
            'closed_at' => null,
            'subject' => 'Escalated checkout issue',
        ]);

    expect($ticket->attentionState())->toBe('waiting_on_customer');

    $token = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-acme',
        'page_url' => 'https://docs.example.test/checkout',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');

    $this->postJson('/api/conversations/WF-CUSTOMER/messages', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-acme',
        'visitor_token' => $token,
        'body' => 'Here is the screenshot you asked for.',
    ])->assertCreated();

    $ticket = $ticket->fresh();

    expect($ticket)
        ->status->toBe('open')
        ->closed_at->toBeNull()
        ->attentionState()->toBe('needs_reply')
        ->attentionLabel()->toBe('Needs reply');

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => Visitor::class,
        'actor_id' => $visitor->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.visitor_replied',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Needs reply')
        ->assertSee('Visitor')
        ->assertSee('Visitor replied')
        ->assertDontSee('<strong>System</strong>', false)
        ->assertSee('Here is the screenshot you asked for.');
});

test('agent cannot close another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHERCL',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-OTHERCL/close')
        ->assertNotFound();
});

test('agent can create a ticket from their account conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'metadata' => [
            'last_page_url' => 'https://docs.example.test/checkout',
            'context' => [
                'plan' => 'Team',
                'support_region' => 'EU',
                'password' => 'super-secret',
            ],
        ],
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKET1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'metadata' => [
            'started_page_url' => 'https://docs.example.test/pricing',
        ],
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => now()->subMinutes(2),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I can help with that.',
        'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-TICKET1')
        ->post('/dashboard/conversations/WF-TICKET1/tickets', [
            'category' => 'bug',
            'priority' => 'high',
        ])
        ->assertRedirect('/dashboard/conversations/WF-TICKET1')
        ->assertSessionHas('status', 'Ticket created.');

    $this->assertDatabaseHas('tickets', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'conversation_id' => $conversation->id,
        'requester_id' => $visitor->id,
        'assignee_id' => $agent->id,
        'category' => 'bug',
        'status' => 'open',
        'priority' => 'high',
        'subject' => 'Checkout trouble',
    ]);

    $ticket = Ticket::query()->sole();

    expect($ticket->description)
        ->toContain('Visitor: The checkout button is stuck.')
        ->toContain('Ada Agent: I can help with that.');

    expect($ticket->metadata)->toMatchArray([
        'source' => 'conversation',
        'description_source' => 'conversation_transcript',
        'support_code' => 'WF-TICKET1',
        'visitor_context' => [
            'last_page_url' => 'https://docs.example.test/checkout',
            'started_page_url' => 'https://docs.example.test/pricing',
            'host_context' => [
                'plan' => 'Team',
                'support_region' => 'EU',
            ],
        ],
    ]);

    expect($ticket->metadata['visitor_context']['host_context'])->not->toHaveKey('password');

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.created',
    ]);

    expect($conversation->fresh()->assigned_agent_id)->toBe($agent->id);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TICKET1')
        ->assertOk()
        ->assertSee('Ticket created.')
        ->assertSee('Ticket')
        ->assertSee('Checkout trouble')
        ->assertSee("/dashboard/tickets/{$ticket->id}", false)
        ->assertSee('Bug')
        ->assertSee('High')
        ->assertSee('Open');
});

test('agent ticket creation explains provider-neutral categories', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CATEGORY1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CATEGORY1')
        ->assertOk()
        ->assertSee('Category')
        ->assertSee('Question - General question or how-to help.')
        ->assertSee('Bug - Something broken or not working as expected.')
        ->assertSee('Billing - Pricing, invoice, payment, or account billing issue.')
        ->assertSee('Access - Login, permissions, or account access issue.')
        ->assertSee('Task - Follow-up work, configuration, or operational request.')
        ->assertSee('Other - Anything that does not fit the other categories.');
});

test('agent ticket creation explains priority semantics', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIORITY1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-PRIORITY1')
        ->assertOk()
        ->assertSee('Urgent - Business-critical, active outage, or blocked production work.')
        ->assertSee('High - Time-sensitive issue affecting an important customer workflow.')
        ->assertSee('Normal - Standard support request with no immediate deadline.')
        ->assertSee('Low - Nice-to-have follow-up or non-blocking question.');
});

test('agent can view a durable ticket record for their account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignee = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETSHOW',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is still stuck.',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($assignee, 'assignee')
        ->create([
            'category' => 'access',
            'description' => 'The visitor cannot finish checkout after entering shipping details.',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Escalated checkout issue',
            'metadata' => [
                'visitor_context' => [
                    'last_page_url' => 'https://docs.example.test/checkout',
                    'started_page_url' => 'https://docs.example.test/pricing',
                    'host_context' => [
                        'plan' => 'Team',
                        'support_region' => 'EU',
                        'password' => 'super-secret',
                    ],
                ],
            ],
        ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.created',
        'metadata' => [
            'source' => 'conversation',
            'support_code' => 'WF-TICKETSHOW',
        ],
        'occurred_at' => now()->subMinute(),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Back to dashboard')
        ->assertSee('Escalated checkout issue')
        ->assertSee('Open')
        ->assertSee('Access')
        ->assertSee('High')
        ->assertSee('Acme Docs')
        ->assertSee('anon-acme')
        ->assertSee('WF-TICKETSHOW')
        ->assertSee('Checkout trouble')
        ->assertSee('View linked conversation')
        ->assertSee('Ticket created from conversation WF-TICKETSHOW')
        ->assertSee('Visitor at a glance')
        ->assertSee('Safe context only')
        ->assertSee('Latest page')
        ->assertSee('https://docs.example.test/checkout')
        ->assertSee('Entry page')
        ->assertSee('https://docs.example.test/pricing')
        ->assertSee('Host context')
        ->assertSee('plan')
        ->assertSee('Team')
        ->assertSee('support_region')
        ->assertSee('EU')
        ->assertDontSee('super-secret')
        ->assertSee('The visitor cannot finish checkout after entering shipping details.')
        ->assertSee('Bea Builder')
        ->assertSee('Assign ticket')
        ->assertSee('Close ticket')
        ->assertSee('/dashboard/conversations/WF-TICKETSHOW', false);
});

test('ticket detail and actions preserve ticket queue return context', function (): void {
    Event::fake([ConversationMessageCreated::class]);
    Notification::fake();

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignee = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $escalationAgent = User::factory()->for($account)->create(['name' => 'Cory Closer']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETRETURN',
        'subject' => 'Smoke checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'category' => 'billing',
            'description' => 'The smoke checkout path needs a steady follow-up.',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Smoke checkout follow-up',
        ]);
    $labelId = DB::table('ticket_labels')->insertGetId([
        'account_id' => $account->id,
        'name' => 'VIP',
        'slug' => 'vip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('ticket_label_ticket')->insert([
        'ticket_id' => $ticket->id,
        'ticket_label_id' => $labelId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $returnQuery = [
        'ticket_status' => 'all',
        'ticket_filter' => 'assigned_to_me',
        'ticket_site' => $site->id,
        'ticket_priority' => 'high',
        'ticket_category' => 'billing',
        'ticket_label' => 'vip',
        'ticket_attention' => 'needs_reply',
        'ticket_search' => 'smoke',
    ];
    $expectedDetailUrl = route('dashboard.tickets.show', ['ticket' => $ticket] + $returnQuery);

    $this->actingAs($agent)
        ->get($expectedDetailUrl.'&ignored=yes')
        ->assertOk()
        ->assertSee('Back to ticket queue')
        ->assertSee(str_replace('&', '&amp;', route('dashboard.tickets.index', $returnQuery)), false)
        ->assertDontSee('ignored=yes', false);

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/notes", $returnQuery + [
            'body' => 'Customer needs a careful follow-up.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket note added.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/labels", $returnQuery + [
            'label_name' => 'Needs Dev',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket label added.');

    $newLabelId = DB::table('ticket_labels')
        ->where('account_id', $account->id)
        ->where('slug', 'needs-dev')
        ->value('id');

    $this->actingAs($agent)
        ->delete("/dashboard/tickets/{$ticket->id}/labels/{$newLabelId}", $returnQuery)
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket label removed.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/replies", $returnQuery + [
            'message' => 'I can keep helping from the ticket.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Reply sent.');

    $this->actingAs($agent)
        ->put("/dashboard/tickets/{$ticket->id}", $returnQuery + [
            'subject' => 'Smoke checkout follow-up updated',
            'description' => 'The smoke checkout path still needs a steady follow-up.',
            'category' => 'billing',
            'priority' => 'high',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket updated.');

    $this->actingAs($agent)
        ->put("/dashboard/tickets/{$ticket->id}/assignee", $returnQuery + [
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket assignee updated.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/pending", $returnQuery + [
            'pending_note' => 'Waiting on customer confirmation.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket marked pending.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/close", $returnQuery + [
            'resolution_note' => 'Confirmed the support path is covered.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket closed.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/reopen", $returnQuery + [
            'reopen_note' => 'Need one more pass.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket reopened.');

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$ticket->id}/escalations", $returnQuery + [
            'target_agent_id' => $escalationAgent->id,
            'reason' => 'Needs another site-aware agent.',
        ])
        ->assertRedirect($expectedDetailUrl)
        ->assertSessionHas('status', 'Ticket escalated.');
});

test('agent can add a provider neutral external link to a ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LINK1',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-links", [
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'external_id' => '123',
            'external_key' => '#123',
            'url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
            'sync_status' => 'linked',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'External link added.');

    $this->assertDatabaseHas('ticket_external_links', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'ticket_id' => $ticket->id,
        'provider' => 'github',
        'project_key' => 'adamgreenwell/wayfindr',
        'external_id' => '123',
        'external_key' => '#123',
        'url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
        'sync_status' => 'linked',
    ]);

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.external_link_created',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('External links')
        ->assertSee('GitHub')
        ->assertSee('adamgreenwell/wayfindr')
        ->assertSee('#123')
        ->assertSee('Linked')
        ->assertSee('https://github.com/adamgreenwell/wayfindr/issues/123');
});

test('agent can remove a provider neutral external link from a ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LINK2',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);
    $externalLink = TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create([
            'provider' => 'gitlab',
            'project_key' => 'acme/docs',
            'external_id' => '321',
            'external_key' => '#321',
            'url' => 'https://gitlab.example.test/acme/docs/-/issues/321',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->delete("/dashboard/tickets/{$ticket->id}/external-links/{$externalLink->id}")
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'External link removed.');

    $this->assertDatabaseMissing('ticket_external_links', [
        'id' => $externalLink->id,
    ]);

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.external_link_removed',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('External link removed')
        ->assertDontSee('https://gitlab.example.test/acme/docs/-/issues/321');
});

test('agent cannot add an external link to another account ticket', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$otherTicket->id}/external-links", [
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'external_id' => '123',
            'external_key' => '#123',
            'url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
            'sync_status' => 'linked',
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('ticket_external_links', 0);
});

test('ticket detail shows a unified timeline across conversation messages notes and activity', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignee = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TIMELINE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $baseTime = now()->startOfMinute();

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is still stuck.',
        'created_at' => $baseTime,
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I can help with that.',
        'created_at' => $baseTime->copy()->addMinute(),
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.created',
        'metadata' => [
            'source' => 'conversation',
            'support_code' => 'WF-TIMELINE',
        ],
        'occurred_at' => $baseTime->copy()->addMinutes(2),
    ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.note_added',
        'metadata' => [
            'body' => 'Follow up with billing before noon.',
        ],
        'occurred_at' => $baseTime->copy()->addMinutes(3),
    ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.pending',
        'metadata' => [],
        'occurred_at' => $baseTime->copy()->addMinutes(4),
    ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.assignee_updated',
        'metadata' => [
            'old_assignee_name' => $agent->name,
            'new_assignee_name' => $assignee->name,
        ],
        'occurred_at' => $baseTime->copy()->addMinutes(5),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Timeline')
        ->assertSee('6 events')
        ->assertSee('Customer message')
        ->assertSee('Customer-visible')
        ->assertSee('Internal')
        ->assertSeeInOrder([
            'Visitor message',
            'The checkout button is still stuck.',
            'Agent reply',
            'I can help with that.',
            'Ticket created from conversation WF-TIMELINE',
            'Internal note',
            'Follow up with billing before noon.',
            'Ticket marked pending',
            'Assignee changed from Ada Agent to Bea Builder',
        ]);
});

test('ticket detail renders linked conversation messages with agent transcript grouping', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETCHAT',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => Carbon::parse('2026-05-30 14:00:00', 'UTC'),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Still stuck after a refresh.',
        'created_at' => Carbon::parse('2026-05-30 14:02:00', 'UTC'),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'I can help with that.',
        'created_at' => Carbon::parse('2026-05-30 14:03:00', 'UTC'),
        'seen_at' => Carbon::parse('2026-05-30 14:04:00', 'UTC'),
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['subject' => 'Escalated checkout issue']);

    $response = $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Recent conversation messages')
        ->assertSee('3 shown')
        ->assertSee('datetime="2026-05-30T14:00:00.000000Z"', false)
        ->assertSee('datetime="2026-05-30T14:02:00.000000Z"', false)
        ->assertSee('Seen by visitor')
        ->assertSeeInOrder([
            'The checkout button is stuck.',
            'Still stuck after a refresh.',
            'I can help with that.',
        ]);

    expect(substr_count($response->content(), 'message visitor grouped'))->toBe(1);
});

test('agent ticket detail explains priority semantics', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'category' => 'billing',
            'priority' => 'urgent',
            'status' => 'open',
            'subject' => 'Production checkout outage',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Billing')
        ->assertSee('Question - General question or how-to help.')
        ->assertSee('Bug - Something broken or not working as expected.')
        ->assertSee('Billing - Pricing, invoice, payment, or account billing issue.')
        ->assertSee('Access - Login, permissions, or account access issue.')
        ->assertSee('Task - Follow-up work, configuration, or operational request.')
        ->assertSee('Other - Anything that does not fit the other categories.')
        ->assertSee('Urgent - Business-critical, active outage, or blocked production work.')
        ->assertSee('High - Time-sensitive issue affecting an important customer workflow.')
        ->assertSee('Normal - Standard support request with no immediate deadline.')
        ->assertSee('Low - Nice-to-have follow-up or non-blocking question.');
});

test('agent can add an internal note to a ticket record', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/notes", [
            'body' => 'Customer wants an update before noon.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket note added.');

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.note_added',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Internal notes')
        ->assertSee('Ada Agent')
        ->assertSee('Customer wants an update before noon.');
});

test('ticket detail exposes internal note helpers', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Note helper')
        ->assertSee('Handoff summary')
        ->assertSee('Waiting on visitor')
        ->assertSee('Resolution summary');
});

test('ticket detail previews internal note helpers with private handoff guidance', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Note assist')
        ->assertSee('No note helper selected')
        ->assertSee('Handoff summary')
        ->assertSee('Handoff summary: include what was tried, current customer impact, and the next recommended step.')
        ->assertSee('data-template-preview-item="handoff_summary"', false)
        ->assertSee('Internal notes are private handoff context for your team, not visitor replies.')
        ->assertSee('Avoid storing sensitive details unless they are necessary for support continuity.');
});

test('agent can add an internal note from a ticket helper', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/notes", [
            'note_template' => 'handoff_summary',
            'body' => '',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket note added.');

    $activity = AuditEvent::query()
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('action', 'ticket.note_added')
        ->firstOrFail();

    expect($activity->metadata)->toMatchArray([
        'body' => 'Handoff summary: include what was tried, current customer impact, and the next recommended step.',
        'note_template' => 'handoff_summary',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Handoff summary: include what was tried, current customer impact, and the next recommended step.');
});

test('ticket internal note helpers validate selected helper', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/notes", [
            'note_template' => 'ship_it_anyway',
            'body' => '',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors('note_template');

    expect($ticket->auditEvents()->where('action', 'ticket.note_added')->count())->toBe(0);
});

test('agent can add and remove labels on a ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/labels", [
            'label_name' => 'Needs Dev',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket label added.');

    $label = DB::table('ticket_labels')
        ->where('account_id', $account->id)
        ->where('slug', 'needs-dev')
        ->first();

    expect($label)->not->toBeNull()
        ->and($label->name)->toBe('Needs Dev');

    $this->assertDatabaseHas('ticket_label_ticket', [
        'ticket_id' => $ticket->id,
        'ticket_label_id' => $label->id,
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Labels')
        ->assertSee('Needs Dev');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->delete("/dashboard/tickets/{$ticket->id}/labels/{$label->id}")
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket label removed.');

    $this->assertDatabaseMissing('ticket_label_ticket', [
        'ticket_id' => $ticket->id,
        'ticket_label_id' => $label->id,
    ]);
});

test('ticket labels reserve dashboard filter sentinel slugs', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/labels", [
            'label_name' => 'All',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors('label_name');

    $this->assertDatabaseMissing('ticket_labels', [
        'account_id' => $account->id,
        'slug' => 'all',
    ]);
});

test('dashboard filters tickets by label', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $labelId = DB::table('ticket_labels')->insertGetId([
        'account_id' => $account->id,
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $matchingTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Checkout outage',
            'status' => 'open',
        ]);
    $otherTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Billing question',
            'status' => 'open',
        ]);

    DB::table('ticket_label_ticket')->insert([
        'ticket_id' => $matchingTicket->id,
        'ticket_label_id' => $labelId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/tickets?ticket_label=needs-dev')
        ->assertOk()
        ->assertSee('Checkout outage')
        ->assertSee('Needs Dev')
        ->assertSee('Label: Needs Dev')
        ->assertDontSee('Billing question');

    expect($otherTicket->exists)->toBeTrue();
});

test('ticket labels are scoped to the agent account', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);
    $otherLabelId = DB::table('ticket_labels')->insertGetId([
        'account_id' => $otherAccount->id,
        'name' => 'Needs Dev',
        'slug' => 'needs-dev',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/labels", [
            'label_name' => 'Needs Dev',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket label added.');

    $ownLabel = DB::table('ticket_labels')
        ->where('account_id', $account->id)
        ->where('slug', 'needs-dev')
        ->first();

    expect($ownLabel)->not->toBeNull()
        ->and($ownLabel->id)->not->toBe($otherLabelId);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->delete("/dashboard/tickets/{$ticket->id}/labels/{$otherLabelId}")
        ->assertNotFound();

    $this->assertDatabaseHas('ticket_label_ticket', [
        'ticket_id' => $ticket->id,
        'ticket_label_id' => $ownLabel->id,
    ]);
});

test('agent can send a visitor reply from a linked ticket record', function (): void {
    Event::fake([ConversationMessageCreated::class]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-TICKETREPLY',
        'subject' => 'Checkout trouble',
        'status' => 'closed',
        'closed_at' => now()->subMinute(),
    ]);
    $visitorMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is still stuck.',
        'created_at' => now()->subMinute(),
    ]);
    $agent->notify(new ConversationNeedsReply($visitorMessage));

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/replies", [
            'message' => 'I can help from the ticket.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Reply sent.');

    $reply = $conversation->messages()->latest('id')->firstOrFail();

    expect($reply)
        ->sender_type->toBe(User::class)
        ->sender_id->toBe($agent->id)
        ->body->toBe('I can help from the ticket.')
        ->and($reply->metadata)->toMatchArray([
            'source' => 'ticket',
            'ticket_id' => $ticket->id,
        ]);

    expect($conversation->fresh())
        ->assigned_agent_id->toBe($agent->id)
        ->status->toBe('open')
        ->closed_at->toBeNull()
        ->last_message_at->not->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.reply_sent',
    ]);

    expect($agent->fresh()->unreadNotifications)->toHaveCount(0);

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->is($reply)
    );

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Visitor reply')
        ->assertSee('Recent conversation messages')
        ->assertSee('The checkout button is still stuck.')
        ->assertSee('I can help from the ticket.')
        ->assertSee('Visitor reply sent');
});

test('ticket detail exposes visitor reply helpers', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-HELPERS',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Reply helper')
        ->assertSee('Looking into it')
        ->assertSee('Need more detail')
        ->assertSee('Ticket follow-up');
});

test('ticket visitor reply composer exposes progressive submission affordances', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETUX',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('data-reply-composer', false)
        ->assertSee('data-submitting-label="Sending visitor reply..."', false)
        ->assertSee('data-reply-body', false)
        ->assertSee('data-shortcut-submit', false)
        ->assertSee('data-reply-status', false)
        ->assertSee('aria-live="polite"', false)
        ->assertSee('data-reply-submit', false)
        ->assertSee('Command or Control plus Enter sends this visitor reply.');
});

test('agent can send a visitor reply from a ticket helper', function (): void {
    Event::fake([ConversationMessageCreated::class]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => null,
        'support_code' => 'WF-HELPERREPLY',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/replies", [
            'reply_template' => 'looking_into_it',
            'message' => '',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Reply sent.');

    $reply = $conversation->messages()->latest('id')->firstOrFail();

    expect($reply)
        ->sender_type->toBe(User::class)
        ->sender_id->toBe($agent->id)
        ->body->toBe('Thanks for the update. I am looking into this now and will follow up shortly.')
        ->and($reply->metadata)->toMatchArray([
            'source' => 'ticket',
            'ticket_id' => $ticket->id,
            'reply_template' => 'looking_into_it',
        ]);

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.reply_sent',
    ]);

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->is($reply)
    );
});

test('ticket visitor replies validate message content', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BLANKREPLY',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/replies", [
            'message' => '   ',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors('message');

    expect($conversation->messages()->count())->toBe(0);
});

test('agent cannot send a visitor reply from another account ticket', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();
    $otherConversation = Conversation::factory()->for($otherSite)->for($otherVisitor)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherConversation)
        ->for($otherVisitor, 'requester')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$otherTicket->id}/replies", [
            'message' => 'Nope.',
        ])
        ->assertNotFound();

    expect($otherConversation->messages()->count())->toBe(0);
});

test('agent can update ticket fields from the detail page', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'category' => 'question',
            'subject' => 'Old checkout issue',
            'description' => 'The original ticket description.',
            'metadata' => [
                'description_source' => 'conversation_transcript',
            ],
            'priority' => 'normal',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->put("/dashboard/tickets/{$ticket->id}", [
            'category' => 'bug',
            'subject' => 'Updated checkout issue',
            'description' => 'The visitor cannot finish checkout on mobile.',
            'priority' => 'high',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket updated.');

    expect($ticket->fresh())
        ->category->toBe('bug')
        ->subject->toBe('Updated checkout issue')
        ->description->toBe('The visitor cannot finish checkout on mobile.')
        ->metadata->toMatchArray(['description_source' => 'agent_summary'])
        ->priority->toBe('high');

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.updated',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Updated checkout issue')
        ->assertSee('The visitor cannot finish checkout on mobile.')
        ->assertSee('Bug')
        ->assertSee('Category changed from Question to Bug')
        ->assertSee('High')
        ->assertSee('Subject changed from Old checkout issue to Updated checkout issue')
        ->assertSee('Priority changed from Normal to High')
        ->assertSee('Description updated');
});

test('ticket field updates validate editable fields', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->create([
            'category' => 'question',
            'subject' => 'Original subject',
            'description' => 'Original description.',
            'priority' => 'normal',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->put("/dashboard/tickets/{$ticket->id}", [
            'category' => 'space-laser',
            'subject' => '',
            'description' => 'Still valid text.',
            'priority' => 'maximum-chaos',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors(['category', 'subject', 'priority']);

    expect($ticket->fresh())
        ->category->toBe('question')
        ->subject->toBe('Original subject')
        ->description->toBe('Original description.')
        ->priority->toBe('normal');

    $this->assertDatabaseMissing('audit_events', [
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.updated',
    ]);
});

test('agent cannot update another account ticket fields', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'subject' => 'Other account issue',
            'priority' => 'normal',
        ]);

    $this->actingAs($agent)
        ->put("/dashboard/tickets/{$otherTicket->id}", [
            'subject' => 'Nope',
            'description' => 'Still nope.',
            'priority' => 'high',
        ])
        ->assertNotFound();

    expect($otherTicket->fresh())
        ->subject->toBe('Other account issue')
        ->priority->toBe('normal');
});

test('agent can close a ticket from its detail page', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAILCLOSE',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/close")
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket closed.');

    expect($ticket->fresh())
        ->status->toBe('closed')
        ->closed_at->not->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.closed',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Activity')
        ->assertSee('Ticket closed')
        ->assertSee('Ada Agent');
});

test('agent can close a ticket with a resolution note', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RESOLVED',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Resolution note');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/close", [
            'resolution_note' => 'Confirmed the checkout button works after cache clear.',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket closed.');

    $activity = AuditEvent::query()
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('action', 'ticket.closed')
        ->firstOrFail();

    expect($activity->metadata)->toMatchArray([
        'resolution_note' => 'Confirmed the checkout button works after cache clear.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Ticket closed')
        ->assertSee('Confirmed the checkout button works after cache clear.');
});

test('agent can mark a ticket pending from its detail page', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DETAILPEND',
        'subject' => 'Checkout trouble',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Waiting on customer answer',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/pending")
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Ticket marked pending.');

    expect($ticket->fresh())
        ->status->toBe('pending')
        ->closed_at->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.pending',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Pending')
        ->assertSee('Ticket marked pending')
        ->assertSee('Ada Agent');
});

test('agent cannot view another account ticket record', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create();

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$otherTicket->id}")
        ->assertNotFound();
});

test('creating a ticket from a conversation is idempotent', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKET2',
        'subject' => 'Existing handoff',
    ]);

    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Existing handoff',
        ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-TICKET2')
        ->post('/dashboard/conversations/WF-TICKET2/tickets', [
            'priority' => 'urgent',
        ])
        ->assertRedirect('/dashboard/conversations/WF-TICKET2')
        ->assertSessionHas('status', 'Ticket already exists.');

    $this->assertDatabaseCount('tickets', 1);
});

test('agent can close a linked ticket from their account conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETCLOSE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TICKETCLOSE')
        ->assertOk()
        ->assertSee('Escalated checkout issue')
        ->assertSee('Close ticket');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-TICKETCLOSE')
        ->post("/dashboard/tickets/{$ticket->id}/close")
        ->assertRedirect('/dashboard/conversations/WF-TICKETCLOSE')
        ->assertSessionHas('status', 'Ticket closed.');

    expect($ticket->fresh())
        ->status->toBe('closed')
        ->closed_at->not->toBeNull();

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertDontSee('Escalated checkout issue');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TICKETCLOSE')
        ->assertOk()
        ->assertSee('Closed')
        ->assertSee('Reopen ticket');
});

test('agent can close a linked ticket with a resolution note from the conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVRESOLVE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CONVRESOLVE')
        ->assertOk()
        ->assertSee('Resolution note')
        ->assertSee('Close ticket');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-CONVRESOLVE')
        ->post("/dashboard/tickets/{$ticket->id}/close", [
            'resolution_note' => 'Confirmed the visitor can complete checkout.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-CONVRESOLVE')
        ->assertSessionHas('status', 'Ticket closed.');

    $activity = AuditEvent::query()
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('action', 'ticket.closed')
        ->firstOrFail();

    expect($activity->metadata)->toMatchArray([
        'resolution_note' => 'Confirmed the visitor can complete checkout.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Confirmed the visitor can complete checkout.');
});

test('linked ticket close validation keeps resolution notes scoped to the submitted ticket', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CONVRESERR',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $submittedTicket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Submitted ticket',
            'status' => 'open',
            'closed_at' => null,
        ]);
    Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Other linked ticket',
            'status' => 'open',
            'closed_at' => null,
        ]);

    $tooLongNote = str_repeat('x', 4001);

    $response = $this->actingAs($agent)
        ->followingRedirects()
        ->from('/dashboard/conversations/WF-CONVRESERR')
        ->post("/dashboard/tickets/{$submittedTicket->id}/close", [
            '_ticket_close_id' => $submittedTicket->id,
            'resolution_note' => $tooLongNote,
        ])
        ->assertOk()
        ->assertSee('Submitted ticket')
        ->assertSee('Other linked ticket');

    expect(substr_count($response->getContent(), $tooLongNote))->toBe(1)
        ->and(substr_count($response->getContent(), 'The resolution note field must not be greater than 4000 characters.'))->toBe(1)
        ->and($submittedTicket->fresh()->status)->toBe('open');
});

test('agent can reopen a linked ticket from their account conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETOPEN',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'closed',
            'closed_at' => now()->subMinute(),
        ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-TICKETOPEN')
        ->post("/dashboard/tickets/{$ticket->id}/reopen")
        ->assertRedirect('/dashboard/conversations/WF-TICKETOPEN')
        ->assertSessionHas('status', 'Ticket reopened.');

    expect($ticket->fresh())
        ->status->toBe('open')
        ->closed_at->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.reopened',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Activity')
        ->assertSee('Ticket reopened')
        ->assertSee('Escalated checkout issue');

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Escalated checkout issue');
});

test('agent can reassign a linked ticket to another account agent', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $assignee = User::factory()->for($account)->create(['name' => 'Bea Builder']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ASSIGNT1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Escalated checkout issue',
            'status' => 'open',
        ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-ASSIGNT1')
        ->assertOk()
        ->assertSee('Assign ticket')
        ->assertSee('Ada Agent')
        ->assertSee('Bea Builder');

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-ASSIGNT1')
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect('/dashboard/conversations/WF-ASSIGNT1')
        ->assertSessionHas('status', 'Ticket assignee updated.');

    expect($ticket->fresh()->assignee_id)->toBe($assignee->id);

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.assignee_updated',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Activity')
        ->assertSee('Assignee changed from Ada Agent to Bea Builder');

    $this->actingAs($agent)
        ->get('/dashboard/tickets')
        ->assertOk()
        ->assertSee('Assignee')
        ->assertSee('Bea Builder');
});

test('agent can clear a linked ticket assignee', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-UNASSIGNT',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-UNASSIGNT')
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => '',
        ])
        ->assertRedirect('/dashboard/conversations/WF-UNASSIGNT')
        ->assertSessionHas('status', 'Ticket assignee updated.');

    expect($ticket->fresh()->assignee_id)->toBeNull();

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-UNASSIGNT')
        ->assertOk()
        ->assertSee('Unassigned');
});

test('agent cannot assign a ticket to another account agent', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($otherAccount)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->from('/dashboard')
        ->put("/dashboard/tickets/{$ticket->id}/assignee", [
            'assignee_id' => $otherAgent->id,
        ])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBe($agent->id);
});

test('agent cannot close another account ticket', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherTicket = Ticket::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->create([
            'status' => 'open',
            'closed_at' => null,
        ]);

    $this->actingAs($agent)
        ->post("/dashboard/tickets/{$otherTicket->id}/close")
        ->assertNotFound();

    expect($otherTicket->fresh())
        ->status->toBe('open')
        ->closed_at->toBeNull();
});

test('agent cannot create a ticket for another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-TICKET3',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-TICKET3/tickets', [
            'priority' => 'high',
        ])
        ->assertNotFound();

    $this->assertDatabaseCount('tickets', 0);
});

test('agent conversation page exposes live cobrowse update readiness when reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVECOBROWSE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIVECOBROWSE')
        ->assertOk()
        ->assertSee('Cobrowse updates')
        ->assertSee('Waiting for live cobrowse updates.')
        ->assertSee('data-cobrowse-update-status', false)
        ->assertSee('conversation.cobrowse.updated')
        ->assertSee('conversation.typing.updated')
        ->assertSee('private-conversations.WF-LIVECOBROWSE')
        ->assertSee('"appKey":"reverb-key"', false)
        ->assertSee('"host":"wayfindr.test"', false);
});

test('agent conversation page exposes live cobrowse transport health targets when reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVEHEALTH',
        'subject' => 'Transport should feel alive',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIVEHEALTH')
        ->assertOk()
        ->assertSee('data-cobrowse-transport-label', false)
        ->assertSee('data-cobrowse-transport-message', false)
        ->assertSee('data-cobrowse-transport-state-label', false)
        ->assertSee('data-cobrowse-transport-last-report', false)
        ->assertSee('data-cobrowse-transport-reconnects', false)
        ->assertSee('data-cobrowse-transport-pressure', false)
        ->assertSee('data-cobrowse-transport-guidance', false)
        ->assertSee('data-cobrowse-telemetry-empty', false)
        ->assertSee('data-cobrowse-telemetry-grid', false)
        ->assertSee('data-cobrowse-telemetry-rtt', false)
        ->assertSee('data-cobrowse-telemetry-max-rtt', false)
        ->assertSee('data-cobrowse-telemetry-payload', false)
        ->assertSee('data-cobrowse-telemetry-max-payload', false)
        ->assertSee('data-cobrowse-telemetry-dropped-batches', false)
        ->assertSee('data-cobrowse-telemetry-reconnects', false)
        ->assertSee('data-cobrowse-telemetry-samples', false)
        ->assertSee('function telemetryIsFreshForUpdate', false)
        ->assertSee('function pressureIsFreshForUpdate', false)
        ->assertSee('function transportPressureFromSummary', false)
        ->assertSee('pressureIsFreshForUpdate(pressure, payload)', false)
        ->assertSee('summary.transport_pressure', false)
        ->assertSee('skipped_mutations', false)
        ->assertSee('telemetry.reported_at', false)
        ->assertSee('Connection telemetry updated live.', false)
        ->assertSee('Fresh snapshot retry limit reached.', false)
        ->assertSee("transportRecovery.dataset.recoveryLocked === 'true' && health.state !== 'exhausted'", false)
        ->assertSee("transportRecovery.dataset.recoveryLocked = 'false';", false);
});

test('agent conversation page exposes live snapshot freshness targets when reverb is configured', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVESNAPSHOT',
        'subject' => 'Snapshot should stay fresh',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install?step=2',
                'title' => 'Install Guide',
                'text' => 'Public checkout content. [masked]',
                'node_count' => 8,
                'masked_count' => 2,
                'reported_at' => now()->subSeconds(45)->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIVESNAPSHOT')
        ->assertOk()
        ->assertSee('data-cobrowse-snapshot-status', false)
        ->assertSee('data-cobrowse-snapshot-freshness-label', false)
        ->assertSee('data-cobrowse-snapshot-freshness-message', false)
        ->assertSee('data-cobrowse-snapshot-freshness-reported', false)
        ->assertSee('function updateSnapshotFreshness', false)
        ->assertSee('summary.snapshot', false)
        ->assertSee('Fresh snapshot received live. Refresh the preview when you are ready.', false);
});

test('agent conversation page can update visitor presence from live events', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-acme',
        'last_seen_at' => null,
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVEPRES',
        'subject' => 'Presence should feel fresh',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIVEPRES')
        ->assertOk()
        ->assertSee('Visitor at a glance')
        ->assertSee('Not reported')
        ->assertSee('No visitor heartbeat yet.')
        ->assertSee('data-visitor-presence-label', false)
        ->assertSee('data-visitor-presence-detail', false)
        ->assertSee('data-visitor-presence-last-seen', false)
        ->assertSee('conversation.presence.updated')
        ->assertSee('last_seen_label')
        ->assertSee('updateVisitorPresence')
        ->assertSee('"presenceEventName":"conversation.presence.updated"', false);
});

test('agent conversation page can update visitor read receipts from live events', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIVEREAD',
        'subject' => 'Read receipts should feel fresh',
        'status' => 'open',
    ]);
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'body' => 'Can you try signing in again?',
        'created_at' => now()->subMinute(),
        'seen_at' => null,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIVEREAD')
        ->assertOk()
        ->assertSee('Visitor read')
        ->assertSee('Not seen yet')
        ->assertSee('data-visitor-read-label', false)
        ->assertSee('data-visitor-read-detail', false)
        ->assertSee('data-agent-message-seen-id="'.$message->id.'"', false)
        ->assertSee('conversation.read.updated')
        ->assertSee('message_id')
        ->assertSee('seen_label')
        ->assertSee('updateVisitorRead')
        ->assertSee('querySelector(\'[data-agent-message-seen-id="\' + messageId + \'"]\')', false)
        ->assertSee('"readEventName":"conversation.read.updated"', false);
});

test('agent conversation page omits visitor typing detail targets', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TYPEEXPIRE',
        'subject' => 'Typing should not stick',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TYPEEXPIRE')
        ->assertOk()
        ->assertDontSee('data-visitor-typing-label', false)
        ->assertDontSee('data-visitor-typing-detail', false)
        ->assertDontSee('clearVisitorTypingExpiry')
        ->assertDontSee('scheduleVisitorTypingExpiry')
        ->assertDontSee('visitorTypingExpiryTimer');
});

test('agent can see cobrowse consent state on a conversation', function (?array $sessionAttributes, string $label, string $message): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-COBROWSE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    if ($sessionAttributes) {
        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create($sessionAttributes);
    }

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-COBROWSE')
        ->assertOk()
        ->assertSee('Cobrowse')
        ->assertSee($label)
        ->assertSee($message);
})->with([
    'unavailable' => [
        null,
        'Unavailable',
        'Visitor has not granted cobrowse consent.',
    ],
    'pending' => [
        ['status' => 'requested', 'consented_at' => null, 'ended_at' => null],
        'Pending consent',
        'Waiting for visitor consent before cobrowsing can start.',
    ],
    'granted' => [
        ['status' => 'granted', 'consented_at' => now()->subMinute(), 'ended_at' => null],
        'Granted',
        'Visitor granted cobrowse consent.',
    ],
    'revoked' => [
        ['status' => 'revoked', 'consented_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()],
        'Revoked',
        'Visitor revoked cobrowse consent.',
    ],
    'ended' => [
        ['status' => 'ended', 'consented_at' => now()->subMinutes(2), 'ended_at' => now()->subMinute()],
        'Ended',
        'Cobrowse session ended.',
    ],
]);

test('agent can see cobrowse lifecycle details on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-LIFECYCLE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->for($agent, 'requestedBy')->create([
        'status' => 'ended',
        'consented_at' => now()->subMinutes(3),
        'ended_at' => now()->subMinute(),
        'created_at' => now()->subMinutes(5),
        'metadata' => [
            'ended_by_type' => 'agent',
            'ended_by_name' => 'Ada Agent',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-LIFECYCLE')
        ->assertOk()
        ->assertSee('Session timeline')
        ->assertSee('Requested by')
        ->assertSee('Ada Agent')
        ->assertSee('Requested')
        ->assertSee('Consent granted')
        ->assertSee('Stopped')
        ->assertSee('Stopped by');
});

test('agent can request cobrowse consent for their account conversation', function (): void {
    Event::fake([CobrowseStateUpdated::class]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REQUEST1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REQUEST1')
        ->post('/dashboard/conversations/WF-REQUEST1/cobrowse/request')
        ->assertRedirect('/dashboard/conversations/WF-REQUEST1')
        ->assertSessionHas('status', 'Cobrowse requested.');

    $this->assertDatabaseHas('cobrowse_sessions', [
        'conversation_id' => $conversation->id,
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'requested_by_id' => $agent->id,
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);

    Event::assertDispatched(
        CobrowseStateUpdated::class,
        fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->conversation_id === $conversation->id
            && $event->kind === 'consent_requested'
    );

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REQUEST1')
        ->assertOk()
        ->assertSee('Pending consent')
        ->assertSee('Waiting for visitor consent before cobrowsing can start.')
        ->assertSee('Cancel request');
});

test('agent cobrowse request is idempotent while a session is active', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REQUEST2',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REQUEST2')
        ->post('/dashboard/conversations/WF-REQUEST2/cobrowse/request')
        ->assertRedirect('/dashboard/conversations/WF-REQUEST2')
        ->assertSessionHas('status', 'Cobrowse request already active.');

    $this->assertDatabaseCount('cobrowse_sessions', 1);
});

test('agent can end an active cobrowse session', function (): void {
    Event::fake([CobrowseStateUpdated::class]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-END1',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'page_state' => [
                'page_url' => 'https://docs.example.test/install',
                'title' => 'Install Guide',
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-END1')
        ->post('/dashboard/conversations/WF-END1/cobrowse/end')
        ->assertRedirect('/dashboard/conversations/WF-END1')
        ->assertSessionHas('status', 'Cobrowse session ended.');

    expect($session->fresh())
        ->status->toBe('ended')
        ->ended_at->not->toBeNull()
        ->metadata->toMatchArray([
            'ended_by_id' => $agent->id,
            'ended_by_type' => 'agent',
        ]);

    Event::assertDispatched(
        CobrowseStateUpdated::class,
        fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->id === $session->id
            && $event->kind === 'ended'
    );

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-END1')
        ->assertOk()
        ->assertSee('Ended')
        ->assertSee('Cobrowse session ended.')
        ->assertSee('Request cobrowse');
});

test('agent can request a fresh cobrowse snapshot for a granted session', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));
    Event::fake([CobrowseStateUpdated::class]);

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC',
            'subject' => 'Cobrowse drifted',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'snapshot' => [
                    'page_url' => 'https://docs.example.test/old',
                    'title' => 'Old page',
                    'reported_at' => now()->subMinutes(3)->toJSON(),
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->from('/dashboard/conversations/WF-RESYNC')
            ->post('/dashboard/conversations/WF-RESYNC/cobrowse/resync')
            ->assertRedirect('/dashboard/conversations/WF-RESYNC')
            ->assertSessionHas('status', 'Fresh cobrowse snapshot requested.');

        $session->refresh();

        expect($session->metadata['resync_request'])
            ->requested_by_id->toBe($agent->id)
            ->requested_by_name->toBe('Ada Agent')
            ->requested_at->toBe(now()->toJSON())
            ->fulfilled_at->toBeNull()
            ->and($session->metadata['resync_request']['id'])->toBeString()->not->toBeEmpty();

        Event::assertDispatched(
            CobrowseStateUpdated::class,
            fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->id === $session->id
                && $event->kind === 'resync_requested'
        );

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC')
            ->assertOk()
            ->assertSee('Fresh snapshot already requested')
            ->assertSee('Waiting for the visitor widget before requesting another snapshot.')
            ->assertSee('data-resync-retry-form', false)
            ->assertSee('data-retry-at="'.now()->addMinute()->toJSON().'"', false)
            ->assertSee('data-retry-label="Request another fresh snapshot"', false)
            ->assertSee('data-retry-ready-help="Still waiting. You can request another fresh snapshot now."', false)
            ->assertDontSee('<button class="button secondary" type="submit">Request fresh snapshot</button>', false)
            ->assertSee('data-state="pending"', false)
            ->assertSee('Fresh snapshot requested')
            ->assertSee('Waiting for the visitor widget to send a clean page snapshot.')
            ->assertSee('Recovery timeline')
            ->assertSee('Snapshot requested')
            ->assertSee('Waiting on visitor widget')
            ->assertSee('Retry opens 1 minute from now.')
            ->assertSee('Expires 5 minutes from now');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent cannot replace a fresh pending cobrowse resync request with another click', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));
    Event::fake([CobrowseStateUpdated::class]);

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC4',
            'subject' => 'Cobrowse double click',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_existing',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(15)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->from('/dashboard/conversations/WF-RESYNC4')
            ->post('/dashboard/conversations/WF-RESYNC4/cobrowse/resync')
            ->assertRedirect('/dashboard/conversations/WF-RESYNC4')
            ->assertSessionHas('status', 'Fresh cobrowse snapshot already requested.');

        expect($session->fresh()->metadata['resync_request'])
            ->id->toBe('resync_existing')
            ->requested_at->toBe(now()->subSeconds(15)->toJSON())
            ->fulfilled_at->toBeNull();

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC4')
            ->assertOk()
            ->assertSee('Fresh snapshot already requested')
            ->assertSee('Waiting for the visitor widget before requesting another snapshot.')
            ->assertSee('data-resync-retry-form', false)
            ->assertSee('data-retry-at="'.now()->addSeconds(45)->toJSON().'"', false)
            ->assertSee('data-retry-label="Request another fresh snapshot"', false)
            ->assertSee('data-retry-ready-help="Still waiting. You can request another fresh snapshot now."', false)
            ->assertDontSee('<button class="button secondary" type="submit">Request fresh snapshot</button>', false);

        Event::assertNotDispatched(CobrowseStateUpdated::class);
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can replace a delayed pending cobrowse resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));
    Event::fake([CobrowseStateUpdated::class]);

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC5',
            'subject' => 'Cobrowse retry',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(3),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_delayed',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(2)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->from('/dashboard/conversations/WF-RESYNC5')
            ->post('/dashboard/conversations/WF-RESYNC5/cobrowse/resync')
            ->assertRedirect('/dashboard/conversations/WF-RESYNC5')
            ->assertSessionHas('status', 'Fresh cobrowse snapshot requested.');

        $session->refresh();

        expect($session->metadata['resync_request'])
            ->id->not->toBe('resync_delayed')
            ->requested_by_id->toBe($agent->id)
            ->requested_at->toBe(now()->toJSON())
            ->fulfilled_at->toBeNull();

        Event::assertDispatched(
            CobrowseStateUpdated::class,
            fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->id === $session->id
                && $event->kind === 'resync_requested'
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can replace an exhausted cobrowse resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));
    Event::fake([CobrowseStateUpdated::class]);

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC-EXHAUSTED-RETRY',
            'subject' => 'Cobrowse exhausted retry',
        ]);
        $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(3),
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

        $this->actingAs($agent)
            ->from('/dashboard/conversations/WF-RESYNC-EXHAUSTED-RETRY')
            ->post('/dashboard/conversations/WF-RESYNC-EXHAUSTED-RETRY/cobrowse/resync')
            ->assertRedirect('/dashboard/conversations/WF-RESYNC-EXHAUSTED-RETRY')
            ->assertSessionHas('status', 'Fresh cobrowse snapshot requested.');

        $session->refresh();

        expect($session->metadata['resync_request'])
            ->id->not->toBe('resync_exhausted')
            ->requested_by_id->toBe($agent->id)
            ->requested_at->toBe(now()->toJSON())
            ->fulfilled_at->toBeNull()
            ->and($session->metadata['resync_request'])->not->toHaveKey('attempts_exhausted_at');

        Event::assertDispatched(
            CobrowseStateUpdated::class,
            fn (CobrowseStateUpdated $event): bool => $event->cobrowseSession->id === $session->id
                && $event->kind === 'resync_requested'
        );
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can see a fulfilled cobrowse resync request', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC2',
            'subject' => 'Cobrowse recovered',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(2),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_received',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinute()->toJSON(),
                    'fulfilled_at' => now()->subSeconds(20)->toJSON(),
                    'fulfilled_snapshot_reported_at' => now()->subSeconds(22)->toJSON(),
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC2')
            ->assertOk()
            ->assertSee('data-state="fulfilled"', false)
            ->assertSee('Fresh snapshot received')
            ->assertSee('The visitor widget sent a clean masked snapshot.')
            ->assertSee('Received 20 seconds ago')
            ->assertSee('Recovery timeline')
            ->assertSee('Snapshot requested')
            ->assertSee('Visitor widget responded')
            ->assertSee('Masked snapshot refreshed');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can see delayed cobrowse resync guidance', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC3',
            'subject' => 'Cobrowse still stale',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(4),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_delayed',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(2)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC3')
            ->assertOk()
            ->assertSee('data-state="delayed"', false)
            ->assertSee('Fresh snapshot delayed')
            ->assertSee('The visitor widget has not answered yet. Request another clean snapshot or confirm the page state through chat.')
            ->assertSee('Expires 3 minutes from now')
            ->assertSee('Recovery timeline')
            ->assertSee('Snapshot requested')
            ->assertSee('Retry available')
            ->assertSee('Request expires')
            ->assertSee('Request another fresh snapshot');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can see exhausted cobrowse resync guidance', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC7',
            'subject' => 'Cobrowse retry limit reached',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(4),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_exhausted',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(30)->toJSON(),
                    'fulfilled_at' => null,
                    'attempts_exhausted_at' => now()->subSeconds(10)->toJSON(),
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC7')
            ->assertOk()
            ->assertSee('data-state="exhausted"', false)
            ->assertSee('Fresh snapshot retry limit reached')
            ->assertSee('The visitor widget tried to send a clean snapshot but could not complete it. Request another clean snapshot or confirm the page state through chat.')
            ->assertSee('Recovery timeline')
            ->assertSee('Snapshot requested')
            ->assertSee('Retry limit reached')
            ->assertSee('The visitor widget stopped retrying this request ID after repeated failures.')
            ->assertSee('Request another fresh snapshot');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can see exhausted cobrowse resync guidance after the request expires', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC8',
            'subject' => 'Cobrowse expired retry limit reached',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(10),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_exhausted',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(6)->toJSON(),
                    'fulfilled_at' => null,
                    'attempts_exhausted_at' => now()->subMinutes(5)->toJSON(),
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC8')
            ->assertOk()
            ->assertSee('data-state="exhausted"', false)
            ->assertSee('Fresh snapshot retry limit reached')
            ->assertSee('Retry limit reached')
            ->assertSee('The visitor widget stopped retrying this request ID after repeated failures.')
            ->assertDontSee('Fresh snapshot expired');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent can see expired cobrowse resync guidance', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RESYNC6',
            'subject' => 'Cobrowse went quiet',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(8),
            'ended_at' => null,
            'metadata' => [
                'resync_request' => [
                    'id' => 'resync_expired',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subMinutes(6)->toJSON(),
                    'fulfilled_at' => null,
                    'ignored_responses' => [
                        [
                            'request_id' => 'resync_expired',
                            'reason' => 'expired',
                            'ignored_at' => now()->subSeconds(20)->toJSON(),
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RESYNC6')
            ->assertOk()
            ->assertSee('data-state="expired"', false)
            ->assertSee('Fresh snapshot expired')
            ->assertSee('The visitor widget did not answer in time. Request another clean snapshot or continue through chat.')
            ->assertSee('Expired 1 minute ago')
            ->assertSee('Recovery timeline')
            ->assertSee('Snapshot requested')
            ->assertSee('Snapshot response ignored')
            ->assertSee('A widget response arrived after the recovery window closed.')
            ->assertSee('Request expired')
            ->assertSee('Request another fresh snapshot');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent cannot request a fresh cobrowse snapshot before consent is granted', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-NORESYNC',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'requested_by_id' => $agent->id,
        'status' => 'requested',
        'consented_at' => null,
        'ended_at' => null,
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-NORESYNC')
        ->post('/dashboard/conversations/WF-NORESYNC/cobrowse/resync')
        ->assertRedirect('/dashboard/conversations/WF-NORESYNC')
        ->assertSessionHas('status', 'Cobrowse must be active before requesting a fresh snapshot.');

    expect($session->fresh()->metadata ?? [])->not->toHaveKey('resync_request');
});

test('agent cannot request cobrowse for another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-REQUEST3',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-REQUEST3/cobrowse/request')
        ->assertNotFound();

    $this->assertDatabaseCount('cobrowse_sessions', 0);
});

test('agent can see cobrowse telemetry on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TELEMETRY',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'telemetry' => [
                'rtt_ms' => 184,
                'max_rtt_ms' => 240,
                'payload_bytes' => 8192,
                'max_payload_bytes' => 16384,
                'dropped_batches' => 2,
                'reconnects' => 1,
                'samples' => 5,
                'reported_at' => now()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TELEMETRY')
        ->assertOk()
        ->assertSee('Connection telemetry')
        ->assertSee('RTT')
        ->assertSee('184 ms')
        ->assertSee('Max RTT')
        ->assertSee('240 ms')
        ->assertSee('Payload')
        ->assertSee('8,192 bytes')
        ->assertSee('Max payload')
        ->assertSee('16,384 bytes')
        ->assertSee('Dropped batches')
        ->assertSee('2')
        ->assertSee('Reconnects')
        ->assertSee('1')
        ->assertSee('Samples')
        ->assertSee('5');
});

test('agent can see cobrowse transport health on a conversation', function (array $metadata, string $label, string $message, string $lastReport, string $pressure, string $guidance, string $recoveryAction): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TRANSPORT',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => $metadata,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-TRANSPORT')
        ->assertOk()
        ->assertSee('Transport health')
        ->assertSee($label)
        ->assertSee($message)
        ->assertSee('Last report')
        ->assertSee($lastReport)
        ->assertSee('Pressure')
        ->assertSee($pressure)
        ->assertSee('Agent guidance')
        ->assertSee($guidance)
        ->assertSee('Recovery action')
        ->assertSee($recoveryAction);

    Carbon::setTestNow();
})->with([
    'live' => [
        [
            'telemetry' => [
                'rtt_ms' => 84,
                'payload_bytes' => 2048,
                'dropped_batches' => 0,
                'reconnects' => 0,
                'samples' => 3,
                'reported_at' => '2026-06-17T11:59:30.000000Z',
            ],
        ],
        'Live',
        'Cobrowse reports are arriving normally.',
        '30 seconds ago',
        'No recent drops reported',
        'Preview is current enough to use alongside chat.',
        'No recovery action needed.',
    ],
    'degraded' => [
        [
            'telemetry' => [
                'rtt_ms' => 120,
                'payload_bytes' => 8192,
                'dropped_batches' => 1,
                'reconnects' => 0,
                'samples' => 4,
                'reported_at' => '2026-06-17T11:59:35.000000Z',
            ],
            'mutations' => [
                'skipped_count' => 3,
                'last_reported_at' => '2026-06-17T11:59:40.000000Z',
                'recent_batches' => [
                    [
                        'sequence' => 8,
                        'mutation_count' => 5,
                        'dropped_count' => 0,
                        'skipped_count' => 3,
                        'page_url' => 'https://docs.example.test/noisy',
                        'reported_at' => '2026-06-17T11:59:40.000000Z',
                        'mutations' => [],
                    ],
                ],
            ],
        ],
        'Degraded',
        'Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.',
        '20 seconds ago',
        '1 dropped batch, 3 skipped mutations',
        'Use the preview for orientation and confirm fast-changing details through chat.',
        'Request a fresh snapshot once the visitor widget settles, and use chat for fast-changing details.',
    ],
    'stale' => [
        [
            'page_state' => [
                'title' => 'Install Guide',
                'page_url' => 'https://docs.example.test/install',
                'reported_at' => '2026-06-17T11:55:00.000000Z',
            ],
        ],
        'Stale',
        'No cobrowse report has arrived in the last 2 minutes.',
        '5 minutes ago',
        'No recent drops reported',
        'Ask the visitor to confirm what they see before relying on the preview.',
        'Request a fresh snapshot if the preview looks out of date, and confirm details through chat.',
    ],
    'reconnecting' => [
        [
            'telemetry' => [
                'rtt_ms' => 184,
                'payload_bytes' => 4096,
                'dropped_batches' => 2,
                'reconnects' => 3,
                'samples' => 6,
                'reported_at' => '2026-06-17T11:59:45.000000Z',
            ],
            'mutations' => [
                'skipped_count' => 1,
                'last_reported_at' => '2026-06-17T11:59:40.000000Z',
                'recent_batches' => [
                    [
                        'sequence' => 12,
                        'mutation_count' => 3,
                        'dropped_count' => 0,
                        'skipped_count' => 1,
                        'page_url' => 'https://docs.example.test/reconnecting',
                        'reported_at' => '2026-06-17T11:59:40.000000Z',
                        'mutations' => [],
                    ],
                ],
            ],
        ],
        'Reconnecting',
        'The visitor transport has reconnected recently; preview data may briefly lag.',
        '15 seconds ago',
        '2 dropped batches, 1 skipped mutation',
        'Use chat to confirm anything that depends on fast-changing page state.',
        'Give the visitor widget a moment, then request a fresh snapshot if the preview still lags.',
    ],
    'unavailable' => [
        [],
        'Unavailable',
        'No cobrowse transport reports have arrived yet.',
        'Not reported',
        'No drops reported',
        'Wait for the visitor page to report before relying on cobrowse.',
        'Wait for the visitor page to report before requesting recovery.',
    ],
]);

test('agent cobrowse transport recovery action does not invite duplicate fresh snapshots', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-18 15:00:00', 'UTC'));

    try {
        $account = Account::factory()->create(['name' => 'Acme Support']);
        $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
        $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
        $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => 'WF-RECOVERY-PENDING',
            'subject' => 'Cobrowse recovery already requested',
            'status' => 'open',
        ]);

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinutes(3),
            'ended_at' => null,
            'metadata' => [
                'telemetry' => [
                    'rtt_ms' => 120,
                    'payload_bytes' => 8192,
                    'dropped_batches' => 2,
                    'reconnects' => 0,
                    'samples' => 4,
                    'reported_at' => now()->subSeconds(20)->toJSON(),
                ],
                'resync_request' => [
                    'id' => 'resync_pending',
                    'requested_by_id' => $agent->id,
                    'requested_by_name' => 'Ada Agent',
                    'requested_at' => now()->subSeconds(15)->toJSON(),
                    'fulfilled_at' => null,
                ],
            ],
        ]);

        $this->actingAs($agent)
            ->get('/dashboard/conversations/WF-RECOVERY-PENDING')
            ->assertOk()
            ->assertSee('Transport health')
            ->assertSee('Recovery action')
            ->assertSee('Fresh snapshot already requested. Wait for the visitor widget before retrying.')
            ->assertDontSee('Request a fresh snapshot once the visitor widget settles, and use chat for fast-changing details.');
    } finally {
        Carbon::setTestNow();
    }
});

test('agent cobrowse transport health does not keep stale reconnect warnings alive after newer reports arrive', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RECOVERED',
        'subject' => 'Recovered cobrowse stream',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinutes(5),
        'ended_at' => null,
        'metadata' => [
            'telemetry' => [
                'rtt_ms' => 184,
                'payload_bytes' => 4096,
                'dropped_batches' => 0,
                'reconnects' => 2,
                'samples' => 6,
                'reported_at' => '2026-06-17T11:56:00.000000Z',
            ],
            'page_state' => [
                'title' => 'Recovered Guide',
                'page_url' => 'https://docs.example.test/recovered',
                'reported_at' => '2026-06-17T11:59:45.000000Z',
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-RECOVERED')
        ->assertOk()
        ->assertSee('Transport health')
        ->assertSee('Live')
        ->assertSee('Cobrowse reports are arriving normally.')
        ->assertDontSee('Reconnecting')
        ->assertDontSee('The visitor transport has reconnected recently; preview data may briefly lag.');

    Carbon::setTestNow();
});

test('agent cobrowse transport health recovers after a clean mutation report', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-RECOVERED-LOSS',
        'subject' => 'Recovered cobrowse pressure',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinutes(5),
        'ended_at' => null,
        'metadata' => [
            'mutations' => [
                'last_sequence' => 12,
                'batch_count' => 2,
                'mutation_count' => 8,
                'dropped_count' => 0,
                'skipped_count' => 3,
                'last_page_url' => 'https://docs.example.test/recovered',
                'last_reported_at' => '2026-06-17T11:59:45.000000Z',
                'recent_batches' => [
                    [
                        'sequence' => 11,
                        'mutation_count' => 4,
                        'dropped_count' => 0,
                        'skipped_count' => 3,
                        'page_url' => 'https://docs.example.test/recovered',
                        'reported_at' => '2026-06-17T11:58:00.000000Z',
                        'mutations' => [],
                    ],
                    [
                        'sequence' => 12,
                        'mutation_count' => 4,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/recovered',
                        'reported_at' => '2026-06-17T11:59:45.000000Z',
                        'mutations' => [],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-RECOVERED-LOSS')
        ->assertOk()
        ->assertSee('Transport health')
        ->assertSee('Live')
        ->assertSee('Cobrowse reports are arriving normally.')
        ->assertSee('No recent drops reported')
        ->assertSee('3 skipped')
        ->assertDontSee('Degraded')
        ->assertDontSee('Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.');

    Carbon::setTestNow();
});

test('agent can see cobrowse payload budget guardrails on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BUDGET',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'payload_budget' => [
                'snapshot_html_max_characters' => 65535,
                'snapshot_text_max_characters' => 10000,
                'mutation_batch_max_items' => 50,
                'mutation_text_max_characters' => 5000,
                'mutation_html_max_characters' => 10000,
                'mutation_recent_batches_retained' => 20,
                'telemetry_payload_max_bytes' => 10485760,
                'widget_mutation_batch_max_bytes' => 60000,
                'widget_mutation_queue_max_records' => 250,
                'widget_mutation_flush_ms' => 50,
                'widget_pressure_resync_ms' => 30000,
                'widget_status_poll_ms' => 5000,
                'widget_resync_max_attempts' => 3,
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-BUDGET')
        ->assertOk()
        ->assertSee('Payload budget')
        ->assertSee('Snapshot HTML')
        ->assertSee('65,535 characters')
        ->assertSee('Snapshot text')
        ->assertSee('10,000 characters')
        ->assertSee('Mutation batch')
        ->assertSee('50 items')
        ->assertSee('Mutation text')
        ->assertSee('5,000 characters')
        ->assertSee('Mutation HTML')
        ->assertSee('10,000 characters')
        ->assertSee('Recent batches')
        ->assertSee('20 retained')
        ->assertSee('Telemetry payload')
        ->assertSee('10,485,760 bytes')
        ->assertSee('Stock widget batch payload')
        ->assertSee('60,000 bytes')
        ->assertSee('Stock widget queue')
        ->assertSee('250 pending')
        ->assertSee('Mutation flush')
        ->assertSee('50 ms')
        ->assertSee('Pressure resync')
        ->assertSee('30,000 ms')
        ->assertSee('Status poll')
        ->assertSee('5,000 ms')
        ->assertSee('Resync attempts')
        ->assertSee('3 attempts');
});

test('agent can see cobrowse payload budget guardrails before intake metadata exists', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BUDGET-FALLBACK',
        'subject' => 'Oversized first payload',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-BUDGET-FALLBACK')
        ->assertOk()
        ->assertSee('Payload budget')
        ->assertSee('Snapshot HTML')
        ->assertSee('65,535 characters')
        ->assertSee('Mutation batch')
        ->assertSee('50 items')
        ->assertSee('Telemetry payload')
        ->assertSee('10,485,760 bytes')
        ->assertSee('Stock widget batch payload')
        ->assertSee('60,000 bytes')
        ->assertSee('Stock widget queue')
        ->assertSee('250 pending')
        ->assertSee('Resync attempts')
        ->assertSee('3 attempts');
});

test('agent can see cobrowse page state on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PAGESTATE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'page_state' => [
                'page_url' => 'https://docs.example.test/install?step=2',
                'title' => 'Install Guide',
                'viewport_width' => 1366,
                'viewport_height' => 768,
                'scroll_x' => 0,
                'scroll_y' => 420,
                'visibility_state' => 'visible',
                'focused' => true,
                'reported_at' => now()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-PAGESTATE')
        ->assertOk()
        ->assertSee('Visitor page')
        ->assertSee('Install Guide')
        ->assertSee('https://docs.example.test/install?step=2')
        ->assertSee('1,366 x 768')
        ->assertSee('0, 420')
        ->assertSee('visible')
        ->assertSee('Focused');
});

test('agent can see a safe cobrowse snapshot preview on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SNAPSHOT',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install?step=2',
                'title' => 'Install Guide',
                'html' => '<main><input value="super-secret-token"><p>Public checkout content.</p></main>',
                'text' => 'Public checkout content. [masked]',
                'node_count' => 8,
                'masked_count' => 2,
                'reported_at' => now()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPSHOT')
        ->assertOk()
        ->assertSee('Page snapshot')
        ->assertSee('Install Guide')
        ->assertSee('https://docs.example.test/install?step=2')
        ->assertSee('8 nodes')
        ->assertSee('2 masked')
        ->assertSee('Public checkout content. [masked]')
        ->assertDontSee('super-secret-token');
});

test('agent can see cobrowse snapshot freshness guidance on a conversation', function (): void {
    $this->travelTo(Carbon::parse('2026-06-21 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $makeConversation = function (string $supportCode, mixed $reportedAt) use ($site, $visitor): Conversation {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => $supportCode,
            'subject' => 'Checkout trouble',
            'status' => 'open',
        ]);

        $snapshot = [
            'page_url' => 'https://docs.example.test/install?step=2',
            'title' => 'Install Guide',
            'text' => 'Public checkout content. [masked]',
            'node_count' => 8,
            'masked_count' => 2,
        ];

        if ($reportedAt !== null) {
            $snapshot['reported_at'] = $reportedAt;
        }

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => [
                'snapshot' => $snapshot,
            ],
        ]);

        return $conversation;
    };

    $makeConversation('WF-SNAPFRESH', now()->subSeconds(45)->toJSON());
    $makeConversation('WF-SNAPAGING', now()->subMinutes(3)->toJSON());
    $makeConversation('WF-SNAPSTALE', now()->subMinutes(8)->toJSON());
    $makeConversation('WF-SNAPUNKNOWN', 'not-a-real-date');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPFRESH')
        ->assertOk()
        ->assertSee('Snapshot freshness')
        ->assertSee('Fresh')
        ->assertSee('Reported 45 seconds ago')
        ->assertSee('Snapshot was reported recently.');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPAGING')
        ->assertOk()
        ->assertSee('Aging')
        ->assertSee('Reported 3 minutes ago')
        ->assertSee('Snapshot is a few minutes old. Request a fresh snapshot if this page is changing.');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPSTALE')
        ->assertOk()
        ->assertSee('Stale')
        ->assertSee('Reported 8 minutes ago')
        ->assertSee('Snapshot is older than 5 minutes. Confirm through chat or request a fresh snapshot.');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPUNKNOWN')
        ->assertOk()
        ->assertSee('Time unknown')
        ->assertSee('Report time unavailable')
        ->assertSee('Use chat to confirm what the visitor sees before relying on this preview.');
});

test('agent sees stale snapshot recovery guidance near cobrowse actions', function (): void {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb.key', 'reverb-key');
    config()->set('broadcasting.connections.reverb.secret', 'reverb-secret');
    config()->set('broadcasting.connections.reverb.app_id', 'reverb-app');
    config()->set('broadcasting.connections.reverb.options.host', 'wayfindr.test');
    config()->set('broadcasting.connections.reverb.options.port', 443);
    config()->set('broadcasting.connections.reverb.options.scheme', 'https');

    $this->travelTo(Carbon::parse('2026-06-21 12:00:00'));

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    $makeConversation = function (string $supportCode, mixed $reportedAt, ?array $resyncRequest = null) use ($site, $visitor): Conversation {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => $supportCode,
            'subject' => 'Checkout trouble',
            'status' => 'open',
        ]);

        $snapshot = [
            'page_url' => 'https://docs.example.test/install?step=2',
            'title' => 'Install Guide',
            'text' => 'Public checkout content. [masked]',
            'node_count' => 8,
            'masked_count' => 2,
            'reported_at' => $reportedAt,
        ];

        $metadata = ['snapshot' => $snapshot];

        if ($resyncRequest !== null) {
            $metadata['resync_request'] = $resyncRequest;
        }

        CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
            'metadata' => $metadata,
        ]);

        return $conversation;
    };

    $makeConversation('WF-SNAPRECENT', now()->subSeconds(45)->toJSON());
    $makeConversation('WF-SNAPOLD', now()->subMinutes(8)->toJSON());
    $makeConversation('WF-SNAPMISSING', 'not-a-real-date');
    $makeConversation('WF-SNAPPENDING', now()->subMinutes(8)->toJSON(), [
        'id' => 'resync_pending',
        'requested_at' => now()->subSeconds(30)->toJSON(),
        'requested_by_name' => 'Ada Agent',
        'fulfilled_at' => null,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPRECENT')
        ->assertOk()
        ->assertDontSee('Snapshot refresh guidance');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPOLD')
        ->assertOk()
        ->assertSeeInOrder([
            'Cobrowse',
            'Snapshot refresh guidance',
            'Snapshot may need refresh',
            'Request a fresh snapshot before relying on this preview, or confirm the page through chat.',
            'Request fresh snapshot',
        ])
        ->assertSee('data-cobrowse-snapshot-recovery', false);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPMISSING')
        ->assertOk()
        ->assertSee('Snapshot time needs confirmation')
        ->assertSee('Ask the visitor what they see or request a fresh snapshot before relying on this preview.');

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-SNAPPENDING')
        ->assertOk()
        ->assertSee('Snapshot refresh already requested')
        ->assertSee('A fresh snapshot request is already waiting on the visitor widget. Use chat while it catches up.')
        ->assertSee('Fresh snapshot already requested')
        ->assertSee('function updateSnapshotRecovery', false);
});

test('agent can see cobrowse mutation stream diagnostics on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MUTATE',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'mutations' => [
                'batch_count' => 3,
                'mutation_count' => 7,
                'dropped_count' => 2,
                'skipped_count' => 1,
                'last_sequence' => 42,
                'last_page_url' => 'https://docs.example.test/install?step=2',
                'last_reported_at' => now()->toJSON(),
                'recent_batches' => [
                    [
                        'sequence' => 42,
                        'mutation_count' => 2,
                        'dropped_count' => 0,
                        'skipped_count' => 1,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->toJSON(),
                        'mutations' => [],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-MUTATE')
        ->assertOk()
        ->assertSee('Mutation stream')
        ->assertSee('3 batches')
        ->assertSee('7 mutations')
        ->assertSee('2 dropped')
        ->assertSee('1 skipped')
        ->assertSee('Sequence 42')
        ->assertSee('https://docs.example.test/install?step=2');
});

test('agent can see a sandboxed cobrowse replay preview on a conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLAY',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install?step=2',
                'title' => 'Install Guide',
                'html' => '<main><h1>Install Guide</h1><p>Original public copy.</p><button aria-expanded="false">Details</button><script>window.secret="steal-token"</script></main>',
                'text' => 'Install Guide. [masked]',
                'node_count' => 6,
                'masked_count' => 1,
                'reported_at' => now()->subMinute()->toJSON(),
            ],
            'mutations' => [
                'batch_count' => 2,
                'mutation_count' => 3,
                'dropped_count' => 0,
                'skipped_count' => 0,
                'last_sequence' => 2,
                'last_page_url' => 'https://docs.example.test/install?step=2',
                'last_reported_at' => now()->toJSON(),
                'recent_batches' => [
                    [
                        'sequence' => 1,
                        'mutation_count' => 2,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->toJSON(),
                        'mutations' => [
                            [
                                'type' => 'text',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1) > p:nth-of-type(1)',
                                'text' => 'Updated public copy.',
                            ],
                            [
                                'type' => 'attribute',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1) > button:nth-of-type(1)',
                                'attribute_name' => 'aria-expanded',
                                'attribute_value' => 'true',
                            ],
                        ],
                    ],
                    [
                        'sequence' => 2,
                        'mutation_count' => 1,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->toJSON(),
                        'mutations' => [
                            [
                                'type' => 'added',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1)',
                                'html' => '<p>Fresh public hint.</p><script>window.secret="mutation-token"</script>',
                                'text' => 'Fresh public hint.',
                                'node_count' => 1,
                                'masked_count' => 0,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REPLAY')
        ->assertOk()
        ->assertSee('Replay preview')
        ->assertSee('sandbox', false)
        ->assertSee('srcdoc=', false)
        ->assertSee('Updated public copy.')
        ->assertSee('Fresh public hint.')
        ->assertSee('aria-expanded=&quot;true&quot;', false)
        ->assertSee('3 applied')
        ->assertSee('0 skipped')
        ->assertDontSee('steal-token')
        ->assertDontSee('mutation-token');
});

test('agent cobrowse replay preview skips mutations already covered by a recovery snapshot', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLAY-RECOVERY',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install?step=2',
                'title' => 'Install Guide',
                'html' => '<main><p>Recovered public copy.</p></main>',
                'text' => 'Recovered public copy.',
                'node_count' => 2,
                'masked_count' => 0,
                'mutation_sequence' => 2,
                'reported_at' => now()->toJSON(),
            ],
            'mutations' => [
                'batch_count' => 3,
                'mutation_count' => 3,
                'dropped_count' => 0,
                'skipped_count' => 0,
                'last_sequence' => 3,
                'last_page_url' => 'https://docs.example.test/install?step=2',
                'last_reported_at' => now()->toJSON(),
                'recent_batches' => [
                    [
                        'sequence' => 1,
                        'mutation_count' => 1,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->subSeconds(20)->toJSON(),
                        'mutations' => [
                            [
                                'type' => 'text',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1) > p:nth-of-type(1)',
                                'text' => 'Stale duplicate copy.',
                            ],
                        ],
                    ],
                    [
                        'sequence' => 2,
                        'mutation_count' => 1,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->subSeconds(10)->toJSON(),
                        'mutations' => [
                            [
                                'type' => 'added',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1)',
                                'html' => '<p>Duplicate covered hint.</p>',
                            ],
                        ],
                    ],
                    [
                        'sequence' => 3,
                        'mutation_count' => 1,
                        'dropped_count' => 0,
                        'skipped_count' => 0,
                        'page_url' => 'https://docs.example.test/install?step=2',
                        'reported_at' => now()->toJSON(),
                        'mutations' => [
                            [
                                'type' => 'added',
                                'path' => 'body:nth-of-type(1) > main:nth-of-type(1)',
                                'html' => '<p>Fresh later hint.</p>',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REPLAY-RECOVERY')
        ->assertOk()
        ->assertSee('Replay preview')
        ->assertSee('Recovered public copy.')
        ->assertSee('Fresh later hint.')
        ->assertSee('1 applied')
        ->assertDontSee('Stale duplicate copy.')
        ->assertDontSee('Duplicate covered hint.');
});

test('agent can reply to their account conversation', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY1',
        'subject' => 'Checkout trouble',
        'status' => 'open',
        'last_message_at' => now()->subMinutes(5),
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button is stuck.',
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-REPLY1/messages', [
            'body' => 'Thanks, I can help.',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLY1');

    $this->assertDatabaseHas('conversation_messages', [
        'conversation_id' => $conversation->id,
        'sender_type' => User::class,
        'sender_id' => $agent->id,
        'type' => 'text',
        'body' => 'Thanks, I can help.',
    ]);

    expect($conversation->fresh()->last_message_at->greaterThan(now()->subMinutes(2)))->toBeTrue();

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-REPLY1')
        ->assertOk()
        ->assertSee('Ada Agent')
        ->assertSeeInOrder(['The checkout button is stuck.', 'Thanks, I can help.']);
});

test('agent reply requires a message body', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-REPLY2',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/conversations/WF-REPLY2')
        ->post('/dashboard/conversations/WF-REPLY2/messages', [
            'body' => '',
        ])
        ->assertRedirect('/dashboard/conversations/WF-REPLY2')
        ->assertSessionHasErrors('body');

    $this->assertDatabaseCount('conversation_messages', 0);
});

test('agent cannot reply to another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
    ]);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-OTHER1/messages', [
            'body' => 'Can you see this?',
        ])
        ->assertNotFound();

    $this->assertDatabaseMissing('conversation_messages', [
        'body' => 'Can you see this?',
    ]);
});

test('agent cannot view another account conversation', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();

    Conversation::factory()->for($otherSite)->for($otherVisitor)->create([
        'support_code' => 'WF-OTHER1',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-OTHER1')
        ->assertNotFound();
});
