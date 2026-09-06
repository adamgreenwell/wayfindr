<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Support\VisitorConversationResolver;
use App\Support\Visitors\VisitorConversationWriteAuthorization;
use App\Support\Visitors\VisitorPageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CobrowsePageStateController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        VisitorConversationResolver $conversations,
        VisitorConversationWriteAuthorization $conversationWrites,
    ): JsonResponse {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'page_url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'viewport_width' => ['required', 'integer', 'min:0', 'max:100000'],
            'viewport_height' => ['required', 'integer', 'min:0', 'max:100000'],
            'scroll_x' => ['required', 'integer', 'min:0', 'max:10000000'],
            'scroll_y' => ['required', 'integer', 'min:0', 'max:10000000'],
            'visibility_state' => ['nullable', 'string', 'max:32'],
            'focused' => ['nullable', 'boolean'],
        ]);

        $conversation = $conversations->resolve(
            $request,
            $supportCode,
            $validated['site_public_key'],
            $validated['anonymous_id'],
        );

        $pageState = [
            // Reduced here as well as in the model hook. The hook is the
            // guarantee -- it cannot be bypassed by a writer nobody has added
            // yet -- but this response echoes the value back, and echoing an
            // address we are about to strip would tell the client we kept
            // something we did not.
            'page_url' => VisitorPageUrl::reduce($validated['page_url']),
            'title' => $validated['title'] ?? null,
            'viewport_width' => $validated['viewport_width'],
            'viewport_height' => $validated['viewport_height'],
            'scroll_x' => $validated['scroll_x'],
            'scroll_y' => $validated['scroll_y'],
            'visibility_state' => $validated['visibility_state'] ?? null,
            'focused' => (bool) ($validated['focused'] ?? false),
            'reported_at' => now()->toJSON(),
        ];

        [$conversation, $cobrowseSession] = DB::transaction(function () use ($conversation, $conversationWrites, $validated, $pageState): array {
            $conversation = $conversationWrites->lock($conversation, $validated['anonymous_id']);
            $cobrowseSession = $conversationWrites->lockCobrowseSession($conversation);
            $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($pageState): array {
                $metadata['page_state'] = $pageState;

                return $metadata;
            });

            return [$conversation, $cobrowseSession];
        });

        event(new CobrowseStateUpdated($cobrowseSession, 'page_state'));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                ],
                'page_state' => $pageState,
            ],
        ]);
    }
}
