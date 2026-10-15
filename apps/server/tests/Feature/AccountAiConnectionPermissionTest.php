<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Policies\ConversationPolicy;
use App\Policies\SitePolicy;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Synthetic lifecycle boundary; no provider request or shared test helper. */
function accountAiPermissionLifecycle(): array
{
    $probe = new class implements AccountAiConnectionProbe
    {
        public int $calls = 0;

        public function policyRevision(): string
        {
            return 'ai-permission-synthetic-policy';
        }

        public function probe(ResolvedAgentCopilotConnection $connection): AgentCopilotResult
        {
            $this->calls++;

            return new AgentCopilotResult('Synthetic acknowledgement', $connection->provider, $connection->model);
        }
    };
    app()->instance(AccountAiConnectionProbe::class, $probe);

    return [app(AccountAiConnections::class), $probe];
}

function accountAiPermissionProposal(AccountAiConnections $service, User $actor, array $changes = []): AccountAiConnection
{
    return $service->propose($actor, (int) $actor->account_id, [
        'source' => 'account',
        'name' => 'Support model',
        'provider' => 'openai-compatible',
        'model' => 'permission-test-model',
        'endpoint' => 'https://models.example.test/v1',
        'api_key' => 'synthetic-permission-key',
        ...$changes,
    ]);
}

test('AI connection management belongs to owners and admins but not ordinary agents', function (AccountRole $role, bool $allowed): void {
    $permission = AccountPermission::tryFrom('manage_ai_connections');

    expect($permission)->not->toBeNull();

    $actor = User::factory()->for(Account::factory())->create(['account_role' => $role]);

    expect($actor->hasAccountPermission($permission))->toBe($allowed);
})->with([
    'owner' => [AccountRole::Owner, true],
    'admin' => [AccountRole::Admin, true],
    'ordinary agent' => [AccountRole::Agent, false],
]);

test('owners can delegate and revoke AI management without granting support or site access', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->post(route('dashboard.account.roles.store'), [
            'name' => 'AI connection manager',
            'permissions' => [AccountPermission::ManageAiConnections->value],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $role = CustomRole::query()->sole();
    $manager = User::factory()->for($account)->create(['custom_role_id' => $role->id]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($owner);
    $conversation = Conversation::factory()->for($site)->create();

    expect($role->permissionValues())->toBe([AccountPermission::ManageAiConnections->value])
        ->and($manager->hasAccountPermission(AccountPermission::ManageAiConnections))->toBeTrue()
        ->and($manager->hasAccountPermission(AccountPermission::ManageIntegrations))->toBeFalse()
        ->and($manager->hasAccountPermission(AccountPermission::ViewConversations))->toBeFalse()
        ->and($manager->hasAccountPermission(AccountPermission::ReplyToConversations))->toBeFalse()
        ->and($manager->hasAccountPermission(AccountPermission::ManageTickets))->toBeFalse()
        ->and($manager->hasAccountPermission(AccountPermission::ManageSiteAccess))->toBeFalse()
        ->and($manager->isPlatformOperator())->toBeFalse()
        ->and($site->supportsAgent($manager))->toBeFalse()
        ->and(app(SitePolicy::class)->view($manager, $site))->toBeFalse()
        ->and(app(ConversationPolicy::class)->view($manager, $conversation))->toBeFalse()
        ->and(app(ConversationPolicy::class)->reply($manager, $conversation))->toBeFalse();

    $this->actingAs($owner)
        ->put(route('dashboard.account.roles.update', $role), [
            'name' => 'AI connection manager',
            'permissions' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($manager->fresh()->hasAccountPermission(AccountPermission::ManageAiConnections))->toBeFalse();
});

test('AI connection management does not bypass a separately granted support site boundary', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ManageAiConnections->value,
            AccountPermission::ViewConversations->value,
            AccountPermission::ReplyToConversations->value,
        ],
    ]);
    $manager = User::factory()->for($account)->create(['custom_role_id' => $role->id]);
    $teammate = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($teammate);
    $conversation = Conversation::factory()->for($site)->create();

    expect($manager->hasAccountPermission(AccountPermission::ManageAiConnections))->toBeTrue()
        ->and(app(ConversationPolicy::class)->view($manager, $conversation))->toBeFalse()
        ->and(app(ConversationPolicy::class)->reply($manager, $conversation))->toBeFalse();
});

test('AI management is denied to undelegated cross-account suspended and operator-only actors', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAiConnections->value],
    ]);
    $integratorRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageIntegrations->value],
    ]);
    $integrator = User::factory()->for($account)->create(['custom_role_id' => $integratorRole->id]);
    $outside = User::factory()->for(Account::factory())->create(['custom_role_id' => $role->id]);
    $suspended = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'deactivated_at' => now(),
    ]);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
    ]);

    foreach ([$integrator, $outside, $suspended, $operator] as $actor) {
        expect($actor->hasAccountPermission(AccountPermission::ManageAiConnections))->toBeFalse();
    }
});

