<?php

namespace App\Support;

use App\Models\CobrowseSession;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CobrowseTransportReadiness
{
    private const ATTENTION_STATES = [
        'degraded',
        'reconnecting',
        'stale',
    ];

    public function __construct(
        private readonly CobrowseConsentState $cobrowseConsentState,
    ) {}

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    public function check(): array
    {
        $states = $this->activeTransportStates();
        $activeCount = array_sum($states);

        if ($activeCount === 0) {
            return $this->readinessCheck(
                status: 'ready',
                summary: 'No active cobrowse transport samples yet.',
                detail: 'Cobrowse health will appear after a visitor grants consent and the widget reports telemetry.',
                action: 'Run the widget smoke path with cobrowse consent before relying on cobrowse for real visitor support.',
                statusLabel: 'No data yet',
            );
        }

        $attentionCount = $this->attentionCount($states);

        if ($attentionCount > 0) {
            return $this->readinessCheck(
                status: 'attention',
                summary: sprintf(
                    '%d active cobrowse %s %s transport attention.',
                    $attentionCount,
                    str('session')->plural($attentionCount),
                    $attentionCount === 1 ? 'needs' : 'need',
                ),
                detail: 'Aggregate signals: '.$this->stateSummary($states).'. No support codes, visitor identifiers, page URLs, snapshots, or transcripts are shown here.',
                action: 'Use chat to confirm fast-changing page state, request a fresh snapshot when the visitor transport settles, and review the conversation-level cobrowse health panel for the assigned support team.',
            );
        }

        $unreportedCount = $states['unavailable'] ?? 0;

        if ($unreportedCount > 0) {
            return $this->readinessCheck(
                status: 'manual',
                summary: sprintf(
                    '%d active cobrowse %s %s waiting for transport reports.',
                    $unreportedCount,
                    str('session')->plural($unreportedCount),
                    $unreportedCount === 1 ? 'is' : 'are',
                ),
                detail: 'Aggregate signals: '.$this->stateSummary($states).'. This usually means consent was granted but the visitor widget has not reported page state or telemetry yet.',
                action: 'Keep the chat fallback available and confirm the widget can reach the cobrowse telemetry endpoints from the visitor site.',
            );
        }

        return $this->readinessCheck(
            status: 'ready',
            summary: sprintf(
                '%d active cobrowse %s report normal transport.',
                $activeCount,
                str('session')->plural($activeCount),
            ),
            detail: 'Aggregate signals: '.$this->stateSummary($states).'.',
            action: 'Keep dogfooding cobrowse on representative pages and review this check after deploys, Reverb changes, or widget configuration changes.',
        );
    }

    /**
     * @return array<string, int>
     */
    private function activeTransportStates(): array
    {
        if (! Schema::hasTable('cobrowse_sessions')) {
            return [];
        }

        $states = [];

        try {
            CobrowseSession::query()
                ->with('conversation')
                ->where('status', 'granted')
                ->whereNull('ended_at')
                ->chunkById(100, function ($sessions) use (&$states): void {
                    foreach ($sessions as $session) {
                        if (! $session->conversation) {
                            continue;
                        }

                        $conversation = $session->conversation;
                        $conversation->setRelation('latestCobrowseSession', $session);
                        $transport = $this->cobrowseConsentState->queueTransportForConversation($conversation);
                        $state = (string) ($transport['state'] ?? 'unavailable');

                        $states[$state] = ($states[$state] ?? 0) + 1;
                    }
                });
        } catch (Throwable) {
            return [];
        }

        return $states;
    }

    /**
     * @param  array<string, int>  $states
     */
    private function attentionCount(array $states): int
    {
        return array_sum(array_intersect_key($states, array_flip(self::ATTENTION_STATES)));
    }

    /**
     * @param  array<string, int>  $states
     */
    private function stateSummary(array $states): string
    {
        $parts = [];

        foreach (['live', 'degraded', 'reconnecting', 'stale', 'unavailable'] as $state) {
            $count = $states[$state] ?? 0;

            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, str($state)->replace('_', ' '));
            }
        }

        return $parts === [] ? 'no active transport samples' : implode(', ', $parts);
    }

    /**
     * @return array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function readinessCheck(
        string $status,
        string $summary,
        string $detail,
        string $action,
        ?string $statusLabel = null,
    ): array {
        return [
            'action' => $action,
            'detail' => $detail,
            'key' => 'cobrowse_transport',
            'label' => 'Cobrowse transport',
            'status' => $status,
            'status_label' => $statusLabel ?? match ($status) {
                'ready' => 'Ready',
                'manual' => 'Manual check',
                default => 'Needs attention',
            },
            'summary' => $summary,
        ];
    }
}
