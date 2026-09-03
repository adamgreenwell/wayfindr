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

            $matchedTimestep = $this->totp->verifyKeyNewer($secret, $code, 0, 1);

            if (! is_int($matchedTimestep)) {
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
                // google2fa returns the exact matching timestep, including
                // when the accepted code is in the adjacent clock window.
                'two_factor_last_used_timestep' => $matchedTimestep,
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
            $verified = $this->verifyLocked($locked, $code);

            if ($verified) {
                $user->refresh();
            }

            return $verified;
        });
    }

    /**
     * @return list<string>|null
     */
    public function regenerateRecoveryCodes(User $user, string $proof): ?array
    {
        return DB::transaction(function () use ($user, $proof): ?array {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $this->verifyLocked($locked, $proof)) {
                return null;
            }

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

    public function disable(User $user, string $proof): bool
    {
        return DB::transaction(function () use ($user, $proof): bool {
            // User first, then account: the policy writer takes the same order.
            // This keeps a concurrent policy enable from racing a disable
            // without serialising every account member's sign-in on one row.
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $account = Account::query()->lockForUpdate()->findOrFail($locked->account_id);

            if ($account->requires_two_factor) {
                return false;
            }

            if (! $this->verifyLocked($locked, $proof)) {
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
            fn (): string => implode('-', str_split(strtoupper(bin2hex(random_bytes(8))), 4)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    private function verifyLocked(User $locked, string $code): bool
    {
        if (! $locked->hasTwoFactorAuthentication()) {
            return false;
        }

        $normalisedCode = $this->normaliseRecoveryCode($code);

        if (preg_match('/^\d{6}$/', $normalisedCode) === 1) {
            $matchedTimestep = $this->totp->verifyKeyNewer(
                $locked->two_factor_secret,
                $normalisedCode,
                $locked->two_factor_last_used_timestep ?? 0,
                1,
            );

            if (! is_int($matchedTimestep)) {
                return false;
            }

            // Persist the precise accepted timestep returned by google2fa so
            // an adjacent-window code cannot be replayed as the clock catches up.
            $locked->forceFill(['two_factor_last_used_timestep' => $matchedTimestep])->save();

            return true;
        }

        $recoveryCodes = $locked->two_factor_recovery_codes ?? [];
        $matchingIndex = null;

        // Always check every remaining hash so the response time does not
        // reveal which recovery-code slot matched.
        foreach ($recoveryCodes as $index => $hash) {
            if (is_string($hash) && Hash::check($normalisedCode, $hash)) {
                $matchingIndex ??= $index;
            }
        }

        if ($matchingIndex === null) {
            return false;
        }

        unset($recoveryCodes[$matchingIndex]);
        $locked->forceFill([
            'two_factor_recovery_codes' => array_values($recoveryCodes),
        ])->save();

        $this->audit($locked, 'agent.two_factor_recovery_code_used');

        return true;
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
