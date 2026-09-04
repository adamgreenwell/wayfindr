<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('owners can deactivate and reactivate another same-account agent', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($owner)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Manage access')
        ->assertSee("/dashboard/account/agents/{$agent->id}/deactivate", false);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$agent->id}/deactivate")
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'account.flash.deactivated');

    $deactivatedEvent = AuditEvent::query()
        ->where('action', 'agent.deactivated')
        ->firstOrFail();

    expect($agent->fresh()->deactivated_at)->not->toBeNull()
        ->and($deactivatedEvent->account_id)->toBe($account->id)
        ->and($deactivatedEvent->actor->is($owner))->toBeTrue()
        ->and($deactivatedEvent->subject->is($agent))->toBeTrue();

    $this->actingAs($owner)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Deactivated')
        ->assertSee("/dashboard/account/agents/{$agent->id}/reactivate", false);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$agent->id}/reactivate")
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'account.flash.reactivated');

    $reactivatedEvent = AuditEvent::query()
        ->where('action', 'agent.reactivated')
        ->firstOrFail();

    expect($agent->fresh()->deactivated_at)->toBeNull()
        ->and($reactivatedEvent->account_id)->toBe($account->id)
        ->and($reactivatedEvent->actor->is($owner))->toBeTrue()
        ->and($reactivatedEvent->subject->is($agent))->toBeTrue();
});

test('admins can deactivate and reactivate custom-role agents', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewAudit->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee(route('dashboard.account.agents.deactivate', $agent), false);

    $this->actingAs($admin)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$agent->id}/deactivate")
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'account.flash.deactivated');

    expect($agent->fresh()->deactivated_at)->not->toBeNull();

    $this->actingAs($admin)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$agent->id}/reactivate")
        ->assertRedirect('/dashboard/account')
        ->assertSessionHas('status', 'account.flash.reactivated');

    expect($agent->fresh()->deactivated_at)->toBeNull();
});

test('admins cannot deactivate owners and agents cannot manage access', function (AccountRole $actorRole): void {
    $account = Account::factory()->create();
    $actor = User::factory()->for($account)->create(['account_role' => $actorRole]);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $target = $actorRole === AccountRole::Admin ? $owner : $agent;

    $this->actingAs($actor)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$target->id}/deactivate")
        ->assertForbidden();

    expect($target->fresh()->deactivated_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'agent.deactivated')->exists())->toBeFalse();
})->with([
    'admin against owner' => [AccountRole::Admin],
    'agent against agent' => [AccountRole::Agent],
]);

test('agent access changes stay inside the current account', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $outsideAgent = User::factory()->for($otherAccount)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$outsideAgent->id}/deactivate")
        ->assertForbidden();

    expect($outsideAgent->fresh()->deactivated_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'agent.deactivated')->exists())->toBeFalse();
});

test('custom agent managers can suspend teammates in the same role but not another custom role', function (): void {
    $account = Account::factory()->create();
    $managerRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageAgents->value],
    ]);
    $otherRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewAudit->value],
    ]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $managerRole->id,
    ]);
    $teammate = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $managerRole->id,
    ]);
    $other = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $otherRole->id,
    ]);

    $this->actingAs($manager)
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee(route('dashboard.account.agents.deactivate', $teammate), false)
        ->assertDontSee(route('dashboard.account.agents.deactivate', $other), false);

    $this->actingAs($manager)
        ->post(route('dashboard.account.agents.deactivate', $teammate))
        ->assertRedirect(route('dashboard.account.show'));

    $this->actingAs($manager)
        ->post(route('dashboard.account.agents.reactivate', $teammate))
        ->assertRedirect(route('dashboard.account.show'));

    $this->actingAs($manager)
        ->post(route('dashboard.account.agents.deactivate', $other))
        ->assertForbidden();

    expect($teammate->fresh()->isDeactivated())->toBeFalse()
        ->and($other->fresh()->isDeactivated())->toBeFalse()
        ->and(AuditEvent::query()->whereIn('action', ['agent.deactivated', 'agent.reactivated'])->count())->toBe(2);
});

test('owners cannot deactivate themselves', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->from('/dashboard/account')
        ->post("/dashboard/account/agents/{$owner->id}/deactivate")
        ->assertForbidden();

    expect($owner->fresh()->deactivated_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'agent.deactivated')->exists())->toBeFalse();
});

test('deactivated agents cannot log in', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'agent@example.test',
        'password' => Hash::make('password'),
        'deactivated_at' => now(),
    ]);

    $this->post('/login', [
        'email' => 'agent@example.test',
        'password' => 'password',
    ])
        ->assertRedirect('/')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($agent->fresh()->deactivated_at)->not->toBeNull();
});

test('deactivated agents are signed out before using dashboard routes', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'deactivated_at' => now(),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('access changes answer in the account page language', function (string $locale, string $deactivated, string $reactivated): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'locale' => $locale,
    ]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($owner)
        ->followingRedirects()
        ->post(route('dashboard.account.agents.deactivate', $agent))
        ->assertOk()
        ->assertSee($deactivated)
        ->assertDontSee('Agent deactivated.');

    $this->actingAs($owner)
        ->followingRedirects()
        ->post(route('dashboard.account.agents.reactivate', $agent))
        ->assertOk()
        ->assertSee($reactivated)
        ->assertDontSee('Agent reactivated.');
})->with([
    'German' => ['de', 'Agent deaktiviert.', 'Agent reaktiviert.'],
    'Italian' => ['it', 'Agente disattivato.', 'Agente riattivato.'],
]);
