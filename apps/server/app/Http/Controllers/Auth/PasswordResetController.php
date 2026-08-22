<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\FirstRunState;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Recovering an account without an operator.
 *
 * Before this, an agent who forgot their password needed someone with
 * production shell access to run an artisan command for them — exactly the
 * routine privileged action the break-glass and audit work exists to remove.
 */
class PasswordResetController extends Controller
{
    public function create(FirstRunState $firstRunState): View|RedirectResponse
    {
        if ($firstRunState->needsSetup()) {
            return redirect()->route('setup.create');
        }

        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(['email' => $validated['email']]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit('password_reset.requested', $validated['email']);
        }

        // The same answer either way. The login page is public and the account
        // model is multi-tenant, so a form that confirms which addresses are
        // real hands over the agent roster of every site on the install.
        return back()->with('status', $this->sentMessage());
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Deliberately one message for an expired token, a wrong token and
            // an unknown address: distinguishing them tells an attacker which
            // of the three they got right.
            throw ValidationException::withMessages([
                'email' => 'That reset link is no longer valid. Request a new one.',
            ]);
        }

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user !== null) {
            $this->endExistingSessions($user);
            $this->audit('password_reset.completed', $validated['email'], $user);
        }

        return redirect()
            ->route('login')
            ->with('status', 'Your password has been reset. Sign in with it now.');
    }

    /**
     * End whatever access existed before the reset.
     *
     * A reset that leaves the old sessions alive is not a recovery, it is a
     * second key cut for whoever already had one.
     *
     * Auth::logoutOtherDevices() is not the tool here: it works on the guard's
     * CURRENT session, and nobody is authenticated during a reset, so it is a
     * no-op. The password broker already rotates remember_token, which kills
     * remember-me cookies; what survives that is the server-side session rows,
     * so those are deleted directly.
     */
    private function endExistingSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            // Other stores key sessions by id alone, with nothing to select a
            // user's rows by. The token rotation above still applies.
            return;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    private function sentMessage(): string
    {
        return 'If that address belongs to an agent, a reset link is on its way. '
            .'Check spam if it does not arrive, and ask your operator whether outbound mail is configured.';
    }

    private function audit(string $action, string $email, ?User $user = null): void
    {
        $user ??= User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        // The actor is the account itself: nobody is authenticated during a
        // reset, and recording an anonymous request against the account is
        // what makes an unexpected one visible to an admin.
        AuditEvent::query()->create([
            'account_id' => $user->account_id,
            'actor_type' => null,
            'actor_id' => null,
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'action' => $action,
            'metadata' => [],
            'occurred_at' => now(),
        ]);
    }
}
