<?php

use App\Enums\ConversationStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the support lifecycle exposes stable scalar contracts for rules and APIs', function (): void {
    expect(ConversationStatus::values())->toBe(['open', 'closed'])
        ->and(TicketStatus::values())->toBe(['open', 'pending', 'closed'])
        ->and(TicketPriority::values())->toBe(['low', 'normal', 'high', 'urgent'])
        ->and(array_keys(TicketPriority::guidanceOptions()))->toBe(['urgent', 'high', 'normal', 'low']);
});

test('conversation and ticket writes accept enums while preserving scalar storage', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create([
        'status' => ConversationStatus::Closed,
        'priority' => TicketPriority::High,
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'status' => TicketStatus::Pending,
        'priority' => TicketPriority::Urgent,
    ]);

    expect($conversation->status)->toBe('closed')
        ->and($conversation->statusEnum())->toBe(ConversationStatus::Closed)
        ->and($conversation->priority)->toBe('high')
        ->and($conversation->priorityEnum())->toBe(TicketPriority::High)
        ->and($ticket->status)->toBe('pending')
        ->and($ticket->statusEnum())->toBe(TicketStatus::Pending)
        ->and($ticket->priority)->toBe('urgent')
        ->and($ticket->priorityEnum())->toBe(TicketPriority::Urgent)
        ->and($conversation->getRawOriginal('status'))->toBe('closed')
        ->and($ticket->getRawOriginal('priority'))->toBe('urgent');
});

test('invalid lifecycle strings fail at the model write boundary', function (string $model, string $field): void {
    $instance = new $model;

    expect(fn () => $instance->forceFill([$field => 'typo-only-state']))
        ->toThrow(ValueError::class);
})->with([
    'conversation status' => [Conversation::class, 'status'],
    'conversation priority' => [Conversation::class, 'priority'],
    'ticket status' => [Ticket::class, 'status'],
    'ticket priority' => [Ticket::class, 'priority'],
]);
