<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('owners can create update and delete an unassigned custom role with audit history', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->assertSee('Create a role')
        ->assertSee('View conversations');

    $this->actingAs($owner)
        ->post(route('dashboard.account.roles.store'), [
            'name' => '  Support   lead  ',
            'permissions' => [
                AccountPermission::ViewConversations->value,
                AccountPermission::ReplyToConversations->value,
            ],
        ])
        ->assertRedirect();

    $role = CustomRole::query()->sole();

    expect($role->name)->toBe('Support lead')
        ->and($role->name_key)->toBe('support lead')
        ->and($role->permissionValues())->toBe([
            AccountPermission::ReplyToConversations->value,
            AccountPermission::ViewConversations->value,
        ]);

    $this->actingAs($owner)
        ->put(route('dashboard.account.roles.update', $role), [
            'name' => 'Auditor',
            'permissions' => [AccountPermission::ViewAudit->value],
        ])
        ->assertRedirect();

    expect($role->fresh()->name)->toBe('Auditor')
        ->and($role->fresh()->permissionValues())->toBe([AccountPermission::ViewAudit->value]);

    $this->actingAs($owner)
        ->delete(route('dashboard.account.roles.destroy', $role))
        ->assertRedirect(route('dashboard.account.roles.index'));

    expect(CustomRole::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('action', [
            'custom_role.created',
            'custom_role.updated',
            'custom_role.deleted',
        ])->count())->toBe(3);

    $this->actingAs($owner)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Auditor']))
        ->assertOk()
        ->assertSee('Custom role deleted')
        ->assertSee('Auditor');
});

test('only owners can manage custom roles', function (AccountRole $role): void {
    $account = Account::factory()->create();
    $actor = User::factory()->for($account)->create(['account_role' => $role]);

    $this->actingAs($actor)
        ->get(route('dashboard.account.roles.index'))
        ->assertForbidden();

    $this->actingAs($actor)
        ->post(route('dashboard.account.roles.store'), ['name' => 'Auditor'])
        ->assertForbidden();

    expect(CustomRole::query()->exists())->toBeFalse();
})->with([
    'admin' => [AccountRole::Admin],
    'agent' => [AccountRole::Agent],
]);

test('permission dependencies are enforced and role names are unique within an account', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    CustomRole::factory()->for($account)->create(['name' => 'Auditor', 'name_key' => 'auditor']);

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->post(route('dashboard.account.roles.store'), [
            'name' => 'Incomplete support',
            'permissions' => [AccountPermission::ReplyToConversations->value],
        ])
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('permissions');

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->post(route('dashboard.account.roles.store'), ['name' => ' AUDITOR '])
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('name');

    expect(CustomRole::query()->count())->toBe(1);
});

test('owners can assign custom roles and permission changes take effect immediately', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $role = CustomRole::factory()->for($account)->create([
        'name' => 'Conversation reader',
        'name_key' => 'conversation reader',
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);

    $this->actingAs($owner)
        ->put(route('dashboard.account.agents.role.update', $agent), [
            'account_role' => 'custom:'.$role->id,
        ])
        ->assertRedirect(route('dashboard.account.show'));

    $assigned = $agent->fresh();
    expect($assigned->account_role)->toBe(AccountRole::Agent)
        ->and($assigned->custom_role_id)->toBe($role->id)
        ->and($assigned->hasAccountPermission(AccountPermission::ViewConversations))->toBeTrue()
        ->and($assigned->hasAccountPermission(AccountPermission::ManageTickets))->toBeFalse();

    $this->actingAs($assigned)
        ->get(route('dashboard.conversations.index'))
        ->assertOk();
    $this->actingAs($assigned)
        ->get(route('dashboard.tickets.index'))
        ->assertForbidden();

    $role->forceFill(['permissions' => [AccountPermission::ManageTickets->value]])->save();
    $assigned->unsetRelation('customRole');

    expect($assigned->hasAccountPermission(AccountPermission::ViewConversations))->toBeFalse()
        ->and($assigned->hasAccountPermission(AccountPermission::ManageTickets))->toBeTrue();
    $this->actingAs($assigned)
        ->get(route('dashboard.conversations.index'))
        ->assertForbidden();
    $this->actingAs($assigned)
        ->get(route('dashboard.tickets.index'))
        ->assertOk();

    $event = AuditEvent::query()->where('action', 'agent.role_changed')->sole();
    expect($event->metadata)->toMatchArray([
        'old_role' => AccountRole::Agent->value,
        'new_role' => 'custom:'.$role->id,
        'new_role_name' => 'Conversation reader',
    ]);
});

test('a custom role cannot cross account boundaries or be deleted while assigned', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $outsideRole = CustomRole::factory()->for($otherAccount)->create();

    $this->actingAs($owner)
        ->from(route('dashboard.account.show'))
        ->put(route('dashboard.account.agents.role.update', $agent), [
            'account_role' => 'custom:'.$outsideRole->id,
        ])
        ->assertRedirect(route('dashboard.account.show'))
        ->assertSessionHasErrors('account_role');

    $assignedRole = CustomRole::factory()->for($account)->create();
    $agent->forceFill([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $assignedRole->id,
    ])->save();

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->delete(route('dashboard.account.roles.destroy', $assignedRole))
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('role');

    expect($assignedRole->fresh())->not->toBeNull();
});

