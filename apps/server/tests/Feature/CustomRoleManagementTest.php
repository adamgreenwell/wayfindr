<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\OidcConnection;
use App\Models\OidcRoleMapping;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AgentRealtimeSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The roles list renders one <article> per role and every delete button inside
 * it is byte-identical, so a whole-page assertion cannot tell which role it
 * matched. Named for this file rather than for the concept: Pest helpers are
 * global, and a bare `articleMarkup()` would collide.
 */
function roleArticleMarkup(string $html, int $roleId): string
{
    $start = strpos($html, 'role-'.$roleId.'-name');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, '</article>', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

function customRoleManagementXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

/** How many open or closed disclosures hold this role's edit form. */
function customRoleManagementEditForms(DOMXPath $xpath, CustomRole $role, bool $open): int
{
    $state = $open ? '@open' : 'not(@open)';

    return (int) $xpath->query('//details['.$state.']//form[@action="'.route('dashboard.account.roles.update', $role).'"]')?->length;
}

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

test('changing assigned role permissions disconnects affected realtime sessions after commit', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $sessions = Mockery::mock(AgentRealtimeSessions::class);
    $sessions->shouldReceive('requestMany')
        ->once()
        ->withArgs(fn (iterable $agentIds): bool => collect($agentIds)->contains($agent->id)
            // RefreshDatabase owns level one; the controller's account
            // transaction raises this to level two while both writes commit.
            && DB::transactionLevel() === 2);
    $sessions->shouldReceive('disconnectMany')
        ->once()
        ->withArgs(fn (iterable $agentIds): bool => collect($agentIds)->contains($agent->id)
            // RefreshDatabase owns level one; the controller's account
            // transaction would raise this to level two if still open.
            && DB::transactionLevel() === 1);
    $this->app->instance(AgentRealtimeSessions::class, $sessions);

    $this->actingAs($owner)
        ->put(route('dashboard.account.roles.update', $role), [
            'name' => $role->name,
            'permissions' => [AccountPermission::ViewAudit->value],
        ])
        ->assertRedirect();

    expect($role->fresh()->permissionValues())->toBe([AccountPermission::ViewAudit->value]);
});

test('normalized custom role names must fit the persisted key boundary', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'name' => 'Original role',
        'name_key' => 'original role',
    ]);
    $expandingName = str_repeat('İ', 80);

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->post(route('dashboard.account.roles.store'), ['name' => $expandingName])
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('name');

    $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->put(route('dashboard.account.roles.update', $role), ['name' => $expandingName])
        ->assertRedirect(route('dashboard.account.roles.index'))
        ->assertSessionHasErrors('name');

    expect(CustomRole::query()->count())->toBe(1)
        ->and($role->fresh()->name)->toBe('Original role')
        ->and(AuditEvent::query()->where('action', 'custom_role.updated')->exists())->toBeFalse();
});

test('custom role mutations reauthorize a stale owner after acquiring the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'name' => 'Original role',
        'name_key' => 'original role',
    ]);

    $this->actingAs($owner);

    // Keep the authenticated object stale to model a request that passed its
    // first authorization check immediately before another owner demoted it.
    User::query()->whereKey($owner->id)->update(['account_role' => AccountRole::Admin->value]);

    $response = match ($action) {
        'store' => $this->post(route('dashboard.account.roles.store'), ['name' => 'Late role']),
        'update' => $this->put(route('dashboard.account.roles.update', $role), ['name' => 'Late rename']),
        'destroy' => $this->delete(route('dashboard.account.roles.destroy', $role)),
    };

    $response->assertForbidden();

    expect(CustomRole::query()->count())->toBe(1)
        ->and($role->fresh()->name)->toBe('Original role')
        ->and(AuditEvent::query()->whereIn('action', [
            'custom_role.created',
            'custom_role.updated',
            'custom_role.deleted',
        ])->exists())->toBeFalse();
})->with(['store', 'update', 'destroy']);

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

test('custom role permissions reuse one loaded role lookup', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);

    $agent->unsetRelation('customRole');
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        expect($agent->hasAccountPermission(AccountPermission::ViewConversations))->toBeTrue()
            ->and($agent->hasAccountPermission(AccountPermission::ManageTickets))->toBeFalse()
            ->and($agent->hasAccountPermission(AccountPermission::ViewConversations))->toBeTrue();

        $roleQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'custom_roles'));
    } finally {
        DB::disableQueryLog();
    }

    expect($agent->relationLoaded('customRole'))->toBeTrue()
        ->and($roleQueries)->toHaveCount(1);
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

