<?php

namespace App\Http\Controllers\Widget;

use App\Events\CobrowseStateUpdated;
use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowseAuditTrail;
use App\Support\CobrowsePayloadBudget;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseSnapshotController extends Controller
{
    public function __construct(
        private readonly CobrowseResyncRequestPolicy $resyncRequestPolicy,
        private readonly CobrowseAuditTrail $cobrowseAuditTrail,
    ) {}

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
            'mask_selectors' => ['sometimes', 'array', 'max:100'],
            'mask_selectors.*' => ['string', 'max:255'],
            'sensitive_terms' => ['sometimes', 'array', 'max:100'],
            'sensitive_terms.*' => ['string', 'max:255'],
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
            // Content hash anchors this keyframe: the HTML itself is pruned on
            // the retention schedule, but the hash lets any later claimed copy
            // of "what the agent saw" be verified or refuted from the trail.
            'html_hash' => hash('sha256', $validated['html']),
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

        $resyncAuditTransition = null;

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($snapshot, &$resyncAuditTransition): array {
            $metadata['snapshot'] = $snapshot;
            $metadata['payload_budget'] = CobrowsePayloadBudget::limits();

            [$metadata, $resyncAuditTransition] = $this->markResyncRequestFulfilled($metadata, $snapshot['resync_request_id'] ?? null, $snapshot['reported_at']);

            return $metadata;
        });

        if (is_array($resyncAuditTransition)) {
            if (($resyncAuditTransition['state'] ?? null) === 'fulfilled') {
                $this->cobrowseAuditTrail->resyncFulfilled($cobrowseSession, $visitor, $resyncAuditTransition);
            }

            if (($resyncAuditTransition['state'] ?? null) === 'ignored') {
                $this->cobrowseAuditTrail->resyncIgnored($cobrowseSession, $visitor, $resyncAuditTransition);
            }
        }

        // Provenance prefers the ruleset the widget says it actually masked
        // with (cached from its bootstrap) over the site's current settings,
        // which may have been edited mid-session. Absent fields mean an older
        // widget, so the receipt-time settings are recorded as a fallback.
        $reportedRuleset = null;

        if (array_key_exists('mask_selectors', $validated) || array_key_exists('sensitive_terms', $validated)) {
            $reportedRuleset = [
                'selectors' => $this->stringList($validated['mask_selectors'] ?? []),
                'terms' => $this->stringList($validated['sensitive_terms'] ?? []),
            ];
        }

        $this->cobrowseAuditTrail->snapshotReceived(
            $cobrowseSession,
            $visitor,
            $snapshot,
            $reportedRuleset,
            [
                'selectors' => $this->stringList($site->settings['mask_selectors'] ?? []),
                'terms' => $this->stringList($site->settings['mask_terms'] ?? []),
            ],
        );

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
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function markResyncRequestFulfilled(array $metadata, ?string $requestId, string $reportedAt): array
    {
        if (! filled($requestId)) {
            return [$metadata, null];
        }

        $request = $metadata['resync_request'] ?? null;

        if (! is_array($request)) {
            return [$metadata, null];
        }

        if ((string) ($request['id'] ?? '') !== $requestId) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'mismatched');

            return [$metadata, $this->ignoredResyncTransition($request, $requestId, $reportedAt, 'mismatched')];
        }

        if (filled($request['fulfilled_at'] ?? null)) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'already_fulfilled');

            return [$metadata, $this->ignoredResyncTransition($request, $requestId, $reportedAt, 'already_fulfilled')];
        }

        if (! $this->resyncRequestPolicy->canBeFulfilled($request)) {
            $metadata['resync_request'] = $this->recordIgnoredResyncResponse($request, $requestId, $reportedAt, 'expired');

            return [$metadata, $this->ignoredResyncTransition($request, $requestId, $reportedAt, 'expired')];
        }

        $request['fulfilled_at'] = $reportedAt;
        $request['fulfilled_snapshot_reported_at'] = $reportedAt;
        $metadata['resync_request'] = $request;

        return [$metadata, [
            'state' => 'fulfilled',
            'request_id' => $requestId,
            'fulfilled_at' => $reportedAt,
            'snapshot_reported_at' => $reportedAt,
        ]];
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

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function ignoredResyncTransition(array $request, string $requestId, string $reportedAt, string $reason): array
    {
        return [
            'state' => 'ignored',
            'active_request_id' => is_string($request['id'] ?? null) ? $request['id'] : null,
            'response_request_id' => $requestId,
            'reason' => $reason,
            'ignored_at' => $reportedAt,
        ];
    }
}
