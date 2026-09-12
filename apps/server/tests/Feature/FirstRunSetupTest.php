<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\FirstRunState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

test('first run setup page is available before bootstrap data exists', function (): void {
    $this->get('/setup')
        ->assertOk()
        ->assertSee('Set up Wayfindr')
        ->assertSee('Create the first account, owner, and install site.')
        ->assertSee('account_name', false)
        ->assertSee('agent_email', false)
        ->assertSee('site_name', false);
});

test('login redirects empty installs to first run setup', function (): void {
    $this->get('/login')
        ->assertRedirect('/setup');
});

test('first run setup page remains available when bootstrap records are incomplete', function (): void {
    $account = Account::factory()->create(['name' => 'Half Built Support']);

    Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);

    $this->get('/login')
        ->assertRedirect('/setup');

    $this->get('/setup')
        ->assertOk()
        ->assertSee('Finish setting up Wayfindr')
        ->assertSee('Some first-run records already exist, but no account owner has been created yet.');
});

test('first run setup creates the owner account site and session', function (): void {
    $response = $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ]);

    $account = Account::query()->sole();
    $agent = User::query()->sole();
    $site = Site::query()->sole();

    $response
        ->assertRedirect(route('operator.onboarding'))
        ->assertSessionHas('status', 'operator.onboarding.setup_complete');

    expect($account->name)->toBe('Acme Support')
        ->and($account->slug)->toBe('acme-support')
        ->and($agent->account_id)->toBe($account->id)
        ->and($agent->account_role)->toBe(AccountRole::Owner)
        ->and($agent->platform_role)->toBe(PlatformRole::Operator)
        ->and($agent->name)->toBe('Ada Agent')
        ->and($agent->email)->toBe('ada@example.com')
        ->and(Hash::check('correct-horse-battery-staple', $agent->password))->toBeTrue()
        ->and($site->account_id)->toBe($account->id)
        ->and($site->name)->toBe('Acme Docs')
        ->and($site->domain)->toBe('docs.example.test')
        ->and($site->public_key)->toStartWith('site_')
        ->and($site->settings)->toMatchArray([
            'mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]'],
        ]);

    expect($site->supportAgents()->whereKey($agent->id)->exists())->toBeTrue();

    $this->assertAuthenticatedAs($agent);
});

test('first run setup claims incomplete bootstrap records without creating duplicates', function (): void {
    $account = Account::factory()->create([
        'name' => 'Half Built Support',
        'slug' => 'half-built-support',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);

    $response = $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'https://docs.example.test/install',
    ]);

    $agent = User::query()->sole();

    $response
        ->assertRedirect(route('operator.onboarding'))
        ->assertSessionHas('status', 'operator.onboarding.setup_complete');

    expect(Account::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1)
        ->and($account->refresh()->name)->toBe('Acme Support')
        ->and($account->slug)->toBe('acme-support')
        ->and($site->refresh()->name)->toBe('Acme Docs')
        ->and($site->domain)->toBe('docs.example.test')
        ->and($agent->account_id)->toBe($account->id)
        ->and($agent->account_role)->toBe(AccountRole::Owner)
        ->and($agent->platform_role)->toBe(PlatformRole::Operator)
        ->and($site->supportAgents()->whereKey($agent->id)->exists())->toBeTrue();
});

test('first run completion follows the installation language on onboarding', function (
    string $locale,
    string $expected,
): void {
    config()->set('wayfindr.dashboard_locale', $locale);

    $this->followingRedirects()
        ->post('/setup', [
            'account_name' => 'Acme Support',
            'agent_name' => 'Ada Agent',
            'agent_email' => 'ada@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'site_name' => 'Acme Docs',
            'site_domain' => 'docs.example.test',
        ])
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'">', false)
        ->assertSee($expected)
        ->assertDontSee('Wayfindr is ready. Finish setting up your installation below');
})->with([
    'German' => [
        'de',
        'Wayfindr ist bereit. Schließen Sie die Einrichtung Ihrer Installation unten ab — verbinden Sie zuerst Ihre erste Website.',
    ],
    'Italian' => [
        'it',
        'Wayfindr è pronto. Completi la configurazione dell’installazione qui sotto, iniziando dal collegamento del primo sito.',
    ],
]);

test('first run setup rechecks setup state inside the recovery transaction', function (): void {
    $account = Account::factory()->create([
        'name' => 'Half Built Support',
        'slug' => 'half-built-support',
    ]);
    $site = Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);
    $existingOwner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'platform_role' => PlatformRole::Operator,
        'email' => 'owner@example.com',
    ]);

    $this->app->instance(FirstRunState::class, new class extends FirstRunState
    {
        private int $checks = 0;

        public function needsSetup(): bool
        {
            $this->checks++;

            return $this->checks === 1 || parent::needsSetup();
        }
    });

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ])
        ->assertRedirect('/login');

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->sole()->is($existingOwner))->toBeTrue()
        ->and($account->refresh()->name)->toBe('Half Built Support')
        ->and($site->refresh()->name)->toBe('Half Built Docs')
        ->and($site->supportAgents()->count())->toBe(0);
});

test('first run setup handoff shows install guidance and operator readiness links', function (): void {
    config()->set('app.url', 'https://support.example.test');

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ]);

    $site = Site::query()->sole();

    $this->get("/dashboard/sites/{$site->id}")
        ->assertOk()
        ->assertSee('Install snippet')
        ->assertSee('Next steps')
        ->assertSee('Copy this snippet into')
        ->assertSee('docs.example.test')
        ->assertSee('Visit the site and send a test message from the widget.')
        ->assertSee('Prove the install works')
        ->assertSee('Send a real email')
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com')
        ->assertSee('Confirm background workers')
        ->assertSee('php artisan queue:failed')
        ->assertSee('/operator', false)
        ->assertSee('Open operator console')
        ->assertSee('data-wayfindr-site-key=&quot;'.$site->public_key.'&quot;', false);
});

