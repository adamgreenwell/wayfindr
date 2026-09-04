<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\AutomationExecutionStatus;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function automationRulePayload(array $overrides = []): array
{
    return [
        'name' => 'Escalate billing work',
        'event' => 'ticket.created',
        'position' => 20,
        'is_enabled' => '0',
        'conditions' => [[
            'field' => 'subject',
            'operator' => 'contains',
            'text_value' => 'invoice',
            'select_value' => '',
        ]],
        'actions' => [[
            'type' => 'set_priority',
            'text_value' => '',
            'select_value' => 'priority:urgent',
        ]],
        ...$overrides,
    ];
}

test('automation managers can create edit and delete ordered draft rules', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Billing']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Automation rules')
        ->assertSee('No automation rules yet.')
        ->assertSee('Create the first rule');

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-rules.store'), automationRulePayload([
            'actions' => [
                [
                    'type' => 'add_label',
                    'text_value' => '',
                    'select_value' => 'label:'.$label->id,
                ],
                [
                    'type' => 'set_priority',
                    'text_value' => '',
                    'select_value' => 'priority:urgent',
                ],
            ],
        ]))
        ->assertRedirect();

    $rule = AutomationRule::query()->sole();

    expect($rule->name)->toBe('Escalate billing work')
        ->and($rule->event)->toBe('ticket.created')
        ->and($rule->position)->toBe(20)
        ->and($rule->is_enabled)->toBeFalse()
        ->and($rule->conditions)->toBe([[
            'field' => 'subject',
            'operator' => 'contains',
            'value' => 'invoice',
        ]])
        ->and($rule->actions)->toBe([
            ['type' => 'add_label', 'value' => $label->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
        ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.edit', $rule))
        ->assertOk()
        ->assertSee('Edit Escalate billing work')
        ->assertSee('Dry-run preview')
        ->assertSee('Ticket created')
        ->assertDontSee('automation_rules.events')
        ->assertSee('No actions, alerts, notes, or lifecycle changes run.')
        ->assertSee('name="conditions[0][field]"', false)
        ->assertSee('value="label:'.$label->id.'" selected', false);

    $this->actingAs($admin)
        ->put(route('dashboard.account.automation-rules.update', $rule), automationRulePayload([
            'name' => 'Escalate billing tickets',
            'position' => 5,
            'is_enabled' => '1',
        ]))
        ->assertRedirect(route('dashboard.account.automation-rules.edit', $rule))
        ->assertSessionHas('status', 'automation_rules.flash.updated');

    expect($rule->fresh())
        ->name->toBe('Escalate billing tickets')
        ->position->toBe(5)
        ->is_enabled->toBeTrue();

    expect(AuditEvent::query()->where('action', 'automation_rule.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'automation_rule.updated')->count())->toBe(1);

    $this->actingAs($admin)
        ->delete(route('dashboard.account.automation-rules.destroy', $rule))
        ->assertRedirect(route('dashboard.account.automation-rules.index'))
        ->assertSessionHas('status', 'automation_rules.flash.deleted');

    expect(AutomationRule::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'automation_rule.deleted')->count())->toBe(1);
});

test('automation management has a dedicated delegable account boundary', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $ordinaryAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $automationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $automationManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $automationRole->id,
    ]);
    $otherRule = AutomationRule::factory()->for($otherAccount)->create();

    $this->actingAs($ordinaryAgent)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertForbidden();

    $this->actingAs($automationManager)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk();

    $this->actingAs($automationManager)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee('Automation rules');

    $this->actingAs($automationManager)
        ->get(route('dashboard.account.automation-rules.edit', $otherRule))
        ->assertNotFound();

    $this->actingAs($automationManager)
        ->put(route('dashboard.account.automation-rules.update', $otherRule), automationRulePayload())
        ->assertNotFound();
});

