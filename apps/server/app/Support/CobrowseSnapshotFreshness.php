<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

class CobrowseSnapshotFreshness
{
    private const AGING_AFTER_SECONDS = 120;

    private const STALE_AFTER_SECONDS = 300;

    /**
     * @return array{state: string, label: string, message: string, reported_at: string, reported_label: string, tone: string}
     */
    public function format(mixed $reportedAt): array
    {
        $reportedAt = $this->parseReportedAt($reportedAt);

        if (! $reportedAt) {
            return [
                'state' => 'unknown',
                'label' => 'Time unknown',
                'message' => 'Use chat to confirm what the visitor sees before relying on this preview.',
                'reported_at' => 'Report time unavailable',
                'reported_label' => 'Report time unavailable',
                'tone' => 'manual',
            ];
        }

        $reportedAtLabel = $reportedAt->diffForHumans();

        if ($reportedAt->lt(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
            return [
                'state' => 'stale',
                'label' => 'Stale',
                'message' => 'Snapshot is older than 5 minutes. Confirm through chat or request a fresh snapshot.',
                'reported_at' => $reportedAtLabel,
                'reported_label' => 'Reported '.$reportedAtLabel,
                'tone' => 'attention',
            ];
        }

        if ($reportedAt->lt(now()->subSeconds(self::AGING_AFTER_SECONDS))) {
            return [
                'state' => 'aging',
                'label' => 'Aging',
                'message' => 'Snapshot is a few minutes old. Request a fresh snapshot if this page is changing.',
                'reported_at' => $reportedAtLabel,
                'reported_label' => 'Reported '.$reportedAtLabel,
                'tone' => 'manual',
            ];
        }

        return [
            'state' => 'fresh',
            'label' => 'Fresh',
            'message' => 'Snapshot was reported recently.',
            'reported_at' => $reportedAtLabel,
            'reported_label' => 'Reported '.$reportedAtLabel,
            'tone' => 'ready',
        ];
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
}
