<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\CobrowsePayloadBudget;
use App\Support\VisitorSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CobrowseTelemetryController extends Controller
{
    public function store(Request $request, string $supportCode, VisitorSessionToken $visitorSessionToken): JsonResponse
    {
        $validated = $request->validate([
            'site_public_key' => ['required', 'string', 'max:255'],
            'anonymous_id' => ['required', 'string', 'max:255'],
            'visitor_token' => ['nullable', 'string', 'max:4096'],
            'rtt_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'payload_bytes' => ['nullable', 'integer', 'min:0', 'max:'.CobrowsePayloadBudget::TELEMETRY_PAYLOAD_MAX_BYTES],
            'dropped_batches' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reconnects' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'resync_request_id' => ['nullable', 'string', 'max:120'],
            'resync_attempts_exhausted' => ['nullable', 'boolean'],
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

        $rttMs = $validated['rtt_ms'] ?? null;
        $payloadBytes = $validated['payload_bytes'] ?? null;
        $resyncRequestId = filled($validated['resync_request_id'] ?? null) ? (string) $validated['resync_request_id'] : null;
        $resyncAttemptsExhausted = (bool) ($validated['resync_attempts_exhausted'] ?? false);
        $hasTransportMetrics = array_key_exists('rtt_ms', $validated)
            || array_key_exists('payload_bytes', $validated)
            || array_key_exists('dropped_batches', $validated)
            || array_key_exists('reconnects', $validated);
        $telemetry = [];

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata) use ($validated, $rttMs, $payloadBytes, $resyncRequestId, $resyncAttemptsExhausted, $hasTransportMetrics, &$telemetry): array {
            $previousTelemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];

            if ($hasTransportMetrics) {
                $telemetry = [
                    'rtt_ms' => $rttMs,
                    'max_rtt_ms' => $rttMs === null
                        ? ($previousTelemetry['max_rtt_ms'] ?? null)
                        : max((int) ($previousTelemetry['max_rtt_ms'] ?? $rttMs), $rttMs),
                    'payload_bytes' => $payloadBytes,
                    'max_payload_bytes' => $payloadBytes === null
                        ? ($previousTelemetry['max_payload_bytes'] ?? null)
                        : max((int) ($previousTelemetry['max_payload_bytes'] ?? $payloadBytes), $payloadBytes),
                    'dropped_batches' => $validated['dropped_batches'] ?? 0,
                    'reconnects' => $validated['reconnects'] ?? 0,
                    'samples' => ((int) ($previousTelemetry['samples'] ?? 0)) + 1,
                    'reported_at' => now()->toJSON(),
                ];
            } else {
                $telemetry = $previousTelemetry;
            }

            $telemetry['resync_request_id'] = $resyncRequestId;
            $telemetry['resync_attempts_exhausted'] = $resyncAttemptsExhausted;

            $metadata['telemetry'] = $telemetry;
            $metadata['payload_budget'] = CobrowsePayloadBudget::limits();

            if ($resyncAttemptsExhausted && $resyncRequestId !== null) {
                $resyncRequest = is_array($metadata['resync_request'] ?? null) ? $metadata['resync_request'] : null;

                if (
                    $resyncRequest !== null
                    && ($resyncRequest['id'] ?? null) === $resyncRequestId
                    && blank($resyncRequest['fulfilled_at'] ?? null)
                ) {
                    $resyncRequest['attempts_exhausted_at'] = now()->toJSON();
                    $metadata['resync_request'] = $resyncRequest;
                }
            }

            return $metadata;
        });

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                ],
                'payload_budget' => CobrowsePayloadBudget::limits(),
                'telemetry' => $telemetry,
            ],
        ]);
    }
}
