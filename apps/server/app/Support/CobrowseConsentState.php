<?php

namespace App\Support;

use App\Models\CobrowseSession;
use App\Models\Conversation;
use Illuminate\Support\Carbon;
use Throwable;

class CobrowseConsentState
{
    private const TRANSPORT_STALE_AFTER_SECONDS = 120;

    private const TRANSPORT_RECENT_LOSS_WINDOW_SECONDS = 30;

    public function __construct(
        private readonly CobrowseReplayPreview $replayPreview,
        private readonly CobrowseResyncRequestPolicy $resyncRequestPolicy,
    ) {}

    /**
     * @return array{label: string, message: string, status: string, lifecycle: array<string, string>|null, transport: array<string, string>, payload_budget: array<string, string>|null, telemetry: array<string, string>|null, page_state: array<string, string>|null, snapshot: array<string, string>|null, mutation_stream: array<string, string>|null, replay_preview: array<string, string>|null, resync_request: array<string, mixed>|null}
     */
    public function forConversation(Conversation $conversation): array
    {
        $session = $conversation->cobrowseSessions()
            ->with('requestedBy')
            ->latest('id')
            ->first();

        if (! $session) {
            return [
                'label' => 'Unavailable',
                'message' => 'Visitor has not granted cobrowse consent.',
                'status' => 'unavailable',
                'lifecycle' => null,
                'transport' => $this->formatTransport(null),
                'payload_budget' => null,
                'telemetry' => null,
                'page_state' => null,
                'snapshot' => null,
                'mutation_stream' => null,
                'replay_preview' => null,
                'resync_request' => null,
            ];
        }

        $state = match ($session->status) {
            'requested' => [
                'label' => 'Pending consent',
                'message' => 'Waiting for visitor consent before cobrowsing can start.',
                'status' => 'pending',
            ],
            'granted' => [
                'label' => 'Granted',
                'message' => 'Visitor granted cobrowse consent.',
                'status' => 'granted',
            ],
            'revoked' => [
                'label' => 'Revoked',
                'message' => 'Visitor revoked cobrowse consent.',
                'status' => 'revoked',
            ],
            'ended' => [
                'label' => 'Ended',
                'message' => 'Cobrowse session ended.',
                'status' => 'ended',
            ],
            default => [
                'label' => 'Unavailable',
                'message' => 'Visitor has not granted cobrowse consent.',
                'status' => 'unavailable',
            ],
        };

        $state['lifecycle'] = $this->formatLifecycle($session);
        $state['transport'] = $this->formatTransport($session);
        $state['payload_budget'] = $this->formatPayloadBudget($session->metadata['payload_budget'] ?? CobrowsePayloadBudget::limits());
        $state['telemetry'] = $this->formatTelemetry($session->metadata['telemetry'] ?? null);
        $state['page_state'] = $this->formatPageState($session->metadata['page_state'] ?? null);
        $state['snapshot'] = $this->formatSnapshot($session->metadata['snapshot'] ?? null);
        $state['mutation_stream'] = $this->formatMutationStream($session->metadata['mutations'] ?? null);
        $state['replay_preview'] = $this->replayPreview->fromMetadata($session->metadata ?? []);
        $state['resync_request'] = $this->formatResyncRequest($session);

        return $state;
    }

