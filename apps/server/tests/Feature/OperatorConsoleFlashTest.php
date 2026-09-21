<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\User;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The operator console confirms what it did.
 *
 * Every other surface in the product answers an action with a flashed line.
 * The console renders the readiness confirmation form, its controller flashes
 * `operator.readiness.confirmation.saved` on success, and neither the view nor
 * the operator layout rendered anywhere for it to land -- so an operator
 * confirmed a readiness item and the page came back silent, with the work done
 * and nothing saying so.
 */
function operatorConsoleUser(): User
{
    return User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Owner,
        'platform_role' => PlatformRole::Operator,
    ]);
}

test('confirming a readiness item from the console says that it saved', function (): void {
    $operator = operatorConsoleUser();
    $key = OperatorReadiness::confirmableKeys()[0];

    $this->actingAs($operator)
        ->from(route('operator.dashboard'))
        ->post(route('operator.readiness.confirmations.store'), ['key' => $key])
        ->assertRedirect(route('operator.dashboard'));

    // Following the redirect is the whole point: the flash is set, and until
    // now nothing on the destination rendered it.
    $this->actingAs($operator)
        ->withSession(['status' => 'operator.readiness.confirmation.saved'])
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee(__('operator.readiness.confirmation.saved'));
});

test('the console announces its confirmation to a screen reader', function (): void {
    $operator = operatorConsoleUser();

    $html = (string) $this->actingAs($operator)
        ->withSession(['status' => 'operator.readiness.confirmation.saved'])
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->getContent();

    // A confirmation nobody can hear is the same defect one step quieter. The
    // audit found `status-message` hand-rolled 35 times and carrying a live
    // region in two of them.
    expect($html)->toContain('role="status"');
});

test('a page that used to render its own flash does not now render two', function (): void {
    $operator = operatorConsoleUser();

    $html = (string) $this->actingAs($operator)
        ->withSession(['status' => 'operator.readiness.confirmation.saved'])
        ->get(route('operator.onboarding'))
        ->assertOk()
        ->getContent();

    // Onboarding, webpush, localization and backups-restore each hand-rolled
    // their own region before the layout grew one. Removing theirs and adding
    // the layout's are two halves of one change, and doing only the second
    // shows the operator the same line twice.
    expect(substr_count($html, 'class="status-message"'))->toBe(1);
});

test('an operator failure is announced assertively, not politely', function (): void {
    $operator = operatorConsoleUser();

    $html = (string) $this->actingAs($operator)
        ->withSession(['error' => 'operator.readiness.confirmation.saved'])
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->getContent();

    // A confirmation can wait for a pause in what a screen reader is saying;
    // a failure cannot. That distinction is the whole reason both roles exist,
    // and 17 operator paths flash an error that nothing rendered at all.
    expect($html)->toContain('role="alert"')
        ->and($html)->not->toContain('role="status"');
});
