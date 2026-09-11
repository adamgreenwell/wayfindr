<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AgentPushSubscription;
use App\Models\User;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\FirstRunState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SessionController extends Controller
{
    public function create(FirstRunState $firstRunState): View|RedirectResponse
    {
        if ($firstRunState->needsSetup()) {
            return redirect()->route('setup.create');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->refuseIfTooManyFailures($request);

        // A remembered session is issued only after the second factor. Doing
        // it here would leave a persistent login cookie behind while the user
        // is still on the challenge screen.
        if (! Auth::attempt($credentials, false)) {
            $this->recordFailure($request);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $this->forgetFailures($request);

        if ($request->user()?->isDeactivated()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'This agent account is deactivated.',
            ]);
        }

        $agent = $request->user();
        $request->session()->regenerate();

        // Seed Laravel's authenticated-session credential version before the
        // second-factor pause. If a reset lands after challenge verification
        // but before this response persists its login, auth.session rejects
        // the new session on its first protected request.
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Auth::guard('web')->hashPasswordForCookie((string) $agent?->getAuthPassword()),
        );

        if ($agent?->hasTwoFactorAuthentication()) {
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'user_id' => $agent->getKey(),
                'remember' => $request->boolean('remember'),
                'started_at' => now()->timestamp,
                'credential_fingerprint' => PendingTwoFactorChallenge::credentialFingerprint($agent),
            ]);

            // Clear only this provisional session. A normal logout rotates the
            // user's remember token and would let somebody who knows only the
            // password invalidate remembered sessions on every other device
            // without ever completing the second factor.
            Auth::guard('web')->logoutCurrentDevice();

            return redirect()->route('two-factor.challenge');
        }

        if ($agent?->account?->requires_two_factor) {
            return redirect()->route('dashboard.profile.show');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $agent = $request->user();
        $endpoint = $request->input('push_subscription_endpoint');

        if ($agent instanceof User
            && is_string($endpoint)
            && strlen($endpoint) <= AgentPushSubscription::ENDPOINT_MAX_LENGTH
            && filter_var($endpoint, FILTER_VALIDATE_URL) !== false
            && parse_url($endpoint, PHP_URL_SCHEME) === 'https') {
            try {
                AgentPushSubscription::revokeEndpointFor($agent, $endpoint);
            } catch (Throwable) {
                // Subscription cleanup is best effort. Never trap an agent in
                // a session merely because this optional channel is unhealthy.
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Sign-in throttling: failures only, and keyed to one account from one
     * source.
     *
     * Three designs preceded this one, each defeated by a population it had
     * not considered, so the reasoning is recorded rather than the result.
     *
     * The `throttle` middleware counts every REQUEST, so a shift arriving
     * behind one office NAT spends the bucket between them and the last ones
     * in are refused while typing the correct password. Counting only
     * failures fixes that.
     *
     * A bucket keyed on the ACCOUNT alone is global across every source, so
     * anyone who knows an agent's address can exhaust it deliberately and that
     * agent's correct password is refused. A lockout wearing a rate limit's
     * clothes.
     *
     * And a bucket keyed on the SOURCE alone is worse than it looks on a
     * shared address: one compromised machine on the office network fills it,
     * and every colleague behind that NAT is refused for the window, correct
     * password or not. The check has to run before `Auth::attempt()` -- there
     * is no way to know a password was right without checking it -- so a full
     * bucket can never be cleared by the very request that would have cleared
     * it. That is why there is no per-source bucket here at all.
     *
     * What remains is the narrowest thing that still bounds guessing: ten
     * failures against one address from one source. A colleague on the same
     * NAT is unaffected because their address differs; a targeted agent can
     * always sign in from somewhere else; and an attacker gets ten tries per
     * account per source. Credential stuffing across many accounts from one
     * source is deliberately NOT bounded here -- that is a job for the network
     * and for monitoring, and pretending a login throttle does it is how the
     * previous three versions each locked out someone real.
     *
     * The key is hashed: `cache.key` is a 255-character column and CACHE_STORE
     * defaults to `database`, so a valid-but-long address composed raw can
     * exceed it, and the counter is written before the response returns --
     * PostgreSQL would reject the insert and the agent would get a 500 instead
     * of a login. sha256 rather than a fast hash, because a collision merges
     * two agents' buckets and an attacker could arrange one.
     */
    private const FAILURES_ALLOWED = 10;

    private const DECAY_SECONDS = 900;

    private function refuseIfTooManyFailures(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (! RateLimiter::tooManyAttempts($key, self::FAILURES_ALLOWED)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => RateLimiter::availableIn($key),
                'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
            ]),
        ]);
    }

    private function recordFailure(Request $request): void
    {
        RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);
    }

    private function forgetFailures(Request $request): void
    {
        RateLimiter::clear($this->throttleKey($request));
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.hash(
            'sha256',
            Str::lower((string) $request->input('email')).'|'.(string) $request->ip()
        );
    }
}
