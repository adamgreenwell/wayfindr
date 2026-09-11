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
     * Sign-in throttling, counted on FAILURES only.
     *
     * This deliberately does not use the `throttle` middleware. That counts
     * every request, including the ones carrying a correct password -- so ten
     * agents arriving at shift start behind one office NAT would spend the
     * bucket between them and the eleventh would be refused while typing the
     * right password. A support desk cannot have its own network lock it out.
     *
     * Two keys, and neither is the address alone or the account alone. An
     * address-only bucket lets a distributed attacker grind one named agent.
     * An account-only bucket is global across every source, so anyone who
     * knows an agent's address could exhaust it on purpose and that agent's
     * correct password would be refused -- a lockout wearing a rate limit's
     * clothes.
     *
     * Both are hashed. `cache.key` is a 255-character column and CACHE_STORE
     * defaults to `database`, so a valid-but-long address composed raw into a
     * key can exceed it, and the write happens before the response returns:
     * PostgreSQL would reject the insert and the agent would get a 500 instead
     * of a login. sha256 rather than a fast hash, because a collision merges
     * two agents' buckets and an attacker could arrange one.
     */
    private const FAILURES_PER_SOURCE = 20;

    private const FAILURES_PER_SOURCE_AND_ACCOUNT = 10;

    private const DECAY_SECONDS = 900;

    private function refuseIfTooManyFailures(Request $request): void
    {
        foreach ($this->throttleKeys($request) as $key => $allowed) {
            if (RateLimiter::tooManyAttempts($key, $allowed)) {
                throw ValidationException::withMessages([
                    'email' => __('auth.throttle', [
                        'seconds' => RateLimiter::availableIn($key),
                        'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                    ]),
                ]);
            }
        }
    }

    private function recordFailure(Request $request): void
    {
        foreach (array_keys($this->throttleKeys($request)) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    private function forgetFailures(Request $request): void
    {
        foreach (array_keys($this->throttleKeys($request)) as $key) {
            RateLimiter::clear($key);
        }
    }

    /** @return array<string, int> key => attempts allowed */
    private function throttleKeys(Request $request): array
    {
        $email = Str::lower((string) $request->input('email'));
        $source = (string) $request->ip();

        return [
            'login-source:'.hash('sha256', $source) => self::FAILURES_PER_SOURCE,
            'login-source-account:'.hash('sha256', $email.'|'.$source) => self::FAILURES_PER_SOURCE_AND_ACCOUNT,
        ];
    }
}
