<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function proactiveRulePayload(array $overrides = []): array
{
    return [
        'name' => 'Pricing page invitation',
        'message' => 'Questions about plans? We are here if you would like a hand.',
        'url_contains' => '/pricing',
        'referrer_contains' => 'search.example',
        'delay_seconds' => 45,
        'minimum_visit_count' => 2,
        'requires_available_agent' => '1',
        'frequency_cap_hours' => 168,
        'dismissal_snooze_days' => 30,
        'position' => 20,
        'is_enabled' => '0',
        ...$overrides,
    ];
}

test('automation managers can create edit and delete ordered proactive rules for a site', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Northwind Pricing']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Proactive messages by site')
        ->assertSee(route('dashboard.sites.proactive-messages.index', $site), false);

    $this->actingAs($admin)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertOk()
        ->assertSee('Proactive messages for')
        ->assertSee('Northwind Pricing')
        ->assertSee('Presence is off')
        ->assertSee('No proactive rules yet.');

    $this->actingAs($admin)
        ->post(route('dashboard.sites.proactive-messages.store', $site), proactiveRulePayload())
        ->assertRedirect();

    $rule = ProactiveMessageRule::query()->sole();

    expect(Str::isUuid($rule->public_id))->toBeTrue()
        ->and($rule->site_id)->toBe($site->id)
        ->and($rule->name)->toBe('Pricing page invitation')
        ->and($rule->message)->toBe('Questions about plans? We are here if you would like a hand.')
        ->and($rule->url_contains)->toBe('/pricing')
        ->and($rule->referrer_contains)->toBe('search.example')
        ->and($rule->delay_seconds)->toBe(45)
        ->and($rule->minimum_visit_count)->toBe(2)
        ->and($rule->requires_available_agent)->toBeTrue()
        ->and($rule->frequency_cap_minutes)->toBe(7 * 24 * 60)
        ->and($rule->dismissal_snooze_minutes)->toBe(30 * 24 * 60)
        ->and($rule->position)->toBe(20)
        ->and($rule->is_enabled)->toBeFalse();

    $this->actingAs($admin)
        ->get(route('dashboard.sites.proactive-messages.edit', [$site, $rule]))
        ->assertOk()
        ->assertSee('Pricing page invitation')
        ->assertSee('The referrer is evaluated locally and is not sent to Wayfindr.')
        ->assertSee('name="frequency_cap_hours"', false)
        ->assertSee('value="168"', false);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.proactive-messages.update', [$site, $rule]), proactiveRulePayload([
            'name' => 'Checkout invitation',
            'url_contains' => ' /checkout ',
            'referrer_contains' => ' ',
            'requires_available_agent' => '0',
            'frequency_cap_hours' => 24,
            'dismissal_snooze_days' => 14,
            'position' => 5,
            'is_enabled' => '1',
        ]))
        ->assertRedirect(route('dashboard.sites.proactive-messages.edit', [$site, $rule]))
        ->assertSessionHas('status', 'proactive_messages.flash.updated');

    expect($rule->fresh())
        ->name->toBe('Checkout invitation')
        ->url_contains->toBe('/checkout')
        ->referrer_contains->toBeNull()
        ->requires_available_agent->toBeFalse()
        ->frequency_cap_minutes->toBe(24 * 60)
        ->dismissal_snooze_minutes->toBe(14 * 24 * 60)
        ->position->toBe(5)
        ->is_enabled->toBeTrue();

    $audit = AuditEvent::query()
        ->where('action', 'proactive_message_rule.created')
        ->firstOrFail();

    expect($audit->site_id)->toBe($site->id)
        ->and($audit->metadata)->toMatchArray([
            'name' => 'Pricing page invitation',
            'is_enabled' => false,
            'position' => 20,
        ])
        ->and(json_encode($audit->metadata))->not->toContain(
            'Questions about plans',
            '/pricing',
            'search.example',
        )
        ->and(AuditEvent::query()->where('action', 'proactive_message_rule.updated')->count())->toBe(1);

    $this->actingAs($admin)
        ->delete(route('dashboard.sites.proactive-messages.destroy', [$site, $rule]))
        ->assertRedirect(route('dashboard.sites.proactive-messages.index', $site))
        ->assertSessionHas('status', 'proactive_messages.flash.deleted');

    expect(ProactiveMessageRule::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'proactive_message_rule.deleted')->count())->toBe(1);
});