test('site management permission cannot be removed from a sites only assigned manager', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'name' => 'Site manager',
        'name_key' => 'site manager',
        'permissions' => [AccountPermission::ManageSiteAccess->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Managed docs']);
    $site->supportAgents()->attach($manager);

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->put(route('dashboard.account.roles.update', $role), [
            'name' => 'Site reader',
            'permissions' => [],
        ])
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('permissions');

    expect($role->fresh()->name)->toBe('Site manager')
        ->and($role->fresh()->hasPermission(AccountPermission::ManageSiteAccess))->toBeTrue();

    $site->supportAgents()->attach($owner);

    $this->actingAs($owner)
        ->put(route('dashboard.account.roles.update', $role), [
            'name' => 'Site reader',
            'permissions' => [],
        ])
        ->assertRedirect();

    expect($role->fresh()->name)->toBe('Site reader')
        ->and($role->fresh()->hasPermission(AccountPermission::ManageSiteAccess))->toBeFalse();
});

test('a sites only assigned manager cannot be moved to a role without site management', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageSiteAccess->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Managed docs']);
    $site->supportAgents()->attach($manager);

    $this->actingAs($owner)
        ->from(route('dashboard.account.show'))
        ->put(route('dashboard.account.agents.role.update', $manager), [
            'account_role' => AccountRole::Agent->value,
        ])
        ->assertRedirect(route('dashboard.account.show'))
        ->assertSessionHasErrors('account_role');

    expect($manager->fresh()->custom_role_id)->toBe($role->id);

    $site->supportAgents()->attach($owner);

    $this->actingAs($owner)
        ->put(route('dashboard.account.agents.role.update', $manager), [
            'account_role' => AccountRole::Agent->value,
        ])
        ->assertRedirect(route('dashboard.account.show'));

    expect($manager->fresh()->custom_role_id)->toBeNull()
        ->and($manager->fresh()->account_role)->toBe(AccountRole::Agent);
});

test('a sites only assigned manager cannot be deactivated', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageSiteAccess->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create(['name' => 'Managed docs']);
    $site->supportAgents()->attach($manager);

    $this->actingAs($owner)
        ->from(route('dashboard.account.show'))
        ->post(route('dashboard.account.agents.deactivate', $manager))
        ->assertRedirect(route('dashboard.account.show'))
        ->assertSessionHasErrors('agent');

    expect($manager->fresh()->isDeactivated())->toBeFalse();

    $site->supportAgents()->attach($owner);

    $this->actingAs($owner)
        ->post(route('dashboard.account.agents.deactivate', $manager))
        ->assertRedirect(route('dashboard.account.show'));

    expect($manager->fresh()->isDeactivated())->toBeTrue();
});

test('custom roles cannot receive the non delegable role management permission', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->post(route('dashboard.account.roles.store'), [
            'name' => 'Shadow owner',
            'permissions' => [AccountPermission::ManageRoles->value],
        ])
        ->assertSessionHasErrors('permissions.0');

    expect(CustomRole::query()->exists())->toBeFalse();
});

test('custom permissions open only their matching account and site capabilities', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ManageKnowledge->value,
            AccountPermission::ManagePrivacySettings->value,
            AccountPermission::ViewReports->value,
        ],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.reports.index'))
        ->assertOk();
    $this->actingAs($agent)
        ->get(route('dashboard.account.articles.index'))
        ->assertOk();
    $this->actingAs($agent)
        ->get(route('dashboard.account.security.show'))
        ->assertForbidden();
    $this->actingAs($agent)
        ->get(route('dashboard.account.roles.index'))
        ->assertForbidden();
    $this->actingAs($agent)
        ->get(route('dashboard.sites.create'))
        ->assertForbidden();

    expect($agent->can('updatePrivacy', $site))->toBeTrue()
        ->and($agent->can('manageAccess', $site))->toBeFalse()
        ->and($agent->can('update', $site))->toBeFalse();
});

test('custom support roles do not receive alerts or dashboard data they cannot open', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $conversation = Conversation::factory()->for($site)->create(['status' => 'open']);
    Ticket::factory()->count(7)->for($account)->for($site)->create(['status' => 'open']);
    Ticket::factory()->for($account)->for($site)->for($conversation)->create([
        'status' => 'open',
        'subject' => 'Private linked ticket',
    ]);

    expect($agent->shouldReceiveConversationAlert($conversation))->toBeFalse();

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard.conversations.index'), false)
        ->assertDontSee(route('dashboard.tickets.index'), false)
        ->assertDontSee('7 open');
    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertDontSee(route('dashboard.conversations.messages.store', $conversation->support_code), false)
        ->assertDontSee('Private linked ticket');

    $role->forceFill(['permissions' => [
        AccountPermission::ViewAlerts->value,
        AccountPermission::ViewConversations->value,
    ]])->save();
    $agent->unsetRelation('customRole');

    expect($agent->shouldReceiveConversationAlert($conversation))->toBeTrue();
});

test('ticket managers without assignment permission do not see or use assignment controls', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee(route('dashboard.tickets.assignee.update', $ticket), false);

    $this->actingAs($agent)
        ->put(route('dashboard.tickets.assignee.update', $ticket), ['assignee_id' => $agent->id])
        ->assertNotFound();
});
