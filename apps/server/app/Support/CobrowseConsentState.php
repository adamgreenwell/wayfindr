<?php

namespace App\Support;

use App\Models\CobrowseSession;
use App\Models\Conversation;
use Illuminate\Support\Carbon;
use Throwable;

class CobrowseConsentState
{
    private const TRANSPORT_STALE_AFTER_SECONDS = 120;

    public function __construct(
        private readonly CobrowseReplayPreview $replayPreview,
        private readonly CobrowseResyncRequestPolicy $resyncRequestPolicy,
        private readonly CobrowseSnapshotFreshness $snapshotFreshness,
        private readonly CobrowseTransportPressure $transportPressure,
    ) {}

    /**
     * @return array{label: string, message: string, status: string, lifecycle: array<string, string>|null, transport: array<string, string>, payload_budget: array<string, string>|null, telemetry: array<string, string>|null, page_state: array<string, string>|null, snapshot: array<string, mixed>|null, snapshot_recovery: array<string, string>|null, mutation_stream: array<string, string>|null, replay_preview: array<string, mixed>|null, resync_request: array<string, mixed>|null}
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
                'snapshot_recovery' => null,
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
        $state['snapshot_recovery'] = $this->formatSnapshotRecovery(
            $state['snapshot']['freshness'] ?? null,
            $state['resync_request'],
        );

        return $state;
    }

    /**
     * @return array{state: string, label: string, message: string, last_report: string, pressure: string, guidance: string, recovery_action: string, tone: string}
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
            'state' => $transport['state'],
            'label' => $transport['label'],
            'message' => $transport['message'],
            'last_report' => $transport['last_report'],
            'pressure' => $transport['pressure'],
            'guidance' => $transport['guidance'],
            'recovery_action' => $transport['recovery_action'],
            'tone' => $this->transportTone($transport['state']),
        ];
    }

    /**
     * @param  array{state?: string}  $transport
     */
    public function transportNeedsAttention(array $transport): bool
    {
        return in_array($transport['state'] ?? null, ['stale', 'reconnecting', 'degraded'], true);
    }

    /**
     * @return array<string, string>
     */
    private function formatTransport(?CobrowseSession $session): array
    {
        if (! $session || $session->status !== 'granted' || $session->ended_at) {
            return [
                'state' => 'unavailable',
                'label' => 'Unavailable',
                'message' => 'Cobrowse transport is not active.',
                'last_report' => 'Not reported',
                'reconnects' => '0',
                'pressure' => 'No drops reported',
                'guidance' => 'Wait for an active cobrowse session before relying on cobrowse.',
                'recovery_action' => 'Wait for the visitor page to report before requesting recovery.',
            ];
        }

        $metadata = $session->metadata ?? [];
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $latestReport = $this->transportPressure->latestReportAt($metadata);
        $telemetryReport = $this->transportPressure->parseReportedAt($telemetry['reported_at'] ?? null);
        $pressure = $this->transportPressure->format($metadata, $latestReport);
        $reconnects = (int) ($telemetry['reconnects'] ?? 0);

        if (! $latestReport) {
            return [
                'state' => 'unavailable',
                'label' => 'Unavailable',
                'message' => 'No cobrowse transport reports have arrived yet.',
                'last_report' => 'Not reported',
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Wait for the visitor page to report before relying on cobrowse.',
                'recovery_action' => 'Wait for the visitor page to report before requesting recovery.',
            ];
        }

        if ($latestReport->lt(now()->subSeconds(self::TRANSPORT_STALE_AFTER_SECONDS))) {
            return [
                'state' => 'stale',
                'label' => 'Stale',
                'message' => 'No cobrowse report has arrived in the last 2 minutes.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Ask the visitor to confirm what they see before relying on the preview.',
                'recovery_action' => 'Request a fresh snapshot if the preview looks out of date, and confirm details through chat.',
            ];
        }

        if ($this->hasFreshReconnectWarning($reconnects, $telemetryReport, $latestReport)) {
            return [
                'state' => 'reconnecting',
                'label' => 'Reconnecting',
                'message' => 'The visitor transport has reconnected recently; preview data may briefly lag.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Use chat to confirm anything that depends on fast-changing page state.',
                'recovery_action' => 'Give the visitor widget a moment, then request a fresh snapshot if the preview still lags.',
            ];
        }

        if ($this->hasTransportPressure($pressure)) {
            return [
                'state' => 'degraded',
                'label' => 'Degraded',
                'message' => 'Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.',
                'last_report' => $latestReport->diffForHumans(),
                'reconnects' => number_format($reconnects),
                'pressure' => $pressure,
                'guidance' => 'Use the preview for orientation and confirm fast-changing details through chat.',
                'recovery_action' => 'Request a fresh snapshot once the visitor widget settles, and use chat for fast-changing details.',
            ];
        }

        return [
            'state' => 'live',
            'label' => 'Live',
            'message' => 'Cobrowse reports are arriving normally.',
            'last_report' => $latestReport->diffForHumans(),
            'reconnects' => '0',
            'pressure' => $pressure,
            'guidance' => ! $this->hasTransportPressure($pressure)
                ? 'Preview is current enough to use alongside chat.'
                : 'Use chat to confirm anything that depends on fast-changing page state.',
            'recovery_action' => 'No recovery action needed.',
        ];
    }

