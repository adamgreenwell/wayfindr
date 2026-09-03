<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTwoFactorPolicy
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user?->account?->requires_two_factor || $user->hasTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs(
            'dashboard.profile.show',
            'dashboard.profile.two-factor.start',
            'dashboard.profile.two-factor.confirm',
            'dashboard.profile.two-factor.cancel',
            'logout',
        )) {
            return $next($request);
        }

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.policy.enrol_required');
    }
}
