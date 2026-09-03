<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

final class TwoFactorAuthentication
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $totp) {}

    public function generateSecret(): string
    {
        // 32 Base32 characters represent the 160-bit secret used by the
        // interoperable SHA-1 TOTP profile.
        return $this->totp->generateSecretKey(32);
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->totp->getQRCodeUrl(
            'Wayfindr - '.$user->account()->value('name'),
            $user->email,
            $secret,
        );
    }

    public function qrCodeDataUri(User $user, string $secret): string
    {
        $png = (new Writer(new GDLibRenderer(280)))
            ->writeString($this->provisioningUri($user, $secret));

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * @return list<string>|null Plaintext codes, returned once at enrolment.
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        return DB::transaction(function () use ($user, $secret, $code): ?array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($locked->hasTwoFactorAuthentication()) {
                return null;
            }

            $timestep = $this->totp->verifyKeyNewer($secret, $code, 0, 1);

            if (! is_int($timestep)) {
                return null;
            }

            $recoveryCodes = $this->generateRecoveryCodes();

            $locked->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_recovery_codes' => array_map(
                    fn (string $recoveryCode): string => Hash::make($this->normaliseRecoveryCode($recoveryCode)),
                    $recoveryCodes,
                ),
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_timestep' => $timestep,
            ])->save();

            $this->audit($locked, 'agent.two_factor_enabled');
            $user->refresh();

            return $recoveryCodes;
        });
    }

    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $locked->hasTwoFactorAuthentication()) {
                return false;
            }

            $normalisedCode = $this->normaliseRecoveryCode($code);

            if (preg_match('/^\d{6}$/', $normalisedCode) === 1) {
                $timestep = $this->totp->verifyKeyNewer(
                    $locked->two_factor_secret,
                    $normalisedCode,
                    $locked->two_factor_last_used_timestep ?? 0,
                    1,
                );

                if (! is_int($timestep)) {
                    return false;
                }

                $locked->forceFill(['two_factor_last_used_timestep' => $timestep])->save();
                $user->refresh();

                return true;
            }

            $recoveryCodes = $locked->two_factor_recovery_codes ?? [];

            $matchingIndex = null;

            // Always check the complete, fixed-size set so the response time
            // does not reveal which recovery-code slot matched.
            foreach ($recoveryCodes as $index => $hash) {
                if (is_string($hash) && Hash::check($normalisedCode, $hash)) {
                    $matchingIndex ??= $index;
                }
            }

            if ($matchingIndex !== null) {
                unset($recoveryCodes[$matchingIndex]);
                $locked->forceFill([
                    'two_factor_recovery_codes' => array_values($recoveryCodes),
                ])->save();

                $this->audit($locked, 'agent.two_factor_recovery_code_used');
                $user->refresh();

                return true;
            }

            return false;
        });
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            abort_unless($locked->hasTwoFactorAuthentication(), 409);

            $recoveryCodes = $this->generateRecoveryCodes();
            $locked->forceFill([
                'two_factor_recovery_codes' => array_map(
                    fn (string $code): string => Hash::make($this->normaliseRecoveryCode($code)),
                    $recoveryCodes,
                ),
            ])->save();

            $this->audit($locked, 'agent.two_factor_recovery_codes_regenerated');
            $user->refresh();

            return $recoveryCodes;
        });
    }

    public function disable(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            // User first, then account: the policy writer takes the same order.
            // This keeps a concurrent policy enable from racing a disable
            // without serialising every account member's sign-in on one row.
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $account = Account::query()->lockForUpdate()->findOrFail($locked->account_id);

            if ($account->requires_two_factor) {
                return false;
            }

            if (! $locked->hasTwoFactorAuthentication()) {
                return false;
            }

            $locked->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_used_timestep' => null,
            ])->save();

            $this->audit($locked, 'agent.two_factor_disabled');
            $user->refresh();

            return true;
        });
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return array_map(
            fn (): string => implode('-', str_split(strtoupper(bin2hex(random_bytes(5))), 5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }

    private function audit(User $user, string $action): void
    {
        AuditEvent::query()->create([
            'account_id' => $user->account_id,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'action' => $action,
            'metadata' => [],
            'occurred_at' => now(),
        ]);
    }
}