    private function transportTone(string $state): string
    {
        return match ($state) {
            'live' => 'ready',
            'stale', 'reconnecting', 'degraded' => 'attention',
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
            'widget_batch_payload' => $this->formatBytes($payloadBudget['widget_mutation_batch_max_bytes'] ?? null),
            'widget_queue' => $this->formatPending($payloadBudget['widget_mutation_queue_max_records'] ?? null),
            'widget_mutation_flush' => $this->formatMilliseconds($payloadBudget['widget_mutation_flush_ms'] ?? null),
            'widget_pressure_resync' => $this->formatMilliseconds($payloadBudget['widget_pressure_resync_ms'] ?? null),
            'widget_status_poll' => $this->formatMilliseconds($payloadBudget['widget_status_poll_ms'] ?? null),
            'widget_resync_attempts' => $this->formatAttempts($payloadBudget['widget_resync_max_attempts'] ?? null),
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
     * @return array<string, mixed>|null
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
            'freshness' => $this->snapshotFreshness->format($snapshot['reported_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>|null  $freshness
     * @param  array<string, mixed>|null  $resyncRequest
     * @return array<string, string>|null
     */
    private function formatSnapshotRecovery(?array $freshness, ?array $resyncRequest): ?array
    {
        $freshnessState = $freshness['state'] ?? null;

        if (! in_array($freshnessState, ['aging', 'stale', 'unknown'], true)) {
            return null;
        }

        if (($resyncRequest['status'] ?? null) === 'pending') {
            return [
                'status' => 'pending',
                'label' => 'Snapshot refresh already requested',
                'message' => 'A fresh snapshot request is already waiting on the visitor widget. Use chat while it catches up.',
            ];
        }

        if ($freshnessState === 'unknown') {
            return [
                'status' => 'unknown',
                'label' => 'Snapshot time needs confirmation',
                'message' => 'Ask the visitor what they see or request a fresh snapshot before relying on this preview.',
            ];
        }

        return [
            'status' => $freshnessState,
            'label' => 'Snapshot may need refresh',
            'message' => 'Request a fresh snapshot before relying on this preview, or confirm the page through chat.',
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
        $attemptsExhaustedAt = $this->parseReportedAt($request['attempts_exhausted_at'] ?? null);
        $expiresAt = $this->resyncRequestPolicy->expiresAt($request);
        $retryAt = $this->resyncRequestPolicy->retryAt($request);
        $timeline = $this->formatResyncRecoveryTimeline($request, $requestedAt, $fulfilledAt, $snapshotReportedAt, $attemptsExhaustedAt, $expiresAt, $retryAt);

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

        if ($attemptsExhaustedAt) {
            return [
                'status' => 'exhausted',
                'label' => 'Fresh snapshot retry limit reached',
                'message' => 'The visitor widget tried to send a clean snapshot but could not complete it. Request another clean snapshot or confirm the page state through chat.',
                'requested_by' => filled($request['requested_by_name'] ?? null) ? (string) $request['requested_by_name'] : 'Support',
                'requested_at' => $this->formatMoment($requestedAt, 'Request time unavailable'),
                'expires_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'attempts_exhausted_at' => $this->formatMoment($attemptsExhaustedAt, 'Retry limit time unavailable'),
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
    private function formatResyncRecoveryTimeline(array $request, ?Carbon $requestedAt, ?Carbon $fulfilledAt, ?Carbon $snapshotReportedAt, ?Carbon $attemptsExhaustedAt, ?Carbon $expiresAt, ?Carbon $retryAt): array
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

            return $this->appendIgnoredResyncResponses($timeline, $request);
        }

        if ($attemptsExhaustedAt) {
            $timeline[] = [
                'state' => 'exhausted',
                'label' => 'Retry limit reached',
                'detail' => 'The visitor widget stopped retrying this request ID after repeated failures.',
                'occurred_at' => $this->formatMoment($attemptsExhaustedAt, 'Retry limit time unavailable'),
                'badge' => 'Exhausted',
            ];

            return $this->appendIgnoredResyncResponses($timeline, $request);
        }

        if ($this->resyncRequestPolicy->isExpired($request)) {
            $timeline[] = [
                'state' => 'expired',
                'label' => 'Request expired',
                'detail' => 'No widget response arrived before the recovery window closed.',
                'occurred_at' => $this->formatMoment($expiresAt, 'Expiry unavailable'),
                'badge' => 'Expired',
            ];

            return $this->appendIgnoredResyncResponses($timeline, $request);
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

            return $this->appendIgnoredResyncResponses($timeline, $request);
        }

        $timeline[] = [
            'state' => 'pending',
            'label' => 'Waiting on visitor widget',
            'detail' => 'Retry opens '.$this->formatMoment($retryAt, 'when the retry window opens').'.',
            'occurred_at' => $this->formatMoment($retryAt, 'Retry time unavailable'),
            'badge' => 'Pending',
        ];

        return $this->appendIgnoredResyncResponses($timeline, $request);
    }

    /**
     * @param  list<array{state: string, label: string, detail: string, occurred_at: string, badge: string}>  $timeline
     * @param  array<string, mixed>  $request
     * @return list<array{state: string, label: string, detail: string, occurred_at: string, badge: string}>
     */
    private function appendIgnoredResyncResponses(array $timeline, array $request): array
    {
        $ignoredResponses = is_array($request['ignored_responses'] ?? null) ? $request['ignored_responses'] : [];

        foreach ($ignoredResponses as $ignoredResponse) {
            if (! is_array($ignoredResponse)) {
                continue;
            }

            $timeline[] = [
                'state' => 'ignored',
                'label' => 'Snapshot response ignored',
                'detail' => $this->ignoredResyncResponseDetail((string) ($ignoredResponse['reason'] ?? 'unknown')),
                'occurred_at' => $this->formatMoment(
                    $this->parseReportedAt($ignoredResponse['ignored_at'] ?? null),
                    'Ignored response time unavailable'
                ),
                'badge' => 'Ignored',
            ];
        }

        return $timeline;
    }

    private function ignoredResyncResponseDetail(string $reason): string
    {
        return match ($reason) {
            'expired' => 'A widget response arrived after the recovery window closed.',
            'mismatched' => 'A widget response arrived for a different recovery request.',
            'already_fulfilled' => 'A duplicate widget response arrived after Wayfindr had already accepted a fresh snapshot.',
            default => 'A widget response could not be matched to the active recovery request.',
        };
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

    private function formatPending(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' pending';
    }

    private function formatAttempts(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        $attempts = (int) $value;

        return number_format($attempts).' '.str('attempt')->plural($attempts);
    }

    private function formatBytes(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'Not reported';
        }

        return number_format((int) $value).' bytes';
    }
}
