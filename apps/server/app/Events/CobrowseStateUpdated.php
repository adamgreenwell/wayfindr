<?php

namespace App\Events;

use App\Models\CobrowseSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class CobrowseStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    private const TRANSPORT_RECENT_LOSS_WINDOW_SECONDS = 30;

    public function __construct(public CobrowseSession $cobrowseSession, public string $kind)
    {
        $this->cobrowseSession->loadMissing('conversation');
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversations.'.$this->cobrowseSession->conversation->support_code),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.cobrowse.updated';
    }

    /**
     * @return array{conversation: array{support_code: string, status: string}, cobrowse: array{status: string}, update: array{kind: string, reported_at: string|null}, summary: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation' => [
                'support_code' => $this->cobrowseSession->conversation->support_code,
                'status' => $this->cobrowseSession->conversation->status,
            ],
            'cobrowse' => [
                'status' => $this->cobrowseSession->status,
            ],
            'update' => [
                'kind' => $this->kind,
                'reported_at' => $this->reportedAt(),
            ],
            'summary' => $this->summaryPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryPayload(): array
    {
        $metadata = $this->cobrowseSession->metadata ?? [];
        $pageState = is_array($metadata['page_state'] ?? null) ? $metadata['page_state'] : [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $resyncRequest = is_array($metadata['resync_request'] ?? null) ? $metadata['resync_request'] : [];
        $resyncRequestId = $resyncRequest['id'] ?? null;
        $resyncAttemptsExhausted = null;

        if ($resyncRequestId !== null && filled($resyncRequest['attempts_exhausted_at'] ?? null)) {
            $resyncAttemptsExhausted = true;
        } elseif (
            $resyncRequestId !== null
            && ($telemetry['resync_request_id'] ?? null) === $resyncRequestId
            && ($telemetry['resync_attempts_exhausted'] ?? false) === true
        ) {
            $resyncAttemptsExhausted = true;
        }

        $transportTelemetry = array_filter([
            'rtt_ms' => $telemetry['rtt_ms'] ?? null,
            'max_rtt_ms' => $telemetry['max_rtt_ms'] ?? null,
            'payload_bytes' => $telemetry['payload_bytes'] ?? null,
            'max_payload_bytes' => $telemetry['max_payload_bytes'] ?? null,
            'dropped_batches' => $telemetry['dropped_batches'] ?? null,
            'reconnects' => $telemetry['reconnects'] ?? null,
            'samples' => $telemetry['samples'] ?? null,
            'reported_at' => $telemetry['reported_at'] ?? null,
            'resync_attempts_exhausted' => $resyncAttemptsExhausted,
        ], fn (mixed $value): bool => $value !== null);
        $transportPressure = $this->transportPressurePayload($metadata);

        return array_filter([
            'page_url' => $pageState['page_url'] ?? $snapshot['page_url'] ?? $mutations['last_page_url'] ?? null,
            'title' => $pageState['title'] ?? $snapshot['title'] ?? null,
            'batch_count' => $mutations['batch_count'] ?? null,
            'mutation_count' => $mutations['mutation_count'] ?? null,
            'last_sequence' => $mutations['last_sequence'] ?? null,
            'resync_request_id' => $resyncRequestId,
            'transport_pressure' => $transportPressure === [] ? null : $transportPressure,
            'telemetry' => $transportTelemetry === [] ? null : $transportTelemetry,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{dropped_batches: int, skipped_mutations: int, reported_at: string|null}|array{}
     */
    private function transportPressurePayload(array $metadata): array
    {
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $latestReport = $this->latestReportAt($metadata);
        $droppedBatches = 0;
        $skippedMutations = 0;
        $latestPressureReport = null;
        $latestPressureReportedAt = null;

        $telemetryReport = $this->parseReportedAt($telemetry['reported_at'] ?? null);

        if ($this->isRecentTransportReport($telemetryReport, $latestReport)) {
            $droppedBatches += (int) ($telemetry['dropped_batches'] ?? 0);
            [$latestPressureReport, $latestPressureReportedAt] = $this->newerReport(
                $latestPressureReport,
                $latestPressureReportedAt,
                $telemetry['reported_at'] ?? null,
            );
        }

        foreach ($this->recentMutationBatches($mutations) as $batch) {
            $batchReport = $this->parseReportedAt($batch['reported_at'] ?? null);

            if (! $this->isRecentTransportReport($batchReport, $latestReport)) {
                continue;
            }

            $droppedBatches += (int) ($batch['dropped_count'] ?? 0);
            $skippedMutations += (int) ($batch['skipped_count'] ?? 0);
            [$latestPressureReport, $latestPressureReportedAt] = $this->newerReport(
                $latestPressureReport,
                $latestPressureReportedAt,
                $batch['reported_at'] ?? null,
            );
        }

        if ($droppedBatches <= 0 && $skippedMutations <= 0) {
            return [];
        }

        return [
            'dropped_batches' => $droppedBatches,
            'skipped_mutations' => $skippedMutations,
            'reported_at' => $latestPressureReportedAt,
        ];
    }

    /**
     * @return array{0: Carbon|null, 1: string|null}
     */
    private function newerReport(?Carbon $currentReport, ?string $currentReportedAt, mixed $candidateReportedAt): array
    {
        $candidateReport = $this->parseReportedAt($candidateReportedAt);

        if (! $candidateReport || ($currentReport && ! $candidateReport->gt($currentReport))) {
            return [$currentReport, $currentReportedAt];
        }

        return [$candidateReport, (string) $candidateReportedAt];
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

    private function reportedAt(): ?string
    {
        $metadata = $this->cobrowseSession->metadata ?? [];
        $pageState = is_array($metadata['page_state'] ?? null) ? $metadata['page_state'] : [];
        $snapshot = is_array($metadata['snapshot'] ?? null) ? $metadata['snapshot'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $resyncRequest = is_array($metadata['resync_request'] ?? null) ? $metadata['resync_request'] : [];

        return match ($this->kind) {
            'page_state' => $pageState['reported_at'] ?? null,
            'snapshot' => $snapshot['reported_at'] ?? null,
            'mutations' => $mutations['last_reported_at'] ?? null,
            'telemetry' => $resyncRequest['attempts_exhausted_at'] ?? $telemetry['reported_at'] ?? null,
            'resync_requested' => $resyncRequest['requested_at'] ?? null,
            default => null,
        };
    }
}
