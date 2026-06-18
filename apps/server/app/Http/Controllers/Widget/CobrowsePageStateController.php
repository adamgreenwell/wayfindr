<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowsePageStateController extends Controller
{
    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
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

        $site = Site::query()
            ->where('public_key', $validated['site_public_key'])
            ->first();

        abort_unless($site, 404, 'Site not found.');

        $visitor = $visitorSessionToken->visitorFromRequest($request, $site, $validated['anonymous_id']);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->first();

        abort_unless($conversation, 404, 'Conversation not found.');

        $cobrowseSession = CobrowseSession::query()
            ->where('conversation_id', $conversation->id)
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->where('status', 'granted')
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        abort_unless($cobrowseSession, 404, 'Cobrowse session not active.');

        $pageState = [
            'page_url' => $validated['page_url'],
            'title' => $validated['title'] ?? null,
            'viewport_width' => $validated['viewport_width'],
            'viewport_height' => $validated['viewport_height'],
            'scroll_x' => $validated['scroll_x'],
            'scroll_y' => $validated['scroll_y'],
            'visibility_state' => $validated['visibility_state'] ?? null,
            'focused' => (bool) ($validated['focused'] ?? false),
            'reported_at' => now()->toJSON(),
        ];

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($pageState): array {
            $metadata['page_state'] = $pageState;

            return $metadata;
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
