<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Rules\DecodableCursor;
use App\Support\Api\ApiScope;
use App\Support\Api\V1\Payload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitors, read-only (ADR 0018).
 *
 * The endpoint an integration reaches for first, because `external_id` is the
 * only field here that joins to the caller's own records.
 */
class VisitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', new DecodableCursor],
        ]);

        $visitors = Visitor::query()
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->when(isset($validated['site_id']), fn ($query) => $query->whereIn(
                'site_id',
                // Narrows only, never widens.
                $scope->includesSite((int) $validated['site_id']) ? [(int) $validated['site_id']] : [],
            ))
            // Exact matches, not searches. `external_id` and `email` are how an
            // integration finds the person it already knows about; a partial
            // match here would be a way to enumerate an account's customers.
            ->when(isset($validated['external_id']), fn ($query) => $query->where('external_id', $validated['external_id']))
            ->when(isset($validated['email']), fn ($query) => $query->whereRaw(
                'LOWER(email) = ?',
                [mb_strtolower($validated['email'])],
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($visitors, Payload::visitor(...)));
    }

    public function show(Request $request, int $visitor): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $found = Visitor::query()
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->whereKey($visitor)
            ->firstOrFail();

        return response()->json(['data' => Payload::visitor($found)]);
    }
}
