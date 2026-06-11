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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('alert digest preview prints safe metadata for digest candidates', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = digestPreviewAgent($account, [
        'email' => 'digest-agent@example.test',
        'name' => 'Digest Agent',
    ]);
    $assigningAgent = User::factory()->for($account)->create(['name' => 'Ada Manager']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    createDigestPreviewConversationAlert(
        agent: $agent,
        site: $site,
        body: 'My card number is 4111 1111 1111 1111 and I need help.',
        conversationOverrides: [
            'support_code' => 'WF-PREVIEW',
            'subject' => 'Checkout trouble',
        ],
    );

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($agent, 'assignee')
        ->create([
            'description' => 'Private billing details should stay out of digest previews.',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Billing follow-up',
        ]);

    $agent->notify(new TicketAssigned($ticket, $assigningAgent));

    $exitCode = Artisan::call('wayfindr:alert-digest-preview', [
        '--email' => $agent->email,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Alert digest preview')
        ->and($output)->toContain('Agent: Digest Agent <digest-agent@example.test>')
        ->and($output)->toContain('Candidates: 2')
        ->and($output)->toContain('- conversation_needs_reply | WF-PREVIEW | Acme Docs | Checkout trouble | open | n/a | /dashboard/conversations/WF-PREVIEW')
        ->and($output)->toContain("- ticket_assigned | Ticket #{$ticket->id} | Acme Docs | Billing follow-up | open | high | /dashboard/tickets/{$ticket->id}")
        ->and($output)->toContain('Digest preview complete. Agents scanned: 1. Candidates: 2.')
        ->not->toContain('4111 1111 1111 1111')
        ->not->toContain('Private billing details')
        ->not->toContain('visitor_anonymous_id')
        ->not->toContain('message_preview');
});

test('alert digest preview reports empty and missing-agent states without sending mail', function (): void {
    $account = Account::factory()->create();
    $agent = digestPreviewAgent($account, [
        'email' => 'empty-digest@example.test',
        'name' => 'Empty Digest',
    ]);

    $exitCode = Artisan::call('wayfindr:alert-digest-preview', [
        '--email' => $agent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No digest candidates found.')
        ->toContain('Digest preview complete. Agents scanned: 1. Candidates: 0.');

    $immediateAgent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_IMMEDIATE,
        ],
        'email' => 'immediate-agent@example.test',
    ]);

    $exitCode = Artisan::call('wayfindr:alert-digest-preview', [
        '--email' => $immediateAgent->email,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No digest candidates found.')
        ->toContain('Digest preview complete. Agents scanned: 0. Candidates: 0.');

    $exitCode = Artisan::call('wayfindr:alert-digest-preview', [
        '--email' => 'missing@example.test',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('No agent found for missing@example.test.');
});

function digestPreviewAgent(Account $account, array $overrides = []): User
{
    return User::factory()->for($account)->create(array_replace_recursive([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ], $overrides));
}

function createDigestPreviewConversationAlert(
    User $agent,
    Site $site,
    string $body = 'The checkout button is still stuck.',
    array $conversationOverrides = [],
): Conversation {
    $site->supportAgents()->syncWithoutDetaching($agent->id);

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
