<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Support\Sites\WidgetAppearance;
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
 */
class AppearanceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
        ]);

        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);

        return response()->json([
            'data' => ['appearance' => WidgetAppearance::for($site)->toPayload()],
        ]);
    }
}
