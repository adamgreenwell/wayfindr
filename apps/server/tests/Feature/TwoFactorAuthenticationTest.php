<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Http\Controllers\AgentProfileTwoFactorController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Middleware\EnsureAgentIsActive;
use App\Http\Middleware\EnsureTwoFactorPolicy;
use App\Http\Middleware\SerializeAgentBroadcastAuthorization;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Auth\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * @return array{secret: string, recovery_code: string}
 */
function giveAgentTwoFactor(User $user): array
{
    $secret = app(TwoFactorAuthentication::class)->generateSecret();
    $recoveryCode = 'ABCDE-12345';

    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => [Hash::make('ABCDE12345')],
        'two_factor_confirmed_at' => now(),
        'two_factor_last_used_timestep' => 0,
    ])->save();

    return ['secret' => $secret, 'recovery_code' => $recoveryCode];
}

test('an agent enrols from their profile and sees recovery codes once', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('correct-password'),
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.profile.two-factor.start'), [
            'current_password' => 'correct-password',
        ])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionHas(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY)
        ->assertSessionHas(AgentProfileTwoFactorController::ENROLMENT_CREDENTIAL_SESSION_KEY);

    $encryptedSecret = session(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY);
    $secret = Crypt::decryptString($encryptedSecret);

    expect($encryptedSecret)->not->toContain($secret);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('data:image/png;base64,', false)
        ->assertSee($secret);

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.two-factor.confirm'), [
            'one_time_code' => $code,
        ])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionMissing(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY)
        ->assertSessionMissing(AgentProfileTwoFactorController::ENROLMENT_CREDENTIAL_SESSION_KEY)
        ->assertSessionHas(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY);

    $encryptedCodes = session(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY);
    $recoveryCodes = json_decode(Crypt::decryptString($encryptedCodes), true, flags: JSON_THROW_ON_ERROR);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee($recoveryCodes[0])
        ->assertSessionMissing(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertDontSee($recoveryCodes[0]);
});

test('an invalid enrolment code is not flashed to the session', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->withSession([
            AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY => Crypt::encryptString(
                app(TwoFactorAuthentication::class)->generateSecret(),
            ),
            AgentProfileTwoFactorController::ENROLMENT_CREDENTIAL_SESSION_KEY => PendingTwoFactorChallenge::credentialFingerprint($agent),
        ])
        ->from(route('dashboard.profile.show'))
        ->put(route('dashboard.profile.two-factor.confirm'), [
            'one_time_code' => '000000',
        ])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionHasErrorsIn('twoFactorConfirm', 'one_time_code')
        ->assertSessionMissing('_old_input.one_time_code');
});

test('a two-factor agent stays signed out until the challenge succeeds', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'agent@example.com',
        'password' => Hash::make('password'),
        'remember_token' => 'existing-remember-token',
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->get(route('dashboard.sites.index'))
        ->assertRedirect(route('login'));

    $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'password',
        'remember' => '1',
    ])
        ->assertRedirect(route('two-factor.challenge'))
        ->assertSessionHas(TwoFactorChallengeController::SESSION_KEY);

    $this->assertGuest();
    expect($agent->fresh()->remember_token)->toBe('existing-remember-token');

    $this->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertDontSee('inputmode="numeric"', false);

    $this->post(route('two-factor.challenge.store'), [
        'one_time_code' => app(Google2FA::class)->getCurrentOtp($credential['secret']),
    ])
        ->assertRedirect(route('dashboard.sites.index'))
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($agent);
});

test('a two-factor challenge expires and rechecks deactivation', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    giveAgentTwoFactor($agent);

    $this->withSession([
        TwoFactorChallengeController::SESSION_KEY => [
            'user_id' => $agent->id,
            'started_at' => now()->timestamp - TwoFactorChallengeController::LIFETIME_SECONDS - 1,
            'remember' => false,
            'credential_fingerprint' => PendingTwoFactorChallenge::credentialFingerprint($agent),
        ],
    ])->get(route('two-factor.challenge'))
        ->assertRedirect(route('login'));

    $agent->update(['deactivated_at' => now()]);

    $this->withSession([
        TwoFactorChallengeController::SESSION_KEY => [
            'user_id' => $agent->id,
            'started_at' => now()->timestamp,
            'remember' => false,
            'credential_fingerprint' => PendingTwoFactorChallenge::credentialFingerprint($agent),
        ],
    ])->get(route('two-factor.challenge'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TwoFactorChallengeController::SESSION_KEY);
});

