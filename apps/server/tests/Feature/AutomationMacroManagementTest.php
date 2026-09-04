<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function automationMacroPayload(array $overrides = []): array
{
    return [
        'name' => 'Prepare billing handoff',
        'subject_type' => 'ticket',
        'position' => 20,
        'is_enabled' => '0',
        'actions' => [[
            'type' => 'set_priority',
            'text_value' => '',
            'select_value' => 'priority:urgent',
        ]],
        ...$overrides,
    ];
}

test('automation managers can create edit and delete ordered draft macros', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Billing']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('No macros yet.')
        ->assertSee('Create the first macro');

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-macros.store'), automationMacroPayload([
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

    $macro = AutomationMacro::query()->sole();

    expect($macro->name)->toBe('Prepare billing handoff')
        ->and($macro->subject_type)->toBe('ticket')
        ->and($macro->position)->toBe(20)
        ->and($macro->is_enabled)->toBeFalse()
        ->and($macro->actions)->toBe([
            ['type' => 'add_label', 'value' => $label->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
        ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-macros.edit', $macro))
        ->assertOk()
        ->assertSee('Edit Prepare billing handoff')
        ->assertSee('same bounded action vocabulary as automation rules')
        ->assertSee('name="actions[0][type]"', false)
        ->assertSee('value="label:'.$label->id.'" selected', false)
        ->assertDontSee('name="conditions[', false);

    $this->actingAs($admin)
        ->put(route('dashboard.account.automation-macros.update', $macro), automationMacroPayload([
            'name' => 'Escalate billing ticket',
            'position' => 5,
            'is_enabled' => '1',
        ]))
        ->assertRedirect(route('dashboard.account.automation-macros.edit', $macro))
        ->assertSessionHas('status', 'automation_macros.flash.updated');

    expect($macro->fresh())
        ->name->toBe('Escalate billing ticket')
        ->position->toBe(5)
        ->is_enabled->toBeTrue();

    expect(AuditEvent::query()->where('action', 'automation_macro.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'automation_macro.updated')->count())->toBe(1);

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Escalate billing ticket']))
        ->assertOk()
        ->assertSee('Automation macro updated')
        ->assertSee('Escalate billing ticket');

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Escalate billing ticket')
        ->assertSee('Ticket');

    $this->actingAs($admin)
        ->delete(route('dashboard.account.automation-macros.destroy', $macro))
        ->assertRedirect(route('dashboard.account.automation-rules.index'))
        ->assertSessionHas('status', 'automation_macros.flash.deleted');

    expect(AutomationMacro::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'automation_macro.deleted')->count())->toBe(1);
});

test('macro management uses the delegable automation boundary and account scope', function (): void {
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
    $otherMacro = AutomationMacro::factory()->for($otherAccount)->create();

    $this->actingAs($ordinaryAgent)
        ->get(route('dashboard.account.automation-macros.create'))
        ->assertForbidden();

    $this->actingAs($automationManager)
        ->get(route('dashboard.account.automation-macros.create'))
        ->assertOk();

    $this->actingAs($automationManager)
        ->get(route('dashboard.account.automation-macros.edit', $otherMacro))
        ->assertNotFound();

    $this->actingAs($automationManager)
        ->put(route('dashboard.account.automation-macros.update', $otherMacro), automationMacroPayload())
        ->assertNotFound();
});

test('macro mutations reauthorize stale custom roles under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $automationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $automationRole->id,
    ]);
    $macro = AutomationMacro::factory()->for($account)->create(['name' => 'Original macro']);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageAutomations))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.account.automation-macros.store'), automationMacroPayload()),
        'update' => $this->put(route('dashboard.account.automation-macros.update', $macro), automationMacroPayload()),
        'delete' => $this->delete(route('dashboard.account.automation-macros.destroy', $macro)),
    };

    $action === 'create' ? $response->assertForbidden() : $response->assertNotFound();
    expect(AutomationMacro::query()->count())->toBe(1)
        ->and($macro->fresh()->name)->toBe('Original macro');
})->with(['create', 'update', 'delete']);

test('macro forms reject incompatible vocabulary and cross-account references', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $otherLabel = TicketLabel::factory()->for($otherAccount)->create();

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-macros.create'))
        ->post(route('dashboard.account.automation-macros.store'), automationMacroPayload([
            'subject_type' => 'conversation',
            'actions' => [[
                'type' => 'add_label',
                'text_value' => '',
                'select_value' => 'label:'.$otherLabel->id,
            ]],
        ]))
        ->assertRedirect(route('dashboard.account.automation-macros.create'))
        ->assertSessionHasErrors('definition');

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-macros.create'))
        ->post(route('dashboard.account.automation-macros.store'), automationMacroPayload([
            'actions' => [[
                'type' => 'add_label',
                'text_value' => '',
                'select_value' => 'label:'.$otherLabel->id,
            ]],
        ]))
        ->assertRedirect(route('dashboard.account.automation-macros.create'))
        ->assertSessionHasErrors('actions.0.select_value');

    expect(AutomationMacro::query()->count())->toBe(0);
});

test('enabled macro action targets must be valid for every account site', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $target = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $coveredSite = Site::factory()->for($account)->create();
    $uncoveredSite = Site::factory()->for($account)->create();
    $coveredSite->supportAgents()->sync([$target->id]);
    $uncoveredSite->supportAgents()->sync([$otherAgent->id]);

    $this->actingAs($admin)
        ->from(route('dashboard.account.automation-macros.create'))
        ->post(route('dashboard.account.automation-macros.store'), automationMacroPayload([
            'is_enabled' => '1',
            'actions' => [[
                'type' => 'assign_agent',
                'text_value' => '',
                'select_value' => 'agent:'.$target->id,
            ]],
        ]))
        ->assertRedirect(route('dashboard.account.automation-macros.create'))
        ->assertSessionHasErrors('actions.0.select_value');

    $this->actingAs($admin)
        ->post(route('dashboard.account.automation-macros.store'), automationMacroPayload([
            'name' => 'Draft specialist handoff',
            'actions' => [[
                'type' => 'assign_agent',
                'text_value' => '',
                'select_value' => 'agent:'.$target->id,
            ]],
        ]))
        ->assertRedirect();

    expect(AutomationMacro::query()->sole()->is_enabled)->toBeFalse();
});

test('macro display order defaults stay within the accepted range', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    AutomationMacro::factory()->for($account)->create(['position' => 10000]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-macros.create'))
        ->assertOk()
        ->assertSee('name="position" type="number" min="0" max="10000" step="1" value="10000"', false);
});

test('labels referenced by macros cannot be deleted', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $label = TicketLabel::factory()->for($account)->create();
    AutomationMacro::factory()->for($account)->create([
        'actions' => [['type' => 'add_label', 'value' => $label->id]],
    ]);

    $this->actingAs($admin)
        ->from(route('dashboard.account.labels.index'))
        ->delete(route('dashboard.account.labels.destroy', $label))
        ->assertRedirect(route('dashboard.account.labels.index'))
        ->assertSessionHasErrors('label');

    expect($label->fresh())->not->toBeNull();
});
