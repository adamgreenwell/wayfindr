<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Rules\DecodableCursor;
use App\Support\Api\ApiScope;
use App\Support\Api\V1\Payload;
use App\Support\DatabaseKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tickets, read-only (ADR 0018).
 */
class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:open,pending,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', new DecodableCursor],
        ]);

        $tickets = Ticket::query()
            // Account AND site. The account filter is redundant while site ids
            // derive from it, and it stays because the day somebody changes how
            // sites are scoped is the day redundancy earns its keep.
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->when(isset($validated['site_id']), fn ($query) => $query->whereIn(
                'site_id',
                // Narrows only, never widens.
                $scope->includesSite((int) $validated['site_id']) ? [(int) $validated['site_id']] : [],
            ))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($tickets, Payload::ticket(...)));
    }

    /**
     * The id arrives RAW, not coerced to `int`.
     *
     * The route constrains shape and not range, so a thirty-digit id is
     * accepted -- and PHP cannot coerce that into an `int` parameter, so the
     * request dies with a TypeError before the method body runs. A 500 where
     * the contract documents 404.
     */
    public function show(Request $request, string $ticket): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        // An id too large to be a key cannot match one, so it is treated exactly
        // like an id that is not there.
        abort_unless(DatabaseKey::isValid($ticket), 404);

        $found = Ticket::query()
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->whereKey($ticket)
            ->firstOrFail();

        return response()->json(['data' => Payload::ticket($found)]);
    }
}
