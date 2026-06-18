<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowsePayloadBudget;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseSnapshotController extends Controller
{
    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'page_url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'html' => ['required', 'string', 'max:'.CobrowsePayloadBudget::SNAPSHOT_HTML_MAX_CHARACTERS],
            'text' => ['required', 'string', 'max:'.CobrowsePayloadBudget::SNAPSHOT_TEXT_MAX_CHARACTERS],
            'node_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'masked_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'mutation_sequence' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
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

        $snapshot = [
            'page_url' => $validated['page_url'],
            'title' => $validated['title'] ?? null,
            'html' => $validated['html'],
            'text' => $validated['text'],
            'html_length' => mb_strlen($validated['html']),
            'text_length' => mb_strlen($validated['text']),
            'node_count' => $validated['node_count'],
            'masked_count' => $validated['masked_count'],
            'reported_at' => now()->toJSON(),
        ];

        if (array_key_exists('mutation_sequence', $validated)) {
            $snapshot['mutation_sequence'] = (int) $validated['mutation_sequence'];
        }

        $metadata = $cobrowseSession->metadata ?? [];
        $metadata['snapshot'] = $snapshot;
        $metadata['payload_budget'] = CobrowsePayloadBudget::limits();

        $cobrowseSession->forceFill([
            'metadata' => $metadata,
        ])->save();

        event(new CobrowseStateUpdated($cobrowseSession, 'snapshot'));

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                ],
                'payload_budget' => CobrowsePayloadBudget::limits(),
                'snapshot' => $snapshot,
            ],
        ]);
    }
}
