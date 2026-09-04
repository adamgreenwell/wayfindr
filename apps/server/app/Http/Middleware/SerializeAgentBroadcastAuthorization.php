<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SerializeAgentBroadcastAuthorization
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $request->user();

        if (! $agent instanceof User) {
            throw new AuthenticationException;
        }

        return DB::transaction(function () use ($request, $next, $agent): Response {
            // Account then user matches every account-policy writer. Holding
            // both locks through the controller response keeps the signed
            // authorization linearizable with role changes, password resets,
            // deactivation, and account-wide required-2FA changes without an
            // inverse lock order that can deadlock those writes.
            $account = Account::query()->lockForUpdate()->find($agent->account_id);

            if (! $account instanceof Account) {
                abort(403);
            }

            $lockedAgent = User::query()
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->find($agent->id);

            if (! $lockedAgent instanceof User || $lockedAgent->isDeactivated()) {
                $this->logout($request);
            }

            if ($account->requires_two_factor && ! $lockedAgent->hasTwoFactorAuthentication()) {
                abort(403);
            }

            $this->assertCredentialIsCurrent($request, $lockedAgent);

            Auth::guard('web')->setUser($lockedAgent);
            $request->setUserResolver(fn (): User => $lockedAgent);

            return $next($request);
        });
    }

    private function assertCredentialIsCurrent(Request $request, User $agent): void
    {
        $storedHash = $request->session()->get('password_hash_'.Auth::getDefaultDriver());
        $passwordHash = (string) $agent->getAuthPassword();
        $cookieHash = Auth::guard('web')->hashPasswordForCookie($passwordHash);

        if (! is_string($storedHash)
            || (! hash_equals($cookieHash, $storedHash) && ! hash_equals($passwordHash, $storedHash))) {
            $this->logout($request);
        }
    }

    private function logout(Request $request): never
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->flush();

        throw new AuthenticationException;
    }
}
