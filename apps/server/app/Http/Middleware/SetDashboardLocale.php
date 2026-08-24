<?php

namespace App\Http\Middleware;

use App\Support\DashboardLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render the dashboard in the signed-in agent's own language.
 *
 * Deliberately not driven by `Accept-Language`. An agent's browser is often
 * configured by whoever set the machine up, and a support tool people work in
 * all day should change language when they say so and not before -- guessing
 * from a header would mean the dashboard silently changing under an agent who
 * never asked.
 */
class SetDashboardLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Scoped to surfaces that have actually been extracted -- see
        // `DashboardLanguage::EXTRACTED_ROUTES` for why that is the locale
        // rather than only the `lang` attribute.
        App::setLocale(DashboardLanguage::forRequest(
            $request->user(),
            $request->route()?->getName(),
        ));

        return $next($request);
    }
}