    /**
     * @return array{label: string, message: string, last_report: string, tone: string}
     */
    public function queueTransportForConversation(Conversation $conversation): array
    {
        $session = $conversation->relationLoaded('latestCobrowseSession')
            ? $conversation->latestCobrowseSession
            : $conversation->cobrowseSessions()
                ->latest('id')
                ->first();

        $transport = $this->formatTransport($session);

        return [
            'label' => $transport['label'],
            'message' => $transport['message'],
            'last_report' => $transport['last_report'],
            'tone' => $this->transportTone($transport['label']),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function formatTransport(?CobrowseSession $session): array
    {
        if (! $session || $session->status !== 'granted' || $session->ended_at) {
            return [
                'label' => 'Unavailable',
                'message' => 'Cobrowse transport is not active.',
                'last_report' => 'Not reported',
                'reconnects' => '0',
                'pressure' => 'No drops reported',
                'guidance' => 'Wait for an active cobrowse session before relying on cobrowse.',
            ];
        }

        $metadata = $session->metadata ?? [];
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $latestReport = $this->latestReportAt($metadata);
        $telemetryReport = $this->parseReportedAt($telemetry['reported_at'] ?? null);
        $pressure = $this->formatTransportPressure($metadata, $latestReport);
        $reconnects = (int) ($telemetry['reconnects'] ?? 0);

        if (! $latestReport) {
            return [
                'label' => 'Unavailable',
                'message' => 'No cobrowse transport reports have arrived yet.',
                'last_report' => 'Not reported',
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Wait for the visitor page to report before relying on cobrowse.',
            ];
        }

        if ($latestReport->lt(now()->subSeconds(self::TRANSPORT_STALE_AFTER_SECONDS))) {
            return [
                'label' => 'Stale',
                'message' => 'No cobrowse report has arrived in the last 2 minutes.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Ask the visitor to confirm what they see before relying on the preview.',
            ];
        }

        if ($this->hasFreshReconnectWarning($reconnects, $telemetryReport, $latestReport)) {
            return [
                'label' => 'Reconnecting',
                'message' => 'The visitor transport has reconnected recently; preview data may briefly lag.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Use chat to confirm anything that depends on fast-changing page state.',
            ];
        }

        if ($this->hasTransportPressure($pressure)) {
            return [
                'label' => 'Degraded',
                'message' => 'Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Use the preview for orientation and confirm fast-changing details through chat.',
            ];
        }

        return [
            'label' => 'Live',
            'message' => 'Cobrowse reports are arriving normally.',
            'last_report' => $latestReport->diffForHumans(),
            'reconnects' => '0',
            'pressure' => $pressure,
            'guidance' => ! $this->hasTransportPressure($pressure)
                ? 'Preview is current enough to use alongside chat.'
                : 'Use chat to confirm anything that depends on fast-changing page state.',
        ];
    }

    private function transportTone(string $label): string
    {
        return match ($label) {
            'Live' => 'ready',
            'Stale', 'Reconnecting', 'Degraded' => 'attention',
            default => 'manual',
        };
    }

    private function hasTransportPressure(string $pressure): bool
    {
        return ! in_array($pressure, ['No drops reported', 'No recent drops reported'], true);
    }

    private function hasFreshReconnectWarning(int $reconnects, ?Carbon $telemetryReport, ?Carbon $latestReport): bool
    {
        if ($reconnects <= 0 || ! $telemetryReport) {
            return false;
        }

        if ($telemetryReport->lt(now()->subSeconds(self::TRANSPORT_STALE_AFTER_SECONDS))) {
            return false;
        }

        return ! $latestReport || $telemetryReport->gte($latestReport);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function latestReportAt(array $metadata): ?Carbon
    {
        $timestamps = [
            $metadata['telemetry']['reported_at'] ?? null,
            $metadata['page_state']['reported_at'] ?? null,
            $metadata['snapshot']['reported_at'] ?? null,
            $metadata['mutations']['last_reported_at'] ?? null,
        ];

        $latest = null;

        foreach ($timestamps as $timestamp) {
            $reportedAt = $this->parseReportedAt($timestamp);

            if ($reportedAt && (! $latest || $reportedAt->gt($latest))) {
                $latest = $reportedAt;
            }
        }

        return $latest;
    }

    private function parseReportedAt(mixed $timestamp): ?Carbon
    {
        if (! filled($timestamp)) {
            return null;
        }

        try {
            return Carbon::parse((string) $timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function formatTransportPressure(array $metadata, ?Carbon $latestReport): string
    {
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $droppedBatches = 0;
        $skippedMutations = 0;
        $parts = [];

        if ($this->isRecentTransportReport($this->parseReportedAt($telemetry['reported_at'] ?? null), $latestReport)) {
            $droppedBatches += (int) ($telemetry['dropped_batches'] ?? 0);
        }

        foreach ($this->recentMutationBatches($mutations) as $batch) {
            if (! $this->isRecentTransportReport($this->parseReportedAt($batch['reported_at'] ?? null), $latestReport)) {
                continue;
            }

            $droppedBatches += (int) ($batch['dropped_count'] ?? 0);
            $skippedMutations += (int) ($batch['skipped_count'] ?? 0);
        }

        if ($droppedBatches > 0) {
            $parts[] = number_format($droppedBatches).' dropped '.str('batch')->plural($droppedBatches);
        }

        if ($skippedMutations > 0) {
            $parts[] = number_format($skippedMutations).' skipped '.str('mutation')->plural($skippedMutations);
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $latestReport ? 'No recent drops reported' : 'No drops reported';
    }

    private function isRecentTransportReport(?Carbon $reportedAt, ?Carbon $latestReport): bool
    {
        if (! $reportedAt || ! $latestReport) {
            return false;
        }

        return $reportedAt->gte($latestReport->copy()->subSeconds(self::TRANSPORT_RECENT_LOSS_WINDOW_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $mutations
     * @return array<int, array<string, mixed>>
     */
    private function recentMutationBatches(array $mutations): array
    {
        if (! is_array($mutations['recent_batches'] ?? null)) {
            return [];
        }

        return collect($mutations['recent_batches'])
            ->filter(fn (mixed $batch): bool => is_array($batch))
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function formatLifecycle(CobrowseSession $session): array
    {
        return [
            'requested_by' => $session->requestedBy?->name ?? 'Unknown agent',
            'requested_at' => $this->formatMoment($session->created_at),
            'consented_at' => $this->formatMoment($session->consented_at, 'Not granted yet'),
            'ended_at' => $this->formatMoment($session->ended_at, 'Still active'),
            'ended_by' => $this->endedByLabel($session->metadata ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function endedByLabel(array $metadata): string
    {
        if (($metadata['ended_by_type'] ?? null) === 'visitor') {
            return 'Visitor';
        }

        if (filled($metadata['ended_by_name'] ?? null)) {
            return (string) $metadata['ended_by_name'];
        }

        return 'Not recorded';
    }

    private function formatMoment(mixed $moment, string $missing = 'Not recorded'): string
    {
        if (! $moment || ! method_exists($moment, 'diffForHumans')) {
            return $missing;
        }

        return $moment->diffForHumans();
    }

    /**
     * @return array<string, string>|null
     */
    private function formatPayloadBudget(mixed $payloadBudget): ?array
    {
        if (! is_array($payloadBudget)) {
            return null;
        }

        return [
            'snapshot_html' => $this->formatCharacters($payloadBudget['snapshot_html_max_characters'] ?? null),
            'snapshot_text' => $this->formatCharacters($payloadBudget['snapshot_text_max_characters'] ?? null),
            'mutation_batch' => $this->formatItems($payloadBudget['mutation_batch_max_items'] ?? null),
            'mutation_text' => $this->formatCharacters($payloadBudget['mutation_text_max_characters'] ?? null),
            'mutation_html' => $this->formatCharacters($payloadBudget['mutation_html_max_characters'] ?? null),
            'recent_batches' => $this->formatRetained($payloadBudget['mutation_recent_batches_retained'] ?? null),
            'telemetry_payload' => $this->formatBytes($payloadBudget['telemetry_payload_max_bytes'] ?? null),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function formatTelemetry(mixed $telemetry): ?array
    {
        if (! is_array($telemetry)) {
            return null;
        }

        return [
            'rtt' => $this->formatMilliseconds($telemetry['rtt_ms'] ?? null),
            'max_rtt' => $this->formatMilliseconds($telemetry['max_rtt_ms'] ?? null),
            'payload' => $this->formatBytes($telemetry['payload_bytes'] ?? null),
            'max_payload' => $this->formatBytes($telemetry['max_payload_bytes'] ?? null),
            'dropped_batches' => number_format((int) ($telemetry['dropped_batches'] ?? 0)),
            'reconnects' => number_format((int) ($telemetry['reconnects'] ?? 0)),
            'samples' => number_format((int) ($telemetry['samples'] ?? 0)),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function formatPageState(mixed $pageState): ?array
    {
        if (! is_array($pageState)) {
            return null;
        }

        return [
            'title' => filled($pageState['title'] ?? null) ? (string) $pageState['title'] : 'Untitled page',
            'page_url' => filled($pageState['page_url'] ?? null) ? (string) $pageState['page_url'] : 'Not reported',
            'viewport' => $this->formatDimensions($pageState['viewport_width'] ?? null, $pageState['viewport_height'] ?? null),
            'scroll' => $this->formatCoordinates($pageState['scroll_x'] ?? null, $pageState['scroll_y'] ?? null),
            'visibility_state' => filled($pageState['visibility_state'] ?? null) ? (string) $pageState['visibility_state'] : 'Not reported',
            'focus' => ($pageState['focused'] ?? false) ? 'Focused' : 'Not focused',
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function formatSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)) {
            return null;
        }

        return [
            'title' => filled($snapshot['title'] ?? null) ? (string) $snapshot['title'] : 'Untitled page',
            'page_url' => filled($snapshot['page_url'] ?? null) ? (string) $snapshot['page_url'] : 'Not reported',
            'node_count' => number_format((int) ($snapshot['node_count'] ?? 0)).' nodes',
            'masked_count' => number_format((int) ($snapshot['masked_count'] ?? 0)).' masked',
            'text' => filled($snapshot['text'] ?? null) ? (string) $snapshot['text'] : 'No text preview reported.',
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function formatMutationStream(mixed $mutations): ?array
    {
        if (! is_array($mutations)) {
            return null;
        }

        return [
            'batch_count' => number_format((int) ($mutations['batch_count'] ?? 0)).' batches',
            'mutation_count' => number_format((int) ($mutations['mutation_count'] ?? 0)).' mutations',
            'dropped_count' => number_format((int) ($mutations['dropped_count'] ?? 0)).' dropped',
            'skipped_count' => number_format((int) ($mutations['skipped_count'] ?? 0)).' skipped',
            'last_sequence' => 'Sequence '.number_format((int) ($mutations['last_sequence'] ?? 0)),
            'last_page_url' => filled($mutations['last_page_url'] ?? null) ? (string) $mutations['last_page_url'] : 'Not reported',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatResyncRequest(CobrowseSession $session): ?array
    {
        if ($session->status !== 'granted' || $session->ended_at) {
            return null;
        }

        $request = $session->metadata['resync_request'] ?? null;

        if (! is_array($request)) {
            return null;
        }

        $requestedAt = $this->parseReportedAt($request['requested_at'] ?? null);
        $fulfilledAt = $this->parseReportedAt($request['fulfilled_at'] ?? null);
        $snapshotReportedAt = $this->parseReportedAt($request['fulfilled_snapshot_reported_at'] ?? null);
        $expiresAt = $this->resyncRequestPolicy->expiresAt($request);
        $retryAt = $this->resyncRequestPolicy->retryAt($request);
        $timeline = $this->formatResyncRecoveryTimeline($request, $requestedAt, $fulfilledAt, $snapshotReportedAt, $expiresAt, $retryAt);

        if ($fulfilledAt) {
            return [
                'status' => 'fulfilled',
                'label' => 'Fresh snapshot received',
                'message' => 'The visitor widget sent a clean masked snapshot.',
                'requested_by' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
                'requested_at' => $this->formatMoment($requestedAt, 'Request time unavailable'),
                'fulfilled_at' => $this->formatMoment($fulfilledAt, 'Receipt time unavailable'),
                'snapshot_reported_at' => $this->formatMoment($snapshotReportedAt, 'Snapshot report time unavailable'),
                'recovery_timeline' => $timeline,
            ];
        }

        if ($this->resyncRequestPolicy->isExpired($request)) {
            return [
                'status' => 'expired',
                'label' => 'Fresh snapshot expired',
                'message' => 'The visitor widget did not answer in time. Request another clean snapshot or continue through chat.',
                'requested_by' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
                'requested_at' => $this->formatMoment($requestedAt, 'Request time unavailable'),
                'expired_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'recovery_timeline' => $timeline,
            ];
        }

        if ($this->resyncRequestPolicy->isDelayedPending($request)) {
            return [
                'status' => 'delayed',
                'label' => 'Fresh snapshot delayed',
                'message' => 'The visitor widget has not answered yet. Request another clean snapshot or confirm the page state through chat.',
                'requested_by' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
                'requested_at' => $this->formatMoment($requestedAt, 'Request time unavailable'),
                'expires_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'recovery_timeline' => $timeline,
            ];
        }

        return [
            'status' => 'pending',
            'label' => 'Fresh snapshot requested',
            'message' => 'Waiting for the visitor widget to send a clean page snapshot.',
            'requested_by' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
            'requested_at' => $this->formatMoment($requestedAt, 'Just requested'),
            'expires_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
            'retry_at' => $retryAt?->toJSON() ?? '',
            'recovery_timeline' => $timeline,
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return list<array{state: string, label: string, detail: string, occurred_at: string, badge: string}>
     */
    private function formatResyncRecoveryTimeline(array $request, ?Carbon $requestedAt, ?Carbon $fulfilledAt, ?Carbon $snapshotReportedAt, ?Carbon $expiresAt, ?Carbon $retryAt): array
    {
        $requestedBy = filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support';
        $timeline = [
            [
                'state' => 'complete',
                'label' => 'Snapshot requested',
                'detail' => $requestedBy.' asked the visitor widget for a clean masked snapshot.',
                'occurred_at' => $this->formatMoment($requestedAt, 'Request time unavailable'),
                'badge' => 'Requested',
            ],
        ];

        if ($fulfilledAt) {
            $timeline[] = [
                'state' => 'complete',
                'label' => 'Visitor widget responded',
                'detail' => 'A fresh cobrowse snapshot response arrived from the visitor page.',
                'occurred_at' => $this->formatMoment($fulfilledAt, 'Receipt time unavailable'),
                'badge' => 'Recovered',
            ];

            if ($snapshotReportedAt) {
                $timeline[] = [
                    'state' => 'complete',
                    'label' => 'Masked snapshot refreshed',
                    'detail' => 'The clean page snapshot is available in the agent preview.',
                    'occurred_at' => $this->formatMoment($snapshotReportedAt, 'Snapshot report time unavailable'),
                    'badge' => 'Preview updated',
                ];
            }

            return $timeline;
        }

        if ($this->resyncRequestPolicy->isExpired($request)) {
            $timeline[] = [
                'state' => 'expired',
                'label' => 'Request expired',
                'detail' => 'No widget response arrived before the recovery window closed.',
                'occurred_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'badge' => 'Expired',
            ];

            return $timeline;
        }

        if ($this->resyncRequestPolicy->isDelayedPending($request)) {
            $timeline[] = [
                'state' => 'delayed',
                'label' => 'Retry available',
                'detail' => 'Support can request another clean snapshot without waiting on the first request.',
                'occurred_at' => $this->formatMoment($retryAt, 'Retry time unavailable'),
                'badge' => 'Retry',
            ];

            $timeline[] = [
                'state' => 'pending',
                'label' => 'Request expires',
                'detail' => 'Wayfindr will stop advertising this stale request after the expiration window.',
                'occurred_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'badge' => 'Guardrail',
            ];

            return $timeline;
        }

        $timeline[] = [
            'state' => 'pending',
            'label' => 'Waiting on visitor widget',
            'detail' => 'Retry opens '.$this->formatMoment($retryAt, 'when the retry window opens').'.',
            'occurred_at' => $this->formatMoment($retryAt, 'Retry time unavailable'),
            'badge' => 'Pending',
        ];

        return $timeline;
    }

    private function formatDimensions(mixed $width, mixed $height): string
    {
        if (! is_numeric($width) || ! is_numeric($height)) {
            return 'Not reported';
        }

        return number_format((int) $width).' x '.number_format((int) $height);
    }

    private function formatCoordinates(mixed $x, mixed $y): string
    {
        if (! is_numeric($x) || ! is_numeric($y)) {
            return 'Not reported';
        }

        return number_format((int) $x).', '.number_format((int) $y);
    }

    private function formatMilliseconds(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' ms';
    }

    private function formatCharacters(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' characters';
    }

    private function formatItems(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' items';
    }

    private function formatRetained(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' retained';
    }

    private function formatBytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' bytes';
    }
}
