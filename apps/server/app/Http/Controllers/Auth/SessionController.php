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

        // A remembered session is issued only after the second factor. Doing
        // it here would leave a persistent login cookie behind while the user
        // is still on the challenge screen.
        if (! Auth::attempt($credentials, false)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

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
}