test('two-factor challenge guesses are rate limited', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    giveAgentTwoFactor($agent);
    $pending = [
        'user_id' => $agent->id,
        'started_at' => now()->timestamp,
        'remember' => false,
        'credential_fingerprint' => PendingTwoFactorChallenge::credentialFingerprint($agent),
    ];

    foreach (range(1, 5) as $attempt) {
        $this->withSession([TwoFactorChallengeController::SESSION_KEY => $pending])
            ->post(route('two-factor.challenge.store'), ['one_time_code' => '000000'])
            ->assertSessionHasErrors('one_time_code');
    }

    $this->withSession([TwoFactorChallengeController::SESSION_KEY => $pending])
        ->post(route('two-factor.challenge.store'), ['one_time_code' => '000000'])
        ->assertTooManyRequests();
});

test('changing a password revokes an unfinished two-factor challenge', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'agent@example.com',
        'password' => Hash::make('old-password'),
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'old-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $agent->forceFill(['password' => Hash::make('new-password')])->save();

    $this->post(route('two-factor.challenge.store'), [
        'one_time_code' => app(Google2FA::class)->getCurrentOtp($credential['secret']),
    ])
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TwoFactorChallengeController::SESSION_KEY);

    $this->assertGuest();
});

test('a password change revokes a newly issued authenticated session', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'agent@example.com',
        'password' => Hash::make('old-password'),
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'old-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $this->post(route('two-factor.challenge.store'), [
        'one_time_code' => app(Google2FA::class)->getCurrentOtp($credential['secret']),
    ])->assertRedirect(route('dashboard'));

    DB::table('users')->where('id', $agent->id)->update([
        'password' => Hash::make('new-password'),
    ]);
    Auth::forgetGuards();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a password change revokes a newly issued operator session', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'email' => 'operator@example.com',
        'password' => Hash::make('old-password'),
        'platform_role' => PlatformRole::Operator,
    ]);
    $credential = giveAgentTwoFactor($operator);

    $this->post(route('login.store'), [
        'email' => 'operator@example.com',
        'password' => 'old-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $this->post(route('two-factor.challenge.store'), [
        'one_time_code' => app(Google2FA::class)->getCurrentOtp($credential['secret']),
    ])->assertRedirect(route('dashboard'));

    DB::table('users')->where('id', $operator->id)->update([
        'password' => Hash::make('new-password'),
    ]);
    Auth::forgetGuards();

    $this->get(route('operator.dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('broadcast authorization validates the current password and two-factor policy', function (): void {
    $route = app('router')->getRoutes()->match(Request::create('/broadcasting/auth', 'POST'));

    expect($route->gatherMiddleware())
        ->toContain('auth')
        ->toContain('auth.session')
        ->toContain(EnsureAgentIsActive::class)
        ->toContain(EnsureTwoFactorPolicy::class)
        ->toContain(SerializeAgentBroadcastAuthorization::class);

    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'agent@example.com',
        'password' => Hash::make('old-password'),
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => 'agent@example.com',
        'password' => 'old-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $this->post(route('two-factor.challenge.store'), [
        'one_time_code' => app(Google2FA::class)->getCurrentOtp($credential['secret']),
    ])->assertRedirect(route('dashboard'));

    DB::table('users')->where('id', $agent->id)->update([
        'password' => Hash::make('new-password'),
    ]);
    Auth::forgetGuards();

    $this->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-conversations.WF-RESET',
    ])->assertRedirect(route('login'));

    $this->assertGuest();
});

test('broadcast authorization follows the account first writer lock order', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/SerializeAgentBroadcastAuthorization.php');
    $accountLock = strpos((string) $source, '$account = Account::query()->lockForUpdate()');
    $userLock = strpos((string) $source, '$lockedAgent = User::query()');

    expect($source)->not->toBeFalse()
        ->and($accountLock)->not->toBeFalse()
        ->and($userLock)->not->toBeFalse()
        ->and($accountLock)->toBeLessThan($userLock);
});

