<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\Api\ApiScope;
use App\Support\Api\V1\Payload;
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
            'cursor' => ['nullable', 'string'],
        ]);

        $siteIds = $scope->siteIds();

        if (isset($validated['site_id'])) {
            $siteIds = in_array($validated['site_id'], $siteIds, true) ? [$validated['site_id']] : [];
        }

        $tickets = Ticket::query()
            // Account AND site. The account filter is redundant while site ids
            // derive from it, and it stays because the day somebody changes how
            // sites are scoped is the day redundancy earns its keep.
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $siteIds)
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($tickets, Payload::ticket(...)));
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $found = Ticket::query()
            ->where('account_id', $scope->accountId())
            ->whereIn('site_id', $scope->siteIds())
            ->whereKey($ticket)
            ->firstOrFail();

        return response()->json(['data' => Payload::ticket($found)]);
    }
}
