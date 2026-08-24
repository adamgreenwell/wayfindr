<?php

namespace App\Http\Middleware;

use App\Support\DashboardLanguage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
            $this->rendersBackTo($request),
        ));

        return $next($request);
    }

    /**
     * The route a write will render its answer on, if it redirects back.
     *
     * A validation failure never renders the endpoint -- it renders the page
     * the agent submitted from, which for a linked-ticket action is the
     * conversation panel rather than the ticket page that owns the controller.
     *
     * Reads are excluded: a GET renders itself, and its own route already
     * decides. The referer is only ever used to pick a language, so a wrong or
     * forged one costs nothing.
     */
    private function rendersBackTo(Request $request): ?string
    {
        if ($request->isMethodSafe()) {
            return null;
        }

        $previous = $request->headers->get('referer');

        if (! is_string($previous) || $previous === '') {
            return null;
        }

        // Same origin only, so an external referer cannot choose the language.
        if (! str_starts_with($previous, $request->getSchemeAndHttpHost())) {
            return null;
        }

        try {
            return Route::getRoutes()
                ->match(Request::create($previous))
                ->getName();
        } catch (Throwable) {
            // An unroutable referer simply does not answer the question.
            return null;
        }
    }
}
