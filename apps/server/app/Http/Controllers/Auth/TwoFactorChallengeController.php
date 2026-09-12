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

        // The KEY, not the sentence. useUserLocale() has set this request to the
        // agent's language, so translating here produces German prose that then
        // renders inside the English sign-in page -- one German sentence in an
        // `<html lang="en">` document, which a screen reader pronounces with
        // English phonetics. The destination translates its own flashes.
        return redirect()
            ->route('login')
            ->withErrors(['email' => 'two_factor.challenge.expired']);
    }

    /**
     * The challenge cannot use the usual route-based resolution.
     *
     * This route is in the `guest` group and the provisional session has
     * already been logged out, so `$request->user()` is null here and
     * DashboardLanguage::forRequest() would resolve the install default and
     * discard whatever the agent themselves chose. The controller knows who is
     * being challenged -- the pending session carries their id -- so it sets the
     * locale from the user directly.
     *
     * What it must NOT do is re-derive that resolution by hand. The previous
     * expression fell back to `config('app.locale')`, and SetDashboardLocale has
     * already called App::setLocale() by the time this runs, which WRITES that
     * key. So the fallback could only ever read back the value the middleware
     * just put there -- FALLBACK, for an unlisted route -- and an agent who had
     * not chosen a language got English on a German install. That is the exact
     * trap DashboardLanguage::for() was written to avoid, and it says so in its
     * own comment.
     */
    private function useUserLocale(User $user): void
    {
        App::setLocale(DashboardLanguage::for($user));
    }
}