test('AI management is a localized account-setting checkbox in custom role create and edit forms', function (string $locale): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner, 'locale' => $locale]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAiConnections->value],
    ]);

    $response = $this->actingAs($owner)
        ->get(route('dashboard.account.roles.index'))
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false);
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $prefixes = ['new-permission-', 'role-'.$role->id.'-permission-'];
    $label = __('account_roles.permissions.manage_ai_connections.label', [], $locale);
    $detail = __('account_roles.permissions.manage_ai_connections.detail', [], $locale);
    $group = __('account_roles.groups.account', [], $locale);

    expect($label)->not->toBe('account_roles.permissions.manage_ai_connections.label')
        ->and($detail)->not->toBe('account_roles.permissions.manage_ai_connections.detail');

    foreach ($prefixes as $prefix) {
        $id = $prefix.AccountPermission::ManageAiConnections->value;
        $checkbox = $xpath->query('//input[@id="'.$id.'" and @name="permissions[]" and @value="manage_ai_connections"]');
        $text = $xpath->query('//label[@for="'.$id.'"]')->item(0)?->textContent ?? '';
        $legend = $xpath->query('//input[@id="'.$id.'"]/ancestor::fieldset[1]/legend')->item(0)?->textContent ?? '';

        expect($checkbox->length)->toBe(1)
            ->and(trim((string) preg_replace('/\s+/u', ' ', $text)))->toBe($label.' '.$detail)
            ->and(trim($legend))->toBe($group);
    }

    expect($xpath->query('//input[@id="new-permission-manage_ai_connections" and @checked]')->length)->toBe(0)
        ->and($xpath->query('//input[@id="role-'.$role->id.'-permission-manage_ai_connections" and @checked]')->length)->toBe(1);
})->with(['en', 'de', 'it']);

test('a restricted AI manager can propose and test but cannot reroute another sites support text', function (): void {
    [$service, $probe] = accountAiPermissionLifecycle();
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAiConnections->value],
    ]);
    $manager = User::factory()->for($account)->create(['custom_role_id' => $role->id]);
    $siteA = Site::factory()->for($account)->create();
    $siteB = Site::factory()->for($account)->create();
    $siteA->supportAgents()->attach([$owner->id, $manager->id]);
    $siteB->supportAgents()->attach($owner);
    $original = accountAiPermissionProposal($service, $owner);
    $service->test($owner, (int) $account->id, $original->proposed_revision);
    $active = $service->activate($owner, (int) $account->id, $original->proposed_revision);

    $candidate = accountAiPermissionProposal($service, $manager, ['model' => 'rerouted-model']);
    $tested = $service->test($manager, (int) $account->id, $candidate->proposed_revision);

    expect($tested->test_status)->toBe('ready')
        ->and($probe->calls)->toBe(2)
        ->and($siteA->supportsAgent($manager))->toBeTrue()
        ->and($siteB->supportsAgent($manager))->toBeFalse()
        ->and(fn () => $service->activate($manager, (int) $account->id, $candidate->proposed_revision))
        ->toThrow(AuthorizationException::class)
        ->and($active->fresh()->active_revision)->toBe($active->active_revision)
        ->and($active->fresh()->active_configuration['model'])->toBe('permission-test-model');
});

test('owners and admins with every affected site can activate and reenable the shared connection', function (AccountRole $role): void {
    [$service] = accountAiPermissionLifecycle();
    $account = Account::factory()->create();
    $actor = User::factory()->for($account)->create(['account_role' => $role]);
    $siteA = Site::factory()->for($account)->create();
    $siteB = Site::factory()->for($account)->create();
    $siteA->supportAgents()->attach($actor);
    $siteB->supportAgents()->attach($actor);
    // Another account's inaccessible site is outside this shared connection.
    Site::factory()->create();
    $candidate = accountAiPermissionProposal($service, $actor);
    $service->test($actor, (int) $account->id, $candidate->proposed_revision);
    $active = $service->activate($actor, (int) $account->id, $candidate->proposed_revision);

    expect($active->active_revision)->toBe($candidate->proposed_revision);

    $service->setEnabled($actor, (int) $account->id, false);

    expect($service->setEnabled($actor, (int) $account->id, true)->enabled)->toBeTrue();
})->with([
    'owner' => AccountRole::Owner,
    'admin' => AccountRole::Admin,
]);

test('freshly restricted AI managers may disable and disconnect but cannot reenable shared processing', function (): void {
    [$service] = accountAiPermissionLifecycle();
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAiConnections->value],
    ]);
    $manager = User::factory()->for($account)->create(['custom_role_id' => $role->id]);
    $teammate = User::factory()->for($account)->create();
    $siteA = Site::factory()->for($account)->create();
    $siteB = Site::factory()->for($account)->create();
    $siteA->supportAgents()->attach($manager);
    $siteB->supportAgents()->attach([$manager->id, $teammate->id]);
    $candidate = accountAiPermissionProposal($service, $manager);
    $service->test($manager, (int) $account->id, $candidate->proposed_revision);
    $service->activate($manager, (int) $account->id, $candidate->proposed_revision);
    $siteB->supportAgents()->detach($manager);

    expect($service->setEnabled($manager, (int) $account->id, false)->enabled)->toBeFalse()
        ->and(fn () => $service->setEnabled($manager, (int) $account->id, true))
        ->toThrow(AuthorizationException::class)
        ->and(AccountAiConnection::query()->sole()->enabled)->toBeFalse();

    $disconnected = $service->disconnect($manager, (int) $account->id);

    expect($disconnected->enabled)->toBeFalse()
        ->and($disconnected->active_revision)->toBeNull();
});