test('the roles list disables delete for a role an sso claim still maps to', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $mappedRole = CustomRole::factory()->for($account)->create(['name' => 'Mapped role']);
    $freeRole = CustomRole::factory()->for($account)->create(['name' => 'Free role']);

    // Nobody holds either role, so the people check clears both. The only thing
    // separating them is the claim mapping -- which is exactly the condition
    // destroy() enforces and the list could not previously see.
    $connection = OidcConnection::factory()->for($account)->create();
    OidcRoleMapping::factory()
        ->for($connection, 'connection')
        ->create(['custom_role_id' => $mappedRole->id, 'built_in_role' => null]);

    $html = (string) $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->getContent();

    // Slice each role's own article so an assertion cannot be satisfied by the
    // other role's markup -- both buttons carry identical text and classes.
    $mappedBlock = roleArticleMarkup($html, $mappedRole->id);
    $freeBlock = roleArticleMarkup($html, $freeRole->id);

    expect($mappedBlock)->toContain('disabled');
    expect($mappedBlock)->toContain(__('account_roles.errors.oidc_mapped'));

    // The control group: without a mapping the button stays live. Without this
    // half the test would pass against a view that disabled every delete.
    expect($freeBlock)->not->toContain('disabled');
});

test('an account with no custom roles is pointed at the create form', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $xpath = customRoleManagementXpath((string) $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->getContent());

    expect($xpath->query('//div[contains(concat(" ", @class, " "), " empty-state ")][strong[normalize-space(.)="'.__('account_roles.existing.empty').'"]]//a[@href="#create-role-heading"]')?->length)
        ->toBe(1, 'the first-run roles state carries no action to the create form')
        ->and($xpath->query('//h2[@id="create-role-heading"]')?->length)
        ->toBe(1, 'the empty-state action points at an id that does not exist');
});

test('the roles roster lists each role as one line, its permissions collapsed', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $roles = collect(['Auditor', 'Support lead'])->map(fn (string $name): CustomRole => CustomRole::factory()
        ->for($account)
        ->create(['name' => $name, 'name_key' => strtolower($name)]));

    $xpath = customRoleManagementXpath((string) $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->getContent());

    expect($xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " section ")]')?->length)
        ->toBe(0, 'a role renders as a .section card nested inside the card that lists it');

    foreach ($roles as $role) {
        expect($xpath->query('//article[@id="role-'.$role->id.'" and @class="section-item"]')?->length)
            ->toBe(1, "{$role->name} is not an item of the roles card");
        expect(customRoleManagementEditForms($xpath, $role, open: false))
            ->toBe(1, "{$role->name}'s nineteen permissions render expanded instead of behind a disclosure");

        // Collapsing the edit form must not take the role's name or its delete
        // control with it.
        expect($xpath->query('//article[@id="role-'.$role->id.'"]//h3[not(ancestor::details) and normalize-space(.)="'.$role->name.'"]')?->length)
            ->toBe(1, "{$role->name}'s name is hidden inside the disclosure")
            ->and($xpath->query('//form[@action="'.route('dashboard.account.roles.destroy', $role).'" and not(ancestor::details)]')?->length)
            ->toBe(1, "{$role->name}'s delete control is hidden inside the disclosure");
    }
});

test('the role a save returns to opens its edit form and no other', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $auditor = CustomRole::factory()->for($account)->create(['name' => 'Auditor', 'name_key' => 'auditor']);
    $lead = CustomRole::factory()->for($account)->create(['name' => 'Support lead', 'name_key' => 'support lead']);

    $xpath = customRoleManagementXpath((string) $this->actingAs($owner)
        ->followingRedirects()
        ->put(route('dashboard.account.roles.update', $auditor), [
            'name' => 'Auditor',
            'permissions' => [AccountPermission::ViewAudit->value],
        ])
        ->assertOk()
        ->getContent());

    expect(customRoleManagementEditForms($xpath, $auditor, open: true))
        ->toBe(1, 'the role just saved came back collapsed, so its saved permissions are out of sight')
        ->and(customRoleManagementEditForms($xpath, $lead, open: false))
        ->toBe(1, 'a role nobody touched opened too');
});

