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
