<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\Oidc\OidcSignInRecorder;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Auth\TwoFactorAuthentication;
use App\Support\DashboardLanguage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'auth.two_factor_challenge';

    public const LIFETIME_SECONDS = 300;

    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return $this->expired($request);
        }

        $this->useUserLocale($user);

        return view('auth.two-factor-challenge');
    }

    public function store(
        Request $request,
        TwoFactorAuthentication $twoFactor,
        OidcSignInRecorder $oidcSignIns,
    ): RedirectResponse {
        // The password credential is revalidated under the same user-row lock
        // as factor consumption below, so a concurrent reset cannot pass in
        // the gap between two independent reads.
        $user = $this->pendingUser($request, checkCredential: false);

        if (! $user) {
            return $this->expired($request);
        }

        $this->useUserLocale($user);

        $validated = $request->validate([
            'one_time_code' => ['required', 'string', 'max:32'],
        ]);

        $pending = $request->session()->get(self::SESSION_KEY);
        $verified = $twoFactor->verifyChallenge(
            $user,
            $validated['one_time_code'],
            $pending['credential_fingerprint'],
            $pending,
        );

        if ($verified === null) {
            return $this->expired($request);
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'one_time_code' => __('two_factor.challenge.invalid'),
            ]);
        }

        if (! $oidcSignIns->complete($user, $pending)) {
            return $this->expired($request);
        }

        $pending = $request->session()->pull(self::SESSION_KEY);
        Auth::guard('web')->login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function pendingUser(Request $request, bool $checkCredential = true): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)
            || ! is_numeric($pending['user_id'] ?? null)
            || ! is_numeric($pending['started_at'] ?? null)
            || ! is_string($pending['credential_fingerprint'] ?? null)
            || now()->timestamp - (int) $pending['started_at'] > self::LIFETIME_SECONDS) {
            return null;
        }

        $user = User::query()->find((int) $pending['user_id']);

        return $user
            && (! $checkCredential || hash_equals(
                $pending['credential_fingerprint'],
                PendingTwoFactorChallenge::credentialFingerprint($user),
            ))
            && PendingTwoFactorChallenge::federatedCredentialIsCurrent($user, $pending)
            && ! $user->isDeactivated()
            && $user->hasTwoFactorAuthentication()
            ? $user
            : null;
    }

    private function expired(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('login')
            ->withErrors(['email' => __('two_factor.challenge.expired')]);
    }

    private function useUserLocale(User $user): void
    {
        $locale = DashboardLanguage::normalise($user->locale) ?? config('app.locale', DashboardLanguage::FALLBACK);
        App::setLocale($locale);
    }
}
