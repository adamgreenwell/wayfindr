<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowsePayloadBudget;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseSnapshotController extends Controller
{
    public function __construct(private readonly CobrowseResyncRequestPolicy $resyncRequestPolicy) {}

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
            'resync_request_id' => ['nullable', 'string', 'max:120'],
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

        if (filled($validated['resync_request_id'] ?? null)) {
            $snapshot['resync_request_id'] = (string) $validated['resync_request_id'];
        }

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($snapshot): array {
            $metadata['snapshot'] = $snapshot;
            $metadata['payload_budget'] = CobrowsePayloadBudget::limits();

            return $this->markResyncRequestFulfilled($metadata, $snapshot['resync_request_id'] ?? null, $snapshot['reported_at']);
        });

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

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function markResyncRequestFulfilled(array $metadata, ?string $requestId, string $reportedAt): array
    {
        if (! filled($requestId)) {
            return $metadata;
        }

        $request = $metadata['resync_request'] ?? null;

        if (! is_array($request)) {
            return $metadata;
        }

        if ((string) ($request['id'] ?? '') !== $requestId) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'mismatched');

            return $metadata;
        }

        if (filled($request['fulfilled_at'] ?? null)) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'already_fulfilled');

            return $metadata;
        }

        if (! $this->resyncRequestPolicy->canBeFulfilled($request)) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'expired');

            return $metadata;
        }

        $request['fulfilled_at'] = $reportedAt;
        $request['fulfilled_snapshot_reported_at'] = $reportedAt;
        $metadata['resync_request'] = $request;

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function recordIgnoredResyncResponse(array $request, string $requestId, string $reportedAt, string $reason): array
    {
        $ignoredResponses = is_array($request['ignored_responses'] ?? null)
            ? array_values(array_filter($request['ignored_responses'], 'is_array'))
            : [];

        $ignoredResponses[] = [
            'request_id' => $requestId,
            'reason' => $reason,
            'ignored_at' => $reportedAt,
        ];

        $request['ignored_responses'] = array_slice($ignoredResponses, -5);

        return $request;
    }
}
