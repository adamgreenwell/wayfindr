<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\Api\ApiScope;
use App\Support\Api\V1\Payload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversations, read-only (ADR 0018).
 *
 * Every query begins from the token's site ids. Not "and then check the account
 * matches" -- an isolation check that runs after loading is one somebody can
 * forget to write, and its absence looks exactly like working code.
 */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $scope = ApiScope::fromRequest($request);

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:open,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $conversations = Conversation::query()
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->when(isset($validated['site_id']), fn ($query) => $query->whereIn(
                'site_id',
                // Can only narrow. Asking for a site the token cannot reach
                // matches nothing rather than being ignored -- an ignored
                // filter is a silent scope escalation.
                $scope->includesSite((int) $validated['site_id']) ? [(int) $validated['site_id']] : [],
            ))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            // Newest first, with `id` breaking ties: cursor pagination needs a
            // total order, and `created_at` alone is not one.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 25, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($conversations, Payload::conversation(...)));
    }

    public function show(Request $request, string $supportCode): JsonResponse
    {
        $conversation = $this->find($request, $supportCode);

        return response()->json(['data' => Payload::conversation($conversation)]);
    }

    public function messages(Request $request, string $supportCode): JsonResponse
    {
        $conversation = $this->find($request, $supportCode);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ]);

        $messages = $conversation->messages()
            // Oldest first: a transcript read in reverse is not a transcript.
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate($validated['per_page'] ?? 50, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json(Payload::page($messages, Payload::message(...)));
    }

    private function find(Request $request, string $supportCode): Conversation
    {
        $scope = ApiScope::fromRequest($request);

        // 404 rather than 403 for a conversation outside the scope. Telling a
        // caller that a support code exists but is not theirs confirms it
        // exists, and support codes are short enough to guess at.
        return Conversation::query()
            ->whereIn('site_id', $scope->siteIdsQuery())
            ->where('support_code', $supportCode)
            ->firstOrFail();
    }
}
