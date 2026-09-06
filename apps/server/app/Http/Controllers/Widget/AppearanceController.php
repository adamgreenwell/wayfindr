<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Support\Sites\SitePresenceReporting;
use App\Support\Sites\WidgetAppearance;
use App\Support\Sites\WidgetLanguage;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How this site's widget should look, before anybody has done anything.
 *
 * The launcher is drawn at init, and bootstrap does not run until the panel is
 * opened -- so a launcher configured for the left corner appeared on the right
 * on every fresh load, which is worst in exactly the case the setting exists
 * for: the right corner already covered by something else, and the visitor
 * unable to reach the launcher to open it.
 *
 * Deliberately NOT bootstrap. That creates or touches a visitor row, and a
 * record written because somebody loaded a page is precisely what ADR 0016
 * declined. This reads configuration and writes nothing.
 *
 * Which is also why presence configuration is served from here (ADR 0019 §1):
 * bootstrap marks a visitor as having made contact, so asking IT whether to
 * watch people who have not made contact answers the question by destroying
 * it.
 */
class AppearanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);
        $presence = SitePresenceReporting::for($site);

        // Presence rides along here for the same reason the launcher position
        // does, and it is the same sentence twice: bootstrap does not run until
        // the panel is opened, and the visitors this feature exists to see are
        // the ones who never open it. Config that must reach the widget before
        // any contact has to come from an endpoint that writes nothing.
        //
        // A second endpoint would have been a second request on every page view
        // of every install, including the ones with presence off.
        return response()->json([
            'data' => [
                'appearance' => WidgetAppearance::for($site)->toPayload(),
                'presence' => $presence->toPayload(),
                // Published only while presence is active. The browser keeps
                // page/referrer/visit matching local, then asks the server for
                // a fresh authorization before it renders the winning rule.
                'proactive_messages' => $presence->enabled
                    ? $site->proactiveMessageRules()
                        ->enabled()
                        ->inEvaluationOrder()
                        ->get()
                        ->map->toWidgetPayload()
                        ->all()
                    : [],
                // The site's configured language, for the same reason as the
                // rest of this response. It only matters when neither the host
                // page nor the browser has expressed a preference -- and in
                // exactly that case a silent visitor on a German site was shown
                // an English privacy notice, because the site default arrived
                // with bootstrap and a silent visitor never bootstraps. A
                // disclosure in a language somebody does not read is not one.
                'locale' => WidgetLanguage::for($site),
            ],
        ]);
    }
}
