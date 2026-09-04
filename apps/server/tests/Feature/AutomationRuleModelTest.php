<?php

use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AutomationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('automation rules are account scoped disabled by default and preserve their definition', function (): void {
    $account = Account::factory()->create();
    $rule = AutomationRule::factory()->for($account)->create([
        'name' => 'Urgent billing intake',
        'event' => AutomationRuleEvent::TicketCreated,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'billing'],
        ],
        'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'],
        ],
        'position' => 20,
    ]);

    expect($rule->account->is($account))->toBeTrue()
        ->and($rule->event)->toBe('ticket.created')
        ->and($rule->eventEnum())->toBe(AutomationRuleEvent::TicketCreated)
        ->and($rule->conditions)->toBe([
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'billing'],
        ])
        ->and($rule->actions)->toBe([
            ['type' => 'set_priority', 'value' => 'urgent'],
        ])
        ->and($rule->position)->toBe(20)
        ->and($rule->is_enabled)->toBeFalse();
});

test('enabled rules are selected by account event and stable evaluation order', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();

    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Second',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 20,
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'First tie breaker one',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 10,
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'First tie breaker two',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 10,
    ]);
    AutomationRule::factory()->for($account)->create([
        'name' => 'Disabled',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Wrong event',
        'event' => AutomationRuleEvent::ConversationCreated,
        'position' => 0,
    ]);
    AutomationRule::factory()->for($otherAccount)->enabled()->create([
        'name' => 'Wrong account',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
    ]);

    expect($account->automationRules()
        ->enabled()
        ->forEvent(AutomationRuleEvent::TicketCreated)
        ->inEvaluationOrder()
        ->pluck('name')
        ->all())->toBe([
            'First tie breaker one',
            'First tie breaker two',
            'Second',
        ]);
});

test('an unknown automation event fails at the model boundary', function (): void {
    expect(fn () => (new AutomationRule)->forceFill(['event' => 'ticket.typo']))
        ->toThrow(ValueError::class);
});

test('conditions and actions reject unordered JSON objects', function (string $field): void {
    expect(fn () => (new AutomationRule)->forceFill([
        $field => ['type' => 'set_priority', 'value' => 'urgent'],
    ]))->toThrow(InvalidArgumentException::class);
})->with(['conditions', 'actions']);
