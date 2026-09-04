<?php

use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\Automation\AutomationRuleEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('enabled matching rules produce deterministic ordered action plans within the account and event', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Urgent BILLING question',
        'priority' => 'high',
        'assignee_id' => null,
    ]);

    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Second match',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 20,
        'conditions' => [
            ['field' => 'priority', 'operator' => 'equals', 'value' => 'high'],
        ],
        'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'post_internal_note', 'value' => 'Escalated by automation.'],
        ],
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'First match',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 10,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'billing'],
            ['field' => 'assignee_id', 'operator' => 'equals', 'value' => null],
        ],
        'actions' => [
            ['type' => 'set_status', 'value' => 'pending'],
        ],
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Does not match',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'not_contains', 'value' => 'billing'],
        ],
        'actions' => [['type' => 'set_priority', 'value' => 'low']],
    ]);
    AutomationRule::factory()->for($account)->create([
        'name' => 'Disabled match',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
        'actions' => [['type' => 'set_priority', 'value' => 'low']],
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Wrong event',
        'event' => AutomationRuleEvent::TicketUpdated,
        'position' => 0,
        'actions' => [['type' => 'set_priority', 'value' => 'low']],
    ]);
    AutomationRule::factory()->for($otherAccount)->enabled()->create([
        'name' => 'Wrong account',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
        'actions' => [['type' => 'set_priority', 'value' => 'low']],
    ]);

    $plans = app(AutomationRuleEvaluator::class)->plan(AutomationRuleEvent::TicketCreated, $ticket);

    expect(array_column($plans, 'rule_name'))->toBe(['First match', 'Second match'])
        ->and($plans[0]['actions'])->toBe([
            ['type' => 'set_status', 'value' => 'pending'],
        ])
        ->and($plans[1]['actions'])->toBe([
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'post_internal_note', 'value' => 'Escalated by automation.'],
        ]);
});

test('a disabled draft can be previewed without mutating its subject', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Password reset help',
        'priority' => 'normal',
    ]);
    $rule = AutomationRule::factory()->for($account)->create([
        'name' => 'Preview me',
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'password'],
            ['field' => 'priority', 'operator' => 'not_equals', 'value' => 'urgent'],
        ],
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    $preview = app(AutomationRuleEvaluator::class)->preview($rule, $ticket);

    expect($preview['matched'])->toBeTrue()
        ->and($preview['conditions'][0])->toBe([
            'field' => 'subject',
            'operator' => 'contains',
            'expected' => 'password',
            'actual' => 'Password reset help',
            'matched' => true,
        ])
        ->and($preview['conditions'][1])->toBe([
            'field' => 'priority',
            'operator' => 'not_equals',
            'expected' => 'urgent',
            'actual' => 'normal',
            'matched' => true,
        ])
        ->and($preview['actions'])->toBe([['type' => 'set_priority', 'value' => 'high']])
        ->and($ticket->fresh()->priority)->toBe('normal');
});

test('visitor message rules use only a visitor message from the same conversation', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Please REFUND the duplicate charge.',
    ]);
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'event' => AutomationRuleEvent::VisitorMessageCreated,
        'conditions' => [
            ['field' => 'message_body', 'operator' => 'contains', 'value' => 'refund'],
        ],
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    $preview = app(AutomationRuleEvaluator::class)->preview($rule, $conversation, $message);

    expect($preview['matched'])->toBeTrue()
        ->and($preview['conditions'][0]['actual'])->toBe('Please REFUND the duplicate charge.');

    $nonVisitorMessage = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => null,
        'sender_id' => null,
        'body' => 'refund',
    ]);

    expect(fn () => app(AutomationRuleEvaluator::class)->preview($rule, $conversation, $nonVisitorMessage))
        ->toThrow(InvalidArgumentException::class, 'requires a visitor message');
});

test('preview refuses to cross account or subject boundaries', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $otherRule = AutomationRule::factory()->for($otherAccount)->create([
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    expect(fn () => app(AutomationRuleEvaluator::class)->preview($otherRule, $ticket))
        ->toThrow(InvalidArgumentException::class, 'same account');

    $conversationRule = AutomationRule::factory()->for($account)->create([
        'event' => AutomationRuleEvent::ConversationCreated,
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    expect(fn () => app(AutomationRuleEvaluator::class)->preview($conversationRule, $ticket))
        ->toThrow(InvalidArgumentException::class, 'cannot be evaluated against this subject type');
});
