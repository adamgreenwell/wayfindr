<?php

// The operator console (ADR 0014, step 7). Every one of these pages used to
// render OUTSIDE the application shell -- no rail, no breadcrumb, and a single
// text link at the top of each page to get back out of it.

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function platformOperator(): User
{
    return User::factory()->for(Account::factory()->create(['name' => 'Acme Support']))->create([
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);
}

test('operator pages render inside the application shell', function (): void {
    $this->actingAs(platformOperator())
        ->get('/operator')
        ->assertOk()
        ->assertSee('class="wf-rail"', false)
        ->assertSee('aria-label="Primary navigation"', false)
        ->assertSee('aria-label="Breadcrumb"', false);
});

test('every operator section is reachable from every other one', function (): void {
    // Seven sections navigated by one "back" link is what this replaces.
    $response = $this->actingAs(platformOperator())->get('/operator/settings/mail')->assertOk();

    $response->assertSee('aria-label="Operator sections"', false);

    foreach (['Console', 'Setup checklist', 'Mail', 'Storage', 'Scanning', 'Backups', 'Break-glass'] as $section) {
        $response->assertSee($section);
    }

    foreach ([
        route('operator.dashboard'),
        route('operator.onboarding'),
        route('operator.settings.storage.edit'),
        route('operator.settings.scanning.edit'),
        route('operator.settings.backups.edit'),
        route('operator.break-glass.index'),
    ] as $href) {
        $response->assertSee($href, false);
    }
});

test('the current section is marked, in the sidebar and the breadcrumb', function (): void {
    $this->actingAs(platformOperator())
        ->get('/operator/settings/backups')
        ->assertOk()
        ->assertSee('wf-crumb-current">Backups', false)
        // Asserted as a shape rather than an exact attribute order, which Blade
        // does not promise.
        ->assertSeeInOrder([
            'class="wf-context-link"',
            route('operator.settings.backups.edit'),
            'aria-current="page"',
        ], false);
});

test('a contextual return path survives, because mid-setup it is not the console', function (): void {
    // Arriving from the setup checklist has to return there, not to the
    // console -- the operator is part way through a sequence.
    $this->actingAs(platformOperator())
        ->get('/operator/settings/mail?from=onboarding')
        ->assertOk()
        ->assertSee('Back to setup checklist');

    $this->actingAs(platformOperator())
        ->get('/operator/settings/mail')
        ->assertOk()
        ->assertSee('Back to operator console');
});

test('no operator view renders on the bare layout any more', function (): void {
    // array_merge, not `+`: the union operator keeps the LEFT array's numeric
    // keys, so every top-level view was silently discarded and this guard only
    // ever looked at the six settings templates.
    $views = array_merge(
        glob(base_path('resources/views/operator/**/*.blade.php')) ?: [],
        glob(base_path('resources/views/operator/*.blade.php')) ?: [],
    );

    expect($views)->not->toBeEmpty();

    $bare = [];

    foreach ($views as $view) {
        if (str_contains((string) file_get_contents($view), '<x-layouts.app')) {
            $bare[] = basename($view);
        }
    }

    expect($bare)->toBe([]);
});