test('two factor disable follows the account first writer lock order', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Support/Auth/TwoFactorAuthentication.php');
    $disableSource = strstr((string) $source, 'public function disable');
    $accountLock = strpos((string) $disableSource, '$account = Account::query()');
    $userLock = strpos((string) $disableSource, '$locked = User::query()');

    expect($source)->not->toBeFalse()
        ->and($disableSource)->not->toBeFalse()
        ->and($accountLock)->not->toBeFalse()
        ->and($userLock)->not->toBeFalse()
        ->and($accountLock)->toBeLessThan($userLock);
});

test('recovery codes can be replaced with both proofs and two factor can be disabled', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('password'),
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->actingAs($agent)
        ->post(route('dashboard.profile.two-factor.recovery-codes.regenerate'), [
            'current_password' => 'password',
            'one_time_code' => $credential['recovery_code'],
        ])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionHas(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY);

    $newCodes = json_decode(Crypt::decryptString(
        session(AgentProfileTwoFactorController::RECOVERY_CODES_SESSION_KEY),
    ), true, flags: JSON_THROW_ON_ERROR);

    $this->actingAs($agent)
        ->delete(route('dashboard.profile.two-factor.disable'), [
            'current_password' => 'password',
            'one_time_code' => $newCodes[0],
        ])
        ->assertRedirect(route('dashboard.profile.show'));

    expect($agent->fresh()->hasTwoFactorAuthentication())->toBeFalse();
});

test('only an enrolled admin can require two factor for an account', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->put(route('dashboard.account.security.update'), ['requires_two_factor' => '1'])
        ->assertSessionHasErrors('requires_two_factor');

    giveAgentTwoFactor($admin);

    $this->actingAs($admin->fresh())
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertSee('1 active agent')
        ->assertDontSee('1 active agents');

    $this->actingAs($admin->fresh())
        ->put(route('dashboard.account.security.update'), ['requires_two_factor' => '1'])
        ->assertRedirect(route('dashboard.account.security.show'));

    expect($account->fresh()->requires_two_factor)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'account.two_factor_policy_updated')->value('metadata'))
        ->toMatchArray(['required' => true]);
});

test('a plain agent cannot manage the account security policy', function (): void {
    $agent = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->get(route('dashboard.account.security.show'))
        ->assertForbidden();

    $this->actingAs($agent)
        ->put(route('dashboard.account.security.update'), ['requires_two_factor' => '1'])
        ->assertForbidden();
});

test('a stale admin session cannot change the account security policy', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin);
    DB::table('users')->where('id', $admin->id)->update([
        'account_role' => AccountRole::Agent->value,
    ]);

    $this->put(route('dashboard.account.security.update'), ['requires_two_factor' => '1'])
        ->assertForbidden();

    expect($account->fresh()->requires_two_factor)->toBeFalse();
});

test('a stale password credential cannot change the account security policy', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    giveAgentTwoFactor($admin);

    $this->actingAs($admin);
    DB::table('users')->where('id', $admin->id)->update([
        'password' => Hash::make('replacement-password'),
    ]);

    $this->put(route('dashboard.account.security.update'), ['requires_two_factor' => '1'])
        ->assertForbidden();

    expect($account->fresh()->requires_two_factor)->toBeFalse();
});

test('the account requirement fences unenrolled sessions including operator pages', function (): void {
    $account = Account::factory()->create(['requires_two_factor' => true]);
    $agent = User::factory()->for($account)->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionHas('status', 'two_factor.policy.enrol_required');

    $this->actingAs($agent)
        ->get(route('operator.dashboard'))
        ->assertRedirect(route('dashboard.profile.show'));

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Required by your account');

    $this->actingAs($agent)
        ->post(route('logout'))
        ->assertRedirect(route('login'));
});