test('a failed role edit reopens that role with its error and what was typed, and leaves the create form alone', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $auditor = CustomRole::factory()->for($account)->create([
        'name' => 'Auditor',
        'name_key' => 'auditor',
        'permissions' => [AccountPermission::ViewAudit->value],
    ]);
    $lead = CustomRole::factory()->for($account)->create(['name' => 'Support lead', 'name_key' => 'support lead']);

    $auditorForm = '//form[@action="'.route('dashboard.account.roles.update', $auditor).'"]';
    $createForm = '//form[@action="'.route('dashboard.account.roles.store').'"]';

    // Submit what the rendered form carries, as a browser would. The hidden
    // field is how the page learns which role a failed save came from.
    $hidden = [];

    foreach (customRoleManagementXpath((string) $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->getContent())->query($auditorForm.'//input[@type="hidden"]') ?? [] as $input) {
        $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
    }

    // Renaming onto an existing name fails on `name` -- the key the create form
    // renders its own errors under.
    $xpath = customRoleManagementXpath((string) $this->actingAs($owner)
        ->from(route('dashboard.account.roles.index'))
        ->followingRedirects()
        ->put(route('dashboard.account.roles.update', $auditor), [
            ...$hidden,
            'name' => 'Support lead',
            'permissions' => [AccountPermission::ViewAudit->value, AccountPermission::ViewReports->value],
        ])
        ->assertOk()
        ->getContent());

    expect(customRoleManagementEditForms($xpath, $auditor, open: true))
        ->toBe(1, 'the failed edit left its role collapsed, hiding the error so the change looks silently lost')
        ->and(customRoleManagementEditForms($xpath, $lead, open: false))
        ->toBe(1, 'a role nobody touched opened too')
        ->and($xpath->query($auditorForm.'//p[@class="field-error" and normalize-space(.)="'.__('account_roles.errors.duplicate').'"]')?->length)
        ->toBe(1, 'the edit error does not render inside the role it belongs to')
        ->and($xpath->query($createForm.'//p[@class="field-error"]')?->length)
        ->toBe(0, 'the edit error renders under the create form')
        ->and($xpath->query($createForm.'//input[@name="name" and @value!=""]')?->length)
        ->toBe(0, 'the create form is prefilled with the failed edit')
        ->and($xpath->query($createForm.'//input[@type="checkbox" and @checked]')?->length)
        ->toBe(0, 'the create form is ticked with the failed edit')
        ->and($xpath->query($auditorForm.'//input[@name="name" and @value="Support lead"]')?->length)
        ->toBe(1, 'the role form lost the name that was typed')
        ->and($xpath->query($auditorForm.'//input[@name="name" and @autofocus]')?->length)
        ->toBe(1, 'the failed role takes no focus, so the page reloads at the top with its error out of sight')
        ->and($xpath->query($auditorForm.'//input[@type="checkbox" and @checked and @value="'.AccountPermission::ViewReports->value.'"]')?->length)
        ->toBe(1, 'the role form lost the permission that was ticked');

    expect($auditor->fresh()->name)->toBe('Auditor');
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

test('custom site creators must also be able to manage site access', function (): void {
    $account = Account::factory()->create();
    $siteEditorRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageSites->value],
    ]);
    $siteCreatorRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ManageSites->value,
            AccountPermission::ManageSiteAccess->value,
        ],
    ]);
    $siteEditor = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $siteEditorRole->id,
    ]);

    $this->actingAs($siteEditor)
        ->get(route('dashboard.sites.create'))
        ->assertForbidden();
    $this->actingAs($siteEditor)
        ->post(route('dashboard.sites.store'), ['name' => 'Stranded site'])
        ->assertForbidden();

    $siteCreator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $siteCreatorRole->id,
    ]);

    $this->actingAs($siteCreator)
        ->get(route('dashboard.sites.create'))
        ->assertOk();
    $this->actingAs($siteCreator)
        ->post(route('dashboard.sites.store'), ['name' => 'Managed site'])
        ->assertRedirect();

    $site = Site::query()->where('name', 'Managed site')->firstOrFail();

    expect($site->supportAgents()->whereKey($siteCreator->id)->exists())->toBeTrue()
        ->and($siteCreator->can('manageAccess', $site))->toBeTrue();

    // The request model is intentionally stale: the account lock must make
    // the database role authoritative before creating another site.
    User::query()->whereKey($siteCreator->id)->update(['custom_role_id' => $siteEditorRole->id]);

    $this->actingAs($siteCreator)
        ->post(route('dashboard.sites.store'), ['name' => 'Stale creator site'])
        ->assertForbidden();

    expect(Site::query()->where('name', 'Stale creator site')->exists())->toBeFalse();
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
