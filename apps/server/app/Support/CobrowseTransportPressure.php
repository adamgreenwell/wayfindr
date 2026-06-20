<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

class CobrowseTransportPressure
{
    private const RECENT_LOSS_WINDOW_SECONDS = 30;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{dropped_batches: int, skipped_mutations: int, reported_at: string|null}
     */
    public function summarize(array $metadata, ?Carbon $latestReport = null): array
    {
        $telemetry = is_array($metadata['telemetry'] ?? null) ? $metadata['telemetry'] : [];
        $mutations = is_array($metadata['mutations'] ?? null) ? $metadata['mutations'] : [];
        $latestReport ??= $this->latestReportAt($metadata);
        $droppedBatches = 0;
        $skippedMutations = 0;
        $latestPressureReport = null;
        $latestPressureReportedAt = null;

        $telemetryReport = $this->parseReportedAt($telemetry['reported_at'] ?? null);

        if ($this->isRecentTransportReport($telemetryReport, $latestReport)) {
            $droppedBatches += $this->count($telemetry['dropped_batches'] ?? 0);

            if ($droppedBatches > 0) {
                [$latestPressureReport, $latestPressureReportedAt] = $this->newerReport(
                    $latestPressureReport,
                    $latestPressureReportedAt,
                    $telemetry['reported_at'] ?? null,
                );
            }
        }

        foreach ($this->recentMutationBatches($mutations) as $batch) {
            $batchReport = $this->parseReportedAt($batch['reported_at'] ?? null);

            if (! $this->isRecentTransportReport($batchReport, $latestReport)) {
                continue;
            }

            $batchDropped = $this->count($batch['dropped_count'] ?? 0);
            $batchSkipped = $this->count($batch['skipped_count'] ?? 0);
            $droppedBatches += $batchDropped;
            $skippedMutations += $batchSkipped;

            if ($batchDropped > 0 || $batchSkipped > 0) {
                [$latestPressureReport, $latestPressureReportedAt] = $this->newerReport(
                    $latestPressureReport,
                    $latestPressureReportedAt,
                    $batch['reported_at'] ?? null,
                );
            }
        }

        return [
            'dropped_batches' => $droppedBatches,
            'skipped_mutations' => $skippedMutations,
            'reported_at' => $latestPressureReportedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function format(array $metadata, ?Carbon $latestReport = null): string
    {
        $latestReport ??= $this->latestReportAt($metadata);
        $summary = $this->summarize($metadata, $latestReport);
        $parts = [];

        if ($summary['dropped_batches'] > 0) {
            $parts[] = number_format($summary['dropped_batches']).' dropped '.str('batch')->plural($summary['dropped_batches']);
        }

        if ($summary['skipped_mutations'] > 0) {
            $parts[] = number_format($summary['skipped_mutations']).' skipped '.str('mutation')->plural($summary['skipped_mutations']);
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $latestReport ? 'No recent drops reported' : 'No drops reported';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function hasPressure(array $summary): bool
    {
        return (int) ($summary['dropped_batches'] ?? 0) > 0
            || (int) ($summary['skipped_mutations'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function latestReportAt(array $metadata): ?Carbon
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

    public function parseReportedAt(mixed $timestamp): ?Carbon
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

        return $reportedAt->gte($latestReport->copy()->subSeconds(self::RECENT_LOSS_WINDOW_SECONDS));
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

    private function count(mixed $value): int
    {
        return max(0, (int) $value);
    }
}
