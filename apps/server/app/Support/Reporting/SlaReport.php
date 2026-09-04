<?php

namespace App\Support\Reporting;

use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use Carbon\CarbonImmutable;

/** Breach history from the same persisted clocks agents see in their queues. */
final class SlaReport
{
    public function __construct(private readonly ReportingScope $scope, private readonly ReportingWindow $window) {}

    /**
     * @return array{
     *   breached: int,
     *   first_response: int,
     *   resolution: int,
     *   conversations: int,
     *   tickets: int,
     *   active_warning: int,
     *   active_breached: int,
     *   by_priority: array<string, int>,
     *   recent: list<array{metric: string, priority: string, breached_at: CarbonImmutable, reference: string, subject: string, url: string}>
     * }
     */
    public function history(): array
    {
        $empty = [
            'breached' => 0,
            'first_response' => 0,
            'resolution' => 0,
            'conversations' => 0,
            'tickets' => 0,
            'active_warning' => 0,
            'active_breached' => 0,
            'by_priority' => array_fill_keys(['urgent', 'high', 'normal', 'low'], 0),
            'recent' => [],
        ];

        if ($this->scope->isEmpty()) {
            return $empty;
        }

        $base = SlaClock::query()->whereIn('site_id', $this->scope->countableSiteIds());
        $breached = (clone $base)
            ->whereBetween('breached_at', [$this->window->start, $this->window->end]);
        $countsByMetric = (clone $breached)
            ->selectRaw('metric, count(*) as aggregate')
            ->groupBy('metric')
            ->pluck('aggregate', 'metric');
        $countsByType = (clone $breached)
            ->selectRaw('subject_type, count(*) as aggregate')
            ->groupBy('subject_type')
            ->pluck('aggregate', 'subject_type');
        $countsByPriority = (clone $breached)
            ->selectRaw('priority, count(*) as aggregate')
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        return [
            'breached' => (clone $breached)->count(),
            'first_response' => (int) ($countsByMetric[SlaClock::METRIC_FIRST_RESPONSE] ?? 0),
            'resolution' => (int) ($countsByMetric[SlaClock::METRIC_RESOLUTION] ?? 0),
            'conversations' => (int) ($countsByType[(new Conversation)->getMorphClass()] ?? 0),
            'tickets' => (int) ($countsByType[(new Ticket)->getMorphClass()] ?? 0),
            'active_warning' => (clone $base)
                ->whereNotNull('warned_at')
                ->whereNull('breached_at')
                ->whereNull('satisfied_at')
                ->whereNull('cancelled_at')
                ->count(),
            'active_breached' => (clone $base)
                ->whereNotNull('breached_at')
                ->whereNull('satisfied_at')
                ->whereNull('cancelled_at')
                ->count(),
            'by_priority' => collect($empty['by_priority'])
                ->map(fn (int $_count, string $priority): int => (int) ($countsByPriority[$priority] ?? 0))
                ->all(),
            'recent' => (clone $breached)
                ->with('subject')
                ->latest('breached_at')
                ->latest('id')
                ->limit(25)
                ->get()
                ->filter->subject
                ->map(function (SlaClock $clock): array {
                    $ticket = $clock->subject instanceof Ticket ? $clock->subject : null;
                    $conversation = $clock->subject instanceof Conversation ? $clock->subject : null;

                    return [
                        'metric' => $clock->metric,
                        'priority' => $clock->priority,
                        'breached_at' => CarbonImmutable::instance($clock->breached_at),
                        'reference' => $ticket ? 'Ticket #'.$ticket->id : (string) $conversation?->support_code,
                        'subject' => (string) ($clock->subject->subject ?? ''),
                        'url' => $ticket
                            ? route('dashboard.tickets.show', $ticket)
                            : route('dashboard.conversations.show', $conversation?->support_code),
                    ];
                })
                ->values()
                ->all(),
        ];
    }
}
