<?php

use App\Enums\AutomationMacroSubjectType;
use App\Models\Account;
use App\Models\AutomationMacro;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('automation macros are account scoped draft by default and preserve ordered actions', function (): void {
    $account = Account::factory()->create();
    $macro = AutomationMacro::factory()->for($account)->create([
        'name' => 'Prepare billing handoff',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'post_internal_note', 'value' => 'Billing handoff prepared.'],
        ],
        'position' => 20,
    ]);

    expect($macro->account->is($account))->toBeTrue()
        ->and($macro->subject_type)->toBe('ticket')
        ->and($macro->subjectTypeEnum())->toBe(AutomationMacroSubjectType::Ticket)
        ->and($macro->actions)->toBe([
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'post_internal_note', 'value' => 'Billing handoff prepared.'],
        ])
        ->and($macro->position)->toBe(20)
        ->and($macro->is_enabled)->toBeFalse();
});

test('enabled macros are selected by account work type and stable display order', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();

    AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Second',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'position' => 20,
    ]);
    AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'First one',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'position' => 10,
    ]);
    AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'First two',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'position' => 10,
    ]);
    AutomationMacro::factory()->for($account)->create([
        'name' => 'Draft',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'position' => 0,
    ]);
    AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Conversation',
        'subject_type' => AutomationMacroSubjectType::Conversation,
        'position' => 0,
    ]);
    AutomationMacro::factory()->for($otherAccount)->enabled()->create([
        'name' => 'Other account',
        'subject_type' => AutomationMacroSubjectType::Ticket,
        'position' => 0,
    ]);

    expect($account->automationMacros()
        ->enabled()
        ->forSubjectType(AutomationMacroSubjectType::Ticket)
        ->inDisplayOrder()
        ->pluck('name')
        ->all())->toBe(['First one', 'First two', 'Second']);
});

test('macro definitions reject empty and work-incompatible action sequences', function (array $attributes, string $message): void {
    $account = Account::factory()->create();

    expect(fn () => AutomationMacro::factory()->for($account)->create($attributes))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty sequence' => [
        ['actions' => []],
        'require at least one action',
    ],
    'ticket action on conversation' => [
        [
            'subject_type' => AutomationMacroSubjectType::Conversation,
            'actions' => [['type' => 'add_label', 'value' => 4]],
        ],
        'not available for conversation macros',
    ],
    'ticket status on conversation' => [
        [
            'subject_type' => AutomationMacroSubjectType::Conversation,
            'actions' => [['type' => 'set_status', 'value' => 'pending']],
        ],
        'status supported by conversation',
    ],
]);