test('automation mutations reauthorize stale custom roles under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $automationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $automationRole->id,
    ]);
    $rule = AutomationRule::factory()->for($account)->create(['name' => 'Original rule']);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageAutomations))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.account.automation-rules.store'), automationRulePayload()),
        'update' => $this->put(route('dashboard.account.automation-rules.update', $rule), automationRulePayload()),
        'delete' => $this->delete(route('dashboard.account.automation-rules.destroy', $rule)),
    };

    $action === 'create' ? $response->assertForbidden() : $response->assertNotFound();
    expect(AutomationRule::query()->count())->toBe(1)
        ->and($rule->fresh()->name)->toBe('Original rule');
})->with(['create', 'update', 'delete']);

test('rule forms reject incompatible vocabulary and cross-account references', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $otherLabel = TicketLabel::factory()->for($otherAccount)->create();

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-rules.create'))
        ->post(route('dashboard.account.automation-rules.store'), automationRulePayload([
            'event' => 'conversation.created',
            'actions' => [[
                'type' => 'add_label',
                'text_value' => '',
                'select_value' => 'label:'.$otherLabel->id,
            ]],
        ]))
        ->assertRedirect(route('dashboard.account.automation-rules.create'))
        ->assertSessionHasErrors('definition');

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-rules.create'))
        ->post(route('dashboard.account.automation-rules.store'), automationRulePayload([
            'actions' => [[
                'type' => 'add_label',
                'text_value' => '',
                'select_value' => 'label:'.$otherLabel->id,
            ]],
        ]))
        ->assertRedirect(route('dashboard.account.automation-rules.create'))
        ->assertSessionHasErrors('actions.0.select_value');

    expect(AutomationRule::query()->count())->toBe(0);
});

test('enabled action targets must cover every site the rule can match', function (string $actionType): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $target = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $otherAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $coveredSite = Site::factory()->for($account)->create();
    $uncoveredSite = Site::factory()->for($account)->create();
    $coveredSite->supportAgents()->sync([$target->id]);
    $uncoveredSite->supportAgents()->sync([$otherAgent->id]);
    $actions = [[
        'type' => $actionType,
        'text_value' => '',
        'select_value' => 'agent:'.$target->id,
    ]];

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-rules.create'))
        ->post(route('dashboard.account.automation-rules.store'), automationRulePayload([
            'is_enabled' => '1',
            'actions' => $actions,
        ]))
        ->assertRedirect(route('dashboard.account.automation-rules.create'))
        ->assertSessionHasErrors('actions.0.select_value');

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-rules.store'), automationRulePayload([
            'name' => 'Route covered site work',
            'is_enabled' => '1',
            'conditions' => [[
                'field' => 'site_id',
                'operator' => 'equals',
                'text_value' => '',
                'select_value' => 'site:'.$coveredSite->id,
            ]],
            'actions' => $actions,
        ]))
        ->assertRedirect();

    expect(AutomationRule::query()->sole()->is_enabled)->toBeTrue();
})->with(['assign_agent', 'notify_agent']);

test('preview evaluates a saved draft without changing work or recording an execution', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Invoice export is blocked',
            'priority' => 'normal',
        ]);
    $rule = AutomationRule::factory()->for($account)->create([
        'name' => 'Urgent invoices',
        'event' => 'ticket.created',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'invoice']],
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
        'is_enabled' => false,
    ]);
    $ticketUpdatedAt = $ticket->updated_at;

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-rules.preview', $rule), [
            'preview_subject' => 'ticket:'.$ticket->id,
        ])
        ->assertRedirect(route('dashboard.account.automation-rules.edit', $rule))
        ->assertSessionHas('status', 'automation_rules.flash.previewed')
        ->assertSessionHas('automation_preview', fn (array $preview): bool => $preview['matched'] === true
            && $preview['actions'] === [['type' => 'set_priority', 'value' => 'urgent']]);

    expect($ticket->fresh()->priority)->toBe('normal')
        ->and($ticket->fresh()->updated_at->equalTo($ticketUpdatedAt))->toBeTrue()
        ->and(AutomationRuleExecution::query()->count())->toBe(0)
        ->and($ticket->auditEvents()->count())->toBe(0);

    $this->actingAs($admin)
        ->withSession(['automation_preview' => [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'event' => $rule->event,
            'matched' => true,
            'subject_label' => 'Ticket #'.$ticket->id,
            'conditions' => [[
                'field' => 'subject', 'operator' => 'contains', 'expected' => 'invoice',
                'actual' => 'Invoice export is blocked', 'matched' => true,
            ]],
            'actions' => $rule->actions,
        ]])
        ->get(route('dashboard.account.automation-rules.edit', $rule))
        ->assertOk()
        ->assertSee('Would match')
        ->assertSee('Subject Contains “invoice”')
        ->assertSee('Actual value: “Invoice export is blocked”')
        ->assertSee('Set priority: Urgent');
});

