<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
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
            'payload_bytes' => ['nullable', 'integer', 'min:0', 'max:10485760'],
            'dropped_batches' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reconnects' => ['nullable', 'integer', 'min:0', 'max:1000000'],
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

        $metadata = $cobrowseSession->metadata ?? [];
        $previousTelemetry = $metadata['telemetry'] ?? [];
        $rttMs = $validated['rtt_ms'] ?? null;
        $payloadBytes = $validated['payload_bytes'] ?? null;

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

        $metadata['telemetry'] = $telemetry;

        $cobrowseSession->forceFill([
            'metadata' => $metadata,
        ])->save();

        return response()->json([
            'data' => [
                'conversation' => [
                    'support_code' => $conversation->support_code,
                ],
                'cobrowse' => [
                    'status' => $cobrowseSession->status,
                ],
                'telemetry' => $telemetry,
            ],
        ]);
    }
}
