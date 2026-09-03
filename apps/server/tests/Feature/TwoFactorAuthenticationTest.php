<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Http\Controllers\AgentProfileTwoFactorController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
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
        ->assertSessionHas(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY);

    $encryptedSecret = session(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY);
    $secret = Crypt::decryptString($encryptedSecret);

    expect($encryptedSecret)->not->toContain($secret);

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('data:image/png;base64,', false)
        ->assertSee($secret);

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.two-factor.confirm'), [
            'one_time_code' => $code,
        ])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionMissing(AgentProfileTwoFactorController::ENROLMENT_SESSION_KEY)
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
        ],
    ])->get(route('two-factor.challenge'))
        ->assertRedirect(route('login'));

    $agent->update(['deactivated_at' => now()]);

    $this->withSession([
        TwoFactorChallengeController::SESSION_KEY => [
            'user_id' => $agent->id,
            'started_at' => now()->timestamp,
            'remember' => false,
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