test('the account requirement prevents two factor from being disabled', function (): void {
    $account = Account::factory()->create(['requires_two_factor' => true]);
    $agent = User::factory()->for($account)->create([
        'password' => Hash::make('password'),
    ]);
    $credential = giveAgentTwoFactor($agent);

    $this->actingAs($agent)
        ->delete(route('dashboard.profile.two-factor.disable'), [
            'current_password' => 'password',
            'one_time_code' => $credential['recovery_code'],
        ])
        ->assertSessionHasErrorsIn('twoFactorDisable', 'current_password');

    expect($agent->fresh()->hasTwoFactorAuthentication())->toBeTrue();
});

test('the challenge speaks the install language to an agent who never chose one', function (): void {
    // The common case on any install that turned the dashboard language on after
    // it had agents: `locale` is null on every one of them. The challenge read
    // `config('app.locale')` as its fallback, and SetDashboardLocale has already
    // written that key by the time the controller runs -- so the fallback could
    // only ever read back FALLBACK, and the one screen between a German agent's
    // password and their German dashboard came out in English.
    config(['wayfindr.dashboard_locale' => 'de', 'app.locale' => 'de']);

    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('correct-password'),
        'locale' => null,
    ]);
    giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $this->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('Identität bestätigen')
        ->assertDontSee('Confirm it is you');
});

test('an agent who chose a language keeps it over the install default', function (): void {
    // The control for the test above. Reading the agent first is the whole point
    // of resolving through DashboardLanguage::for(), and a fix that simply used
    // the install default everywhere would pass the first test and fail this one.
    config(['wayfindr.dashboard_locale' => 'de', 'app.locale' => 'de']);

    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('correct-password'),
        'locale' => 'it',
    ]);
    giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-password',
    ])->assertRedirect(route('two-factor.challenge'));

    $this->get(route('two-factor.challenge'))
        ->assertOk()
        ->assertSee('<html lang="it">', false)
        ->assertDontSee('Identität bestätigen');
});

test('the expired message lands on the sign-in page in the page own language', function (): void {
    // The challenge translates for the AGENT; the sign-in page it redirects to is
    // English. Flashing a translated sentence put one German line inside an
    // `<html lang="en">` document, which a screen reader pronounces with English
    // phonetics. The key travels instead, and the destination translates it.
    config(['wayfindr.dashboard_locale' => 'de', 'app.locale' => 'de']);

    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('correct-password'),
        'locale' => 'de',
    ]);
    giveAgentTwoFactor($agent);

    $this->post(route('login.store'), [
        'email' => $agent->email,
        'password' => 'correct-password',
    ])->assertRedirect(route('two-factor.challenge'));

    // The documented way a pending challenge expires: the credential it was
    // opened against changes underneath it.
    $agent->forceFill(['password' => Hash::make('a-different-password')])->save();

    $this->followingRedirects()
        ->post(route('two-factor.challenge.store'), ['one_time_code' => '123456'])
        ->assertOk()
        ->assertSee('That sign-in attempt expired. Please start again.')
        ->assertDontSee('Dieser Anmeldeversuch ist abgelaufen.')
        ->assertDontSee('two_factor.challenge.expired');
});

test('the German and Italian challenge address the agent formally, and name the field', function (): void {
    // Every other string in the challenge block uses Sie and Lei. `expired` alone
    // said "Bitte beginne erneut" and "Ricomincia" -- the du and tu imperatives --
    // and the register linter does not see a bare imperative with no pronoun in it.
    $de = require lang_path('de/two_factor.php');
    $it = require lang_path('it/two_factor.php');

    expect($de['challenge']['expired'])->toContain('beginnen Sie')
        ->and($de['challenge']['expired'])->not->toContain('beginne erneut')
        ->and($it['challenge']['expired'])->toContain('Ricominci.')
        ->and($it['challenge']['expired'])->not->toContain('Ricomincia');

    // `one_time_code` is the only field this page validates, and neither
    // catalogue named it -- so a German agent pasting a recovery code with its
    // dashes was told "one time code darf hoechstens 32 Zeichen lang sein".
    $deAttributes = (require lang_path('de/validation.php'))['attributes'];
    $itAttributes = (require lang_path('it/validation.php'))['attributes'];

    expect($deAttributes)->toHaveKey('one_time_code')
        ->and($itAttributes)->toHaveKey('one_time_code');
});
