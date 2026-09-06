<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\ProactiveMessageDelivery;
use App\Support\ProactiveMessages\ProactiveMessageDeliveryGate;
use App\Support\WidgetSiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProactiveMessageController extends Controller
{
    public function authorizeDisplay(
        Request $request,
        string $rulePublicId,
        ProactiveMessageDeliveryGate $deliveries,
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'claim_key' => ['required', 'string', 'max:128'],
        ]);
        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);
        $delivery = $deliveries->claim(
            $site,
            $rulePublicId,
            $validated['anonymous_id'],
            $validated['claim_key'],
        );

        if (! $delivery instanceof ProactiveMessageDelivery) {
            return response()->json(['data' => ['authorized' => false]]);
        }

        return response()->json([
            'data' => [
                'authorized' => true,
                'delivery_id' => $delivery->public_id,
                'message' => $delivery->message,
                'expires_at' => $delivery->expires_at->toJSON(),
            ],
        ], $delivery->wasRecentlyCreated ? 201 : 200);
    }

    public function recordOutcome(
        Request $request,
        string $deliveryPublicId,
        ProactiveMessageDeliveryGate $deliveries,
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'outcome' => ['required', 'string', Rule::in(['shown', 'engaged', 'dismissed'])],
        ]);
        $site = WidgetSiteResolver::resolveOrFail($validated['site_public_key']);
        $delivery = $deliveries->recordOutcome(
            $site,
            $deliveryPublicId,
            $validated['anonymous_id'],
            $validated['outcome'],
        );

        abort_unless($delivery instanceof ProactiveMessageDelivery, 404);

        return response()->json(['data' => ['recorded' => true]], 202);
    }
}
