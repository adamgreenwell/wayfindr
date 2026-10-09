<?php

declare(strict_types=1);

namespace App\Support\Updates;

use App\Models\Account;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Auth\TwoFactorAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** A recent proof belongs to this operator and this credential/MFA version. */
final class OperatorUpdateAuthorization
{
    public const SESSION_KEY = 'operator.updates.reauthenticated';

    public const LIFETIME_SECONDS = 300;

    public function confirm(Request $request, #[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $code): void
    {
        // A failed fresh proof must not leave an earlier proof usable.
        $request->session()->forget(self::SESSION_KEY);
        $proof = $this->run($request, function (User $operator) use ($password, $code): array {
            if (! Hash::check($password, (string) $operator->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => __('auth.password')]);
            }

            if ($operator->hasTwoFactorAuthentication()
                && ($code === null || app(TwoFactorAuthentication::class)->verifyChallenge(
                    $operator, $code, PendingTwoFactorChallenge::credentialFingerprint($operator),
                ) !== true)) {
                throw ValidationException::withMessages(['one_time_code' => __('two_factor.profile.invalid_code')]);
            }

            return ['user_id' => (int) $operator->getKey(), 'at' => now()->timestamp, 'fingerprint' => self::fingerprint($operator)];
        }, recent: false);

        $request->session()->put(self::SESSION_KEY, $proof);
    }

    /** Keep the authority row locked through the short helper admission call. */
    public function run(Request $request, Closure $action, bool $recent = true): mixed
    {
        return DB::transaction(function () use ($request, $action, $recent): mixed {
            $hint = User::query()->find($request->user()?->getAuthIdentifier());
            $account = $hint?->account_id === null ? null : Account::query()->lockForUpdate()->find($hint->account_id);
            $operator = User::query()->lockForUpdate()->find($request->user()?->getAuthIdentifier());
            if (! $operator?->isPlatformOperator() || $operator->isDeactivated()) {
                abort(response()->json(['schema' => 1, 'reason' => 'operator_required'], 403)->header('Cache-Control', 'no-store'));
            }

            if ($operator->account_id !== $hint?->account_id) {
                abort(response()->json(['schema' => 1, 'reason' => 'operator_required'], 403)->header('Cache-Control', 'no-store'));
            }
            if ($account?->requires_two_factor && ! $operator->hasTwoFactorAuthentication()) {
                abort(response()->json(['schema' => 1, 'reason' => 'two_factor_required'], 403)->header('Cache-Control', 'no-store'));
            }

            if ($recent && ! $this->current($request, $operator)) {
                $request->session()->forget(self::SESSION_KEY);
                abort(response()->json(['schema' => 1, 'reason' => 'reauthentication_required'], 428)->header('Cache-Control', 'no-store'));
            }

            return $action($operator);
        });
    }

    public static function fingerprint(User $operator): string
    {
        return hash_hmac('sha256', json_encode([
            PendingTwoFactorChallenge::credentialFingerprint($operator),
            $operator->getRawOriginal('two_factor_secret'),
            $operator->getRawOriginal('two_factor_confirmed_at'),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function current(Request $request, User $operator): bool
    {
        $proof = $request->session()->get(self::SESSION_KEY);

        return is_array($proof) && count($proof) === 3
            && ($proof['user_id'] ?? null) === (int) $operator->getKey()
            && is_int($proof['at'] ?? null) && $proof['at'] <= now()->timestamp
            && $proof['at'] > now()->timestamp - self::LIFETIME_SECONDS
            && is_string($proof['fingerprint'] ?? null)
            && hash_equals(self::fingerprint($operator), $proof['fingerprint']);
    }
}
