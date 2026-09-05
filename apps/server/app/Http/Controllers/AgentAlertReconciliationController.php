<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Support\AgentAlertPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/** Catch a dashboard tab up on durable alerts missed while its socket connected. */
final class AgentAlertReconciliationController extends Controller
{
    private const MAX_ALERTS = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $agent = $request->user();

        abort_unless(
            $agent->account_id !== null
            && ! $agent->isDeactivated()
            && $agent->hasAccountPermission(AccountPermission::ViewAlerts),
            403,
        );

        $validated = $request->validate([
            'since' => ['required', 'date'],
        ]);
        $since = CarbonImmutable::parse($validated['since'])->startOfSecond();
        // Inclusive, second-aligned boundaries are deliberate. Some database
        // engines store these timestamps only to the second; overlapping the
        // boundary and deduplicating by payload version closes that gap.
        $through = CarbonImmutable::now()->startOfSecond();
        $visible = $agent->notifications()
            ->where('updated_at', '>=', $since)
            ->where('updated_at', '<=', $through->endOfSecond())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->values();
        $truncated = $visible->count() > self::MAX_ALERTS;

        return response()->json([
            'data' => [
                'alerts' => $visible
                    ->take(self::MAX_ALERTS)
                    ->map(fn (DatabaseNotification $notification): array => AgentAlertPayload::for($notification))
                    ->values()
                    ->all(),
                'truncated' => $truncated,
                'watermark' => $through->toJSON(),
            ],
        ]);
    }
}
