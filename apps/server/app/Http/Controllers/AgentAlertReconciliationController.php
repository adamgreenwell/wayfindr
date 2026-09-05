<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Rules\DecodableCursor;
use App\Support\AgentAlertPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/** Catch a dashboard tab up on durable alerts missed while its socket connected. */
final class AgentAlertReconciliationController extends Controller
{
    private const PAGE_SIZE = 50;

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
            'through' => ['nullable', 'required_with:cursor', 'date', 'after_or_equal:since'],
            'cursor' => ['nullable', 'string', new DecodableCursor([
                'updated_at' => 'timestamp',
                'id' => 'uuid',
            ])],
        ]);
        $since = CarbonImmutable::parse($validated['since'])->startOfSecond();
        // Inclusive, second-aligned boundaries are deliberate. Some database
        // engines store these timestamps only to the second; overlapping the
        // boundary and deduplicating by payload version closes that gap.
        // The first page freezes an upper boundary. Every later page carries it
        // back so new live alerts cannot reshuffle this walk while it is in
        // progress; the next reconciliation overlaps that boundary safely.
        $through = isset($validated['through'])
            ? CarbonImmutable::parse($validated['through'])->startOfSecond()
            : CarbonImmutable::now()->startOfSecond();
        $page = $agent->notifications()
            ->where('updated_at', '>=', $since)
            ->where('updated_at', '<=', $through->endOfSecond())
            ->orderBy('updated_at')
            ->orderBy('id')
            ->cursorPaginate(
                self::PAGE_SIZE,
                ['*'],
                'cursor',
                $validated['cursor'] ?? null,
            );
        $visible = collect($page->items())
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->values();
        $nextCursor = $page->nextCursor()?->encode();

        return response()->json([
            'data' => [
                'alerts' => $visible
                    ->map(fn (DatabaseNotification $notification): array => AgentAlertPayload::for($notification))
                    ->values()
                    ->all(),
                'next_cursor' => $nextCursor,
                'truncated' => $nextCursor !== null,
                'watermark' => $through->toJSON(),
            ],
        ]);
    }
}
