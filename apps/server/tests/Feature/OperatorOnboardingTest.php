<?php

// Guided onboarding checklist (ADR 0011 slice 1c): a focused, mail-first walk to
// a runnable install under the platform-operator boundary, turning readiness
// diagnostics into inline actions.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function onboardingOperator(?Account $account = null): User
{
    return User::factory()->for($account ?? Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
}

test('a non-operator cannot reach the onboarding checklist', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.onboarding'))->assertForbidden();
});

test('the onboarding checklist is mail-first with an inline configure action', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Essential steps')
        ->assertSee('Configure the essentials')
        ->assertSee('of 5 ready')
        // The mail step offers a GUI Configure action, not just a CLI command.
        ->assertSee(route('operator.settings.mail.edit'), false)
        // Mail leads the guided order.
        ->assertSeeInOrder(['Mail transport', 'Public URL', 'Queue worker', 'Scheduler', 'Backups and restore']);
});

test('the onboarding checklist shows the connect-your-first-site card', function (): void {
    $account = Account::factory()->create();
    $operator = onboardingOperator($account);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('Connect your first site')
        ->assertSee('Acme Docs')
        ->assertSee(route('dashboard.sites.show', $site).'#install-snippet', false);
});

test('the onboarding checklist links back to the full operator diagnostic', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee(route('operator.dashboard'), false)
        ->assertSee('full instance diagnostic');
});