test('first run setup is locked after bootstrap data exists', function (): void {
    User::factory()->for(Account::factory())->create();

    $this->get('/setup')
        ->assertRedirect('/login');

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
    ])
        ->assertRedirect('/login');

    expect(User::query()->count())->toBe(1)
        ->and(Site::query()->count())->toBe(0);
});

test('losing the setup race says what happened', function (): void {
    // A complete six-field form against an install someone else just claimed
    // returned 302 to a bare sign-in page with no flash at all: no way to tell
    // whether the submission failed, was ignored, or half-applied.
    $account = Account::factory()->create();
    User::factory()->for($account)->create();

    $this->post(route('setup.store'), [
        'account_name' => 'Second Workspace',
        'agent_name' => 'Someone Else',
        'agent_email' => 'someone-else@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'site_name' => 'Their Site',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');

    $this->followingRedirects()
        ->get(route('setup.create'))
        ->assertOk()
        ->assertSee('This installation has already been set up.');
});

test('every path that sets a password asks for the same length', function (): void {
    // PasswordResetController already called Password::defaults() -- the right
    // thing -- and nothing registered one, so it silently got Laravel's min(8),
    // while the install hard-coded 12 and the profile change hard-coded 8. The
    // one correctly-written call site was the weakest of the three, so account
    // recovery accepted a shorter password than the install that created it.
    $eleven = 'elevenchars';

    // The install path.
    $this->post(route('setup.store'), [
        'account_name' => 'Acme', 'agent_name' => 'A',
        'agent_email' => 'a@example.test',
        'password' => $eleven, 'password_confirmation' => $eleven,
        'site_name' => 'S',
    ])->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);

    expect(User::count())->toBe(0);

    // The recovery path, which is the one that used to accept eight.
    $agent = User::factory()->for(Account::factory())->create();

    $this->post(route('password.update'), [
        'token' => Password::createToken($agent),
        'email' => $agent->email,
        'password' => $eleven, 'password_confirmation' => $eleven,
    ])->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);

    // The profile change, which also hard-coded its own number.
    $this->actingAs($agent)
        ->put(route('dashboard.profile.password.update'), [
            'current_password' => 'wrong-on-purpose',
            'password' => $eleven, 'password_confirmation' => $eleven,
        ])->assertSessionHasErrors(['password' => 'The password field must be at least 12 characters.']);
});

test('both password screens state the rule before they can reject you', function (): void {
    // Asserted on a GET, because the claim is that the rule is readable BEFORE
    // you fail -- which a test of the failure path cannot show.
    $this->get(route('setup.create'))
        ->assertOk()
        ->assertSee('At least 12 characters')
        ->assertSee('id="password-help"', false)
        ->assertSee('aria-describedby="password-help', false);

    $agent = User::factory()->for(Account::factory())->create();

    $this->get(route('password.reset', ['token' => Password::createToken($agent)]))
        ->assertOk()
        ->assertSee('At least 12 characters')
        ->assertSee('id="password-help"', false);
});

test('a rejected field is tied to its own error message', function (): void {
    // Not an attribute count. The property that matters is that the token in
    // aria-describedby resolves to the element carrying the message -- a wired
    // input pointing at an id nothing renders announces nothing. My own first
    // pass produced exactly that: ids derived from the error paragraph rather
    // than the input, so `agent_name-error-error`.
    $payload = [
        'account_name' => '', 'agent_name' => '',
        'agent_email' => 'not-an-address',
        'password' => 'short', 'password_confirmation' => 'different',
        'site_name' => '',
    ];

    $this->from(route('setup.create'))->post(route('setup.store'), $payload);

    $html = $this->followingRedirects()
        ->post(route('setup.store'), $payload)
        ->assertOk()
        ->getContent();

    // Blade's @error directive leaves whitespace inside the attribute, which is
    // fine -- aria-describedby is a space-separated token list -- so the
    // assertion reads the tokens rather than the byte string.
    preg_match_all('/aria-describedby="([^"]*)"/', $html, $matches);

    $tokens = collect($matches[1])
        ->flatMap(fn (string $value): array => preg_split('/\s+/', trim($value)) ?: [])
        ->filter()
        ->all();

    expect($tokens)->not->toBeEmpty();

    foreach (['account_name', 'agent_name', 'agent_email', 'password'] as $field) {
        // the input points at an id...
        expect($tokens)->toContain($field.'-error');
        // ...and something renders that id
        expect($html)->toContain('id="'.$field.'-error"');
    }

    // and the invalid state sits on the field, not only in the prose
    expect(substr_count($html, 'aria-invalid="true"'))->toBeGreaterThanOrEqual(4);

    // Every marked input must point at ITS OWN field's message. Resolving to
    // something that renders is not enough: my first pass wired
    // password_confirmation to site_name's error, which resolved perfectly and
    // told a screen reader the wrong control was invalid.
    preg_match_all('/<input\b[^>]*>/s', $html, $inputs);

    foreach ($inputs[0] as $input) {
        if (! str_contains($input, 'aria-describedby=')) {
            continue;
        }

        preg_match('/name="([a-z_0-9]+)"/', $input, $name);
        preg_match('/aria-describedby="([^"]*)"/', $input, $described);

        foreach (preg_split('/\s+/', trim($described[1])) ?: [] as $token) {
            if ($token === '' || str_ends_with($token, '-help')) {
                continue;
            }

            expect($token)->toBe(
                $name[1].'-error',
                "the {$name[1]} input points at {$token}, which belongs to another field",
            );
        }
    }
});
