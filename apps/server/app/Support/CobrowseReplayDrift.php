<?php

namespace App\Support;

/**
 * Turns replay-apply outcome counts into a drift signal for agents.
 *
 * Mutations are addressed by nth-of-type paths. When the visitor's live DOM
 * drifts from the snapshot the agent is viewing, those paths stop resolving and
 * are counted as "unresolved". A sustained share of unresolved mutations means
 * the preview no longer reflects reality, so the agent should request a fresh
 * snapshot rather than trusting an increasingly stale reconstruction.
 *
 * Only unresolved mutations count as drift. Unsupported types and malformed
 * records are noise about mutation shape, not tree drift, so they are reported
 * separately and excluded from the ratio.
 *
 * See issue #486 and docs/privacy/cobrowse-data-boundaries.md.
 */
class CobrowseReplayDrift
{
    /**
     * Minimum addressable mutations before a ratio is meaningful. Below this a
     * single miss should not alarm an agent.
     */
    private const MIN_SAMPLE = 5;

    private const WATCH_RATIO = 0.25;

    private const RESYNC_RATIO = 0.5;

    /**
     * @param  array{applied?: int, unresolved?: int, unsupported?: int, invalid?: int}  $counts
     * @return array{state: string, label: string, message: string, summary: string, drift_count: int, addressable: int, ratio: float, tone: string, recommend_resync: bool}
     */
    public function evaluate(array $counts): array
    {
        $applied = max(0, (int) ($counts['applied'] ?? 0));
        $unresolved = max(0, (int) ($counts['unresolved'] ?? 0));
        $addressable = $applied + $unresolved;
        $ratio = $addressable > 0 ? $unresolved / $addressable : 0.0;
        $summary = number_format($unresolved).' of '.number_format($addressable).' drifted';

        if ($addressable < self::MIN_SAMPLE || $unresolved === 0) {
            return [
                'state' => 'steady',
                'label' => 'Aligned',
                'message' => 'Replay updates are landing on the expected nodes.',
                'summary' => $summary,
                'drift_count' => $unresolved,
                'addressable' => $addressable,
                'ratio' => round($ratio, 2),
                'tone' => 'ready',
                'recommend_resync' => false,
            ];
        }

        if ($ratio >= self::RESYNC_RATIO) {
            return [
                'state' => 'drifting',
                'label' => 'Drifting',
                'message' => 'Many replay updates no longer match this preview. Request a fresh snapshot to resync.',
                'summary' => $summary,
                'drift_count' => $unresolved,
                'addressable' => $addressable,
                'ratio' => round($ratio, 2),
                'tone' => 'attention',
                'recommend_resync' => true,
            ];
        }

        if ($ratio >= self::WATCH_RATIO) {
            return [
                'state' => 'watch',
                'label' => 'Minor drift',
                'message' => 'Some replay updates did not match this preview. Confirm fast-changing areas through chat.',
                'summary' => $summary,
                'drift_count' => $unresolved,
                'addressable' => $addressable,
                'ratio' => round($ratio, 2),
                'tone' => 'manual',
                'recommend_resync' => false,
            ];
        }

        return [
            'state' => 'steady',
            'label' => 'Aligned',
            'message' => 'Replay updates are landing on the expected nodes.',
            'summary' => $summary,
            'drift_count' => $unresolved,
            'addressable' => $addressable,
            'ratio' => round($ratio, 2),
            'tone' => 'ready',
            'recommend_resync' => false,
        ];
    }
}
