<?php

// Guided onboarding checklist (ADR 0011 slice 1c): a focused, mail-first walk to
// a runnable install under the platform-operator boundary, turning readiness
// diagnostics into inline actions.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\OperatorReadinessConfirmation;
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

/** Make every essential green EXCEPT the background-workers attestation. */
function greenExceptWorkers(): void
{
    config()->set('app.url', 'https://support.example.test');
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'smtp.example.com');
    config()->set('mail.mailers.smtp.port', 587);
    config()->set('mail.mailers.smtp.scheme', null);
    config()->set('mail.from.address', 'support@acme.test');
}

function confirmReadiness(string $key, User $operator): void
{
    OperatorReadinessConfirmation::query()->create([
        'key' => $key,
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now(),
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
        ->assertSee('of 4 ready')
        // The mail step offers a GUI Configure action, not just a CLI command.
        ->assertSee(route('operator.settings.mail.edit'), false)
        // Mail leads the guided order; background workers are a single confirmable
        // step (not a driver-only "Queue worker" that overclaims readiness).
        ->assertSeeInOrder(['Mail transport', 'Public URL', 'Confirm background workers', 'Backups and restore'])
        ->assertDontSee('Queue worker');
});

test('a scheduler-only confirmation does not complete the background-workers essential', function (): void {
    greenExceptWorkers();
    $operator = onboardingOperator();

    // Fresh scheduler + backups proofs exist, but no dedicated background-workers
    // proof — scheduler-only evidence must not satisfy worker readiness.
    confirmReadiness('scheduler', $operator);
    confirmReadiness('backups_restore', $operator);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('3 of 4 ready')
        ->assertDontSee('All essentials ready');
});

test('confirming background workers completes the checklist', function (): void {
    greenExceptWorkers();
    $operator = onboardingOperator();

    confirmReadiness('backups_restore', $operator);
    confirmReadiness('background_workers', $operator);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('4 of 4 ready')
        ->assertSee('All essentials ready');
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

test('the connect-site card is hidden for a site this operator cannot view', function (): void {
    $account = Account::factory()->create();
    $operator = onboardingOperator($account);
    // The site has explicit support agents that exclude this operator, so it is
    // outside their visibility (SitePolicy::view would 404 the link).
    $otherAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['name' => 'Restricted Docs']);
    $site->supportAgents()->attach($otherAgent->id);

    $this->actingAs($operator)
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertDontSee('Connect your first site')
        ->assertDontSee('Restricted Docs');
});

test('the onboarding confirmation form carries a return path back to onboarding', function (): void {
    $this->actingAs(onboardingOperator())
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->assertSee('name="redirect_to" value="onboarding"', false);
});

test('confirming a step from onboarding returns to the checklist, not the dashboard', function (): void {
    $this->actingAs(onboardingOperator())
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'scheduler',
            'redirect_to' => 'onboarding',
        ])
        ->assertRedirect(route('operator.onboarding'));
});

test('confirming a step without a return path still lands on the operator dashboard', function (): void {
    $this->actingAs(onboardingOperator())
        ->post(route('operator.readiness.confirmations.store'), [
            'key' => 'scheduler',
        ])
        ->assertRedirect(route('operator.dashboard'));
});