test('proactive rules are evaluated in explicit site order', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    ProactiveMessageRule::factory()->for($site)->create(['name' => 'Later', 'position' => 20]);
    ProactiveMessageRule::factory()->for($site)->create(['name' => 'First by id', 'position' => 10]);
    ProactiveMessageRule::factory()->for($site)->create(['name' => 'Second by id', 'position' => 10]);

    $this->actingAs($admin)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertOk()
        ->assertSeeInOrder(['First by id', 'Second by id', 'Later']);
});

test('proactive management is delegated by automation permission and constrained by site access', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $ordinaryAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $otherSite = Site::factory()->for($account)->create();
    $otherRule = ProactiveMessageRule::factory()->for($otherSite)->create();
    $restricted = Site::factory()->for($account)->create(['name' => 'Restricted Site']);
    $restricted->supportAgents()->attach($ordinaryAgent);
    $foreignSite = Site::factory()->for($otherAccount)->create();
    $foreignRule = ProactiveMessageRule::factory()->for($foreignSite)->create();

    $this->actingAs($ordinaryAgent)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertForbidden();

    $this->actingAs($manager)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertOk();

    $this->actingAs($manager)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee(route('dashboard.sites.proactive-messages.index', $site), false)
        ->assertDontSee(route('dashboard.sites.proactive-messages.index', $restricted), false);

    $this->actingAs($manager)
        ->get(route('dashboard.sites.proactive-messages.index', $restricted))
        ->assertNotFound();

    $this->actingAs($manager)
        ->get(route('dashboard.sites.proactive-messages.edit', [$site, $otherRule]))
        ->assertNotFound();

    $this->actingAs($manager)
        ->get(route('dashboard.sites.proactive-messages.edit', [$foreignSite, $foreignRule]))
        ->assertNotFound();
});

test('archived sites cannot expose or mutate proactive configuration', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['archived_at' => now()]);
    $rule = ProactiveMessageRule::factory()->for($site)->create();

    $this->actingAs($admin)
        ->get(route('dashboard.sites.proactive-messages.index', $site))
        ->assertNotFound();

    $this->actingAs($admin)
        ->put(route('dashboard.sites.proactive-messages.update', [$site, $rule]), proactiveRulePayload())
        ->assertNotFound();

    expect($rule->fresh()->name)->not->toBe('Pricing page invitation');
});

test('proactive mutations reauthorize stale automation managers under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAutomations->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $rule = ProactiveMessageRule::factory()->for($site)->create(['name' => 'Original rule']);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageAutomations))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.sites.proactive-messages.store', $site), proactiveRulePayload()),
        'update' => $this->put(route('dashboard.sites.proactive-messages.update', [$site, $rule]), proactiveRulePayload()),
        'delete' => $this->delete(route('dashboard.sites.proactive-messages.destroy', [$site, $rule])),
    };

    $action === 'create' ? $response->assertForbidden() : $response->assertNotFound();
    expect(ProactiveMessageRule::query()->count())->toBe(1)
        ->and($rule->fresh()->name)->toBe('Original rule');
})->with(['create', 'update', 'delete']);

test('proactive rule validation keeps every trigger and cap bounded', function (array $change, string $field): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();

    $this->actingAs($admin)
        ->post(route('dashboard.sites.proactive-messages.store', $site), proactiveRulePayload($change))
        ->assertSessionHasErrors($field);

    expect(ProactiveMessageRule::query()->count())->toBe(0);
})->with([
    'empty message' => [['message' => ''], 'message'],
    'delay too long' => [['delay_seconds' => 301], 'delay_seconds'],
    'visit count zero' => [['minimum_visit_count' => 0], 'minimum_visit_count'],
    'frequency cap zero' => [['frequency_cap_hours' => 0], 'frequency_cap_hours'],
    'dismissal too long' => [['dismissal_snooze_days' => 91], 'dismissal_snooze_days'],
    'position too high' => [['position' => 10001], 'position'],
]);

test('a proactive rule name is unique within one site but reusable elsewhere', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create();
    $otherSite = Site::factory()->for($account)->create();
    ProactiveMessageRule::factory()->for($site)->create(['name' => 'Pricing page invitation']);

    $this->actingAs($admin)
        ->post(route('dashboard.sites.proactive-messages.store', $site), proactiveRulePayload())
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('dashboard.sites.proactive-messages.store', $otherSite), proactiveRulePayload())
        ->assertRedirect();

    expect(ProactiveMessageRule::query()->count())->toBe(2);
});
