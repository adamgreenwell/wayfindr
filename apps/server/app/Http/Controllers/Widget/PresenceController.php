<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Visitors\VisitorPresenceReport;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Somebody is on the site right now (ADR 0019).
 *
 * Deliberately NOT authenticated by a visitor token. A visitor who has never
 * made contact has no token, which is the entire population this exists for --
 * requiring one would mean it only reported people we already knew about, which
 * is the board that already existed.
 *
 * What that costs, and why it is acceptable: the site key is public, so anybody
 * can forge presence for a made-up anonymous id. That inflates a count on one
 * desk's board and does nothing else -- no data is read back, nothing is
 * exposed, and every value stored is either server-stamped or sanitised. The
 * rate limiter bounds the volume.
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, VisitorPresenceReport $presence): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        // Off unless the operator turned it on, checked before anything is
        // written. A site that has not opted in stores nothing at all, rather
        // than storing a row and declining to show it.
        $reporting = SitePresenceReporting::for($site);

        if (! $reporting->enabled) {
            return response()->json(['data' => ['reports' => false]], 200);
        }

        // A tester visitor is an agent looking at their own site. Recording
        // them means an agent watches themselves browse, which
        // `Site::latestVisitor()` already refuses for the same reason.
        if (str_starts_with($validated['anonymous_id'], 'tester-site-')) {
            return response()->json(['data' => ['reports' => false]], 200);
        }

        // Dropped here, not merely omitted by the widget. The endpoint is
        // public, so the widget being told not to send one is a request rather
        // than a guarantee -- an operator who turned page addresses off has to
        // get that whoever is calling.
        $pageUrl = $reporting->pageUrls ? ($validated['page_url'] ?? null) : null;

        $presence->record($site, $validated['anonymous_id'], $pageUrl);

        return response()->json(['data' => ['reports' => true]], 202);
    }
}
