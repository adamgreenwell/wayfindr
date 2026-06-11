<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use App\Support\AlertDigestCandidateCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('digest candidates summarize visible unread support alerts without raw visitor content', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = digestAgent($account);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Manager']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    createConversationAlert(
        agent: $agent,
        site: $site,
        body: 'My card number is 4111 1111 1111 1111 and I need help.',
        conversationOverrides: [
            'support_code' => 'WF-DIGEST1',
            'subject' => 'Checkout trouble',
        ],
    );

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'description' => 'Private billing details should stay out of digest candidates.',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Billing follow-up',
        ]);

    $agent->notify(new TicketAssigned($ticket, $assigningAgent));

    $candidates = app(AlertDigestCandidateCollector::class)->forAgent($agent);

    expect($candidates)->toHaveCount(2);

    $conversationCandidate = $candidates->firstWhere('kind', 'conversation_needs_reply');
    $ticketCandidate = $candidates->firstWhere('kind', 'ticket_assigned');

    expect($conversationCandidate)->toMatchArray([
        'kind' => 'conversation_needs_reply',
        'reference' => 'WF-DIGEST1',
        'subject' => 'Checkout trouble',
        'site_name' => 'Acme Docs',
        'status' => 'open',
        'url' => '/dashboard/conversations/WF-DIGEST1',
    ])
        ->and($ticketCandidate)->toMatchArray([
            'kind' => 'ticket_assigned',
            'reference' => 'Ticket #'.$ticket->id,
            'subject' => 'Billing follow-up',
            'site_name' => 'Acme Docs',
            'priority' => 'high',
            'status' => 'open',
            'url' => "/dashboard/tickets/{$ticket->id}",
        ]);

    $serializedCandidates = json_encode($candidates->all(), JSON_THROW_ON_ERROR);

    expect($serializedCandidates)
        ->not->toContain('4111 1111 1111 1111')
        ->not->toContain('Private billing details')
        ->not->toContain('visitor_anonymous_id')
        ->not->toContain('message_preview');
});

test('digest candidates require digest cadence and enabled email alerts', function (array $preferences): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => $preferences,
    ]);
    $site = Site::factory()->for($account)->create();

    createConversationAlert($agent, $site);

    $candidates = app(AlertDigestCandidateCollector::class)->forAgent($agent);

    expect($candidates)->toBeEmpty();
})->with([
    'immediate email alerts' => [[
        'mode' => User::ALERT_MODE_ALL,
        'email' => true,
        'cadence' => User::ALERT_CADENCE_IMMEDIATE,
    ]],
    'digest without email alerts' => [[
        'mode' => User::ALERT_MODE_ALL,
        'email' => false,
        'cadence' => User::ALERT_CADENCE_DIGEST,
    ]],
    'quiet digest alerts' => [[
        'mode' => User::ALERT_MODE_QUIET,
        'email' => true,
        'cadence' => User::ALERT_CADENCE_DIGEST,
    ]],
]);

test('digest candidates skip deactivated agents', function (): void {
    $account = Account::factory()->create();
    $agent = digestAgent($account, ['deactivated_at' => now()]);
    $site = Site::factory()->for($account)->create();

    createConversationAlert($agent, $site);

    expect(app(AlertDigestCandidateCollector::class)->forAgent($agent))->toBeEmpty();
});

test('digest candidates recheck site access and current assigned-only preferences', function (): void {
    $account = Account::factory()->create();
    $agent = digestAgent($account);
    $remainingAgent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    createConversationAlert($agent, $site, conversationOverrides: [
        'support_code' => 'WF-STALE',
        'subject' => 'Stale site access',
    ]);

    $site->supportAgents()->sync([$remainingAgent->id]);

    expect(app(AlertDigestCandidateCollector::class)->forAgent($agent))->toBeEmpty();

    $assignedOnlyAgent = digestAgent($account, [
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ASSIGNED,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ]);

    createConversationAlert($assignedOnlyAgent, $site, conversationOverrides: [
        'assigned_agent_id' => null,
        'support_code' => 'WF-UNASSIGNED',
        'subject' => 'Unassigned conversation',
    ]);

    $site->supportAgents()->sync([$remainingAgent->id, $assignedOnlyAgent->id]);

    expect(app(AlertDigestCandidateCollector::class)->forAgent($assignedOnlyAgent))->toBeEmpty();

    createConversationAlert($assignedOnlyAgent, $site, conversationOverrides: [
        'assigned_agent_id' => $assignedOnlyAgent->id,
        'support_code' => 'WF-ASSIGNED',
        'subject' => 'Assigned conversation',
    ]);

    $candidates = app(AlertDigestCandidateCollector::class)->forAgent($assignedOnlyAgent);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first())->toMatchArray([
            'kind' => 'conversation_needs_reply',
            'reference' => 'WF-ASSIGNED',
            'subject' => 'Assigned conversation',
        ]);
});

test('digest candidates skip conversations that no longer need an agent reply', function (): void {
    $account = Account::factory()->create();
    $agent = digestAgent($account);
    $site = Site::factory()->for($account)->create();

    $repliedConversation = createConversationAlert($agent, $site, conversationOverrides: [
        'support_code' => 'WF-REPLIED',
        'subject' => 'Already handled',
    ]);
    $agentMessage = ConversationMessage::factory()->for($repliedConversation)->create([
        'body' => 'I handled this one.',
        'created_at' => now()->addMinute(),
        'sender_id' => $agent->id,
        'sender_type' => User::class,
    ]);
    $repliedConversation->forceFill([
        'last_message_at' => $agentMessage->created_at,
    ])->save();

    $closedConversation = createConversationAlert($agent, $site, conversationOverrides: [
        'support_code' => 'WF-CLOSED',
        'subject' => 'Closed before digest',
    ]);
    $closedConversation->forceFill([
        'closed_at' => now(),
        'status' => 'closed',
    ])->save();

    expect(app(AlertDigestCandidateCollector::class)->forAgent($agent))->toBeEmpty();
});

function digestAgent(Account $account, array $overrides = []): User
{
    return User::factory()->for($account)->create(array_replace_recursive([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ], $overrides));
}

function createConversationAlert(
    User $agent,
    Site $site,
    string $body = 'The checkout button is still stuck.',
    array $conversationOverrides = [],
): Conversation {
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create(array_replace([
            'support_code' => fake()->unique()->bothify('WF-######'),
            'subject' => 'Support request',
        ], $conversationOverrides));

    $message = ConversationMessage::factory()->for($conversation)->create([
        'body' => $body,
        'sender_id' => $visitor->id,
        'sender_type' => Visitor::class,
    ]);

    $conversation->forceFill(['last_message_at' => $message->created_at])->save();
    $agent->notify(new ConversationNeedsReply($message));

    return $conversation;
}
