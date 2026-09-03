<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Auth\TwoFactorAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

test('an agent can enrol a locally generated TOTP credential', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $twoFactor = app(TwoFactorAuthentication::class);
    $secret = $twoFactor->generateSecret();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $recoveryCodes = $twoFactor->confirm($agent, $secret, $code);

    expect($secret)->toHaveLength(32)
        ->and($recoveryCodes)->toHaveCount(TwoFactorAuthentication::RECOVERY_CODE_COUNT)
        ->and($agent->hasTwoFactorAuthentication())->toBeTrue()
        ->and($agent->two_factor_last_used_timestep)->toBeInt();

    $raw = DB::table('users')->where('id', $agent->id)->first();
    expect($raw->two_factor_secret)->not->toBe($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($recoveryCodes[0]);

    $storedHashes = $agent->two_factor_recovery_codes;
    expect($storedHashes)->toHaveCount(TwoFactorAuthentication::RECOVERY_CODE_COUNT)
        ->and(Hash::check(str_replace('-', '', $recoveryCodes[0]), $storedHashes[0]))->toBeTrue();

    expect(AuditEvent::query()->where('action', 'agent.two_factor_enabled')->count())->toBe(1);
});

test('TOTP timesteps cannot be replayed', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $twoFactor = app(TwoFactorAuthentication::class);
    $secret = $twoFactor->generateSecret();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    expect($twoFactor->confirm($agent, $secret, $code))->not->toBeNull()
        ->and($twoFactor->verify($agent, $code))->toBeFalse();
});

test('a recovery code is consumed exactly once', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $twoFactor = app(TwoFactorAuthentication::class);
    $secret = $twoFactor->generateSecret();
    $codes = $twoFactor->confirm($agent, $secret, app(Google2FA::class)->getCurrentOtp($secret));

    expect($twoFactor->verify($agent, strtolower($codes[0])))->toBeTrue()
        ->and($agent->two_factor_recovery_codes)->toHaveCount(TwoFactorAuthentication::RECOVERY_CODE_COUNT - 1)
        ->and($twoFactor->verify($agent, $codes[0]))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'agent.two_factor_recovery_code_used')->count())->toBe(1);
});

test('recovery codes can be replaced and two factor can be disabled', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $twoFactor = app(TwoFactorAuthentication::class);
    $secret = $twoFactor->generateSecret();
    $oldCodes = $twoFactor->confirm($agent, $secret, app(Google2FA::class)->getCurrentOtp($secret));

    $newCodes = $twoFactor->regenerateRecoveryCodes($agent);

    expect($newCodes)->toHaveCount(TwoFactorAuthentication::RECOVERY_CODE_COUNT)
        ->and($newCodes)->not->toBe($oldCodes)
        ->and($twoFactor->verify($agent, $oldCodes[0]))->toBeFalse();

    $twoFactor->disable($agent);

    expect($agent->hasTwoFactorAuthentication())->toBeFalse()
        ->and($agent->two_factor_recovery_codes)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'agent.two_factor_recovery_codes_regenerated')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.two_factor_disabled')->count())->toBe(1);
});

test('the QR code is rendered locally as a PNG data URI', function (): void {
    $agent = User::factory()->for(Account::factory()->state(['name' => 'Acme Support']))->create([
        'email' => 'agent@example.com',
    ]);
    $twoFactor = app(TwoFactorAuthentication::class);
    $secret = $twoFactor->generateSecret();
    $dataUri = $twoFactor->qrCodeDataUri($agent, $secret);

    expect($twoFactor->provisioningUri($agent, $secret))
        ->toStartWith('otpauth://totp/Wayfindr%20-%20Acme%20Support:agent%40example.com')
        ->and($dataUri)->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true))
        ->toStartWith("\x89PNG\r\n\x1a\n");
});