test('preview refuses support work outside the managers visible scope', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $otherAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $hiddenSite = Site::factory()->for($account)->create();
    $site->supportAgents()->sync([$admin->id]);
    $hiddenSite->supportAgents()->sync([$otherAgent->id]);
    $visitor = Visitor::factory()->for($hiddenSite)->create();
    $conversation = Conversation::factory()->for($hiddenSite)->for($visitor)->create();
    $ticket = Ticket::factory()->for($account)->for($hiddenSite)->for($conversation)->for($visitor, 'requester')->create();
    $rule = AutomationRule::factory()->for($account)->create([
        'event' => 'ticket.created',
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-rules.preview', $rule), [
            'preview_subject' => 'ticket:'.$ticket->id,
        ])
        ->assertForbidden();
});

test('execution history hides support subjects outside the managers visible scope', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->for($conversation)->for($visitor, 'requester')->create([
        'subject' => 'Confidential acquisition discussion',
    ]);
    $rule = AutomationRule::factory()->for($account)->create();
    AutomationRuleExecution::query()->create([
        'account_id' => $account->id,
        'automation_rule_id' => $rule->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'rule_name' => $rule->name,
        'event' => $rule->event,
        'status' => AutomationExecutionStatus::Succeeded,
        'conditions' => $rule->conditions,
        'actions' => $rule->actions,
        'action_results' => [],
        'metadata' => ['message_id' => null],
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Restricted support work')
        ->assertDontSee('Confidential acquisition discussion');
});

test('execution history remains readable after its rule is deleted', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->for($conversation)->for($visitor, 'requester')->create([
        'subject' => 'Export is blocked',
    ]);
    $rule = AutomationRule::factory()->for($account)->create([
        'name' => 'Urgent exports',
        'event' => 'ticket.created',
        'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'export']],
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);
    $execution = AutomationRuleExecution::query()->create([
        'account_id' => $account->id,
        'automation_rule_id' => $rule->id,
        'subject_type' => $ticket->getMorphClass(),
        'subject_id' => $ticket->id,
        'rule_name' => $rule->name,
        'event' => $rule->event,
        'status' => AutomationExecutionStatus::Succeeded,
        'conditions' => $rule->conditions,
        'actions' => $rule->actions,
        'action_results' => [['type' => 'set_priority', 'status' => 'applied', 'detail' => 'normal->urgent']],
        'metadata' => ['message_id' => null],
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Execution log')
        ->assertSee('Urgent exports')
        ->assertSee('Ticket #'.$ticket->id)
        ->assertSee('Subject Contains “export”')
        ->assertSee('Set priority — Applied (normal to urgent)');

    $this->actingAs($admin)
        ->delete(route('dashboard.account.automation-rules.destroy', $rule))
        ->assertRedirect();

    expect($execution->fresh()->automation_rule_id)->toBeNull();

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Urgent exports')
        ->assertSee('Set priority — Applied (normal to urgent)');
});

test('labels referenced by automation rules cannot be deleted out from under them', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $label = TicketLabel::factory()->for($account)->create();
    AutomationRule::factory()->for($account)->create([
        'event' => 'ticket.created',
        'actions' => [['type' => 'add_label', 'value' => $label->id]],
    ]);

    $this->actingAs($admin)
        ->from(route('dashboard.account.labels.index'))
        ->delete(route('dashboard.account.labels.destroy', $label))
        ->assertRedirect(route('dashboard.account.labels.index'))
        ->assertSessionHasErrors('label');

    expect($label->fresh())->not->toBeNull();
});
