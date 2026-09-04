<?php

namespace App\Support\Sla;

use App\Models\SlaClock;
use App\Support\ReaderNumber;
use App\Support\Sites\SiteAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Turn persisted clock facts into page-localized, read-only UI state. */
final class SlaStatePresenter
{
    /** @return Collection<int, array<string, mixed>> */
    public function all(Model $subject, ?CarbonInterface $at = null): Collection
    {
        $at = CarbonImmutable::instance($at ?? now());
        $subject->loadMissing(['site', 'slaClocks']);

        $clocks = $subject->slaClocks->whereNull('cancelled_at');

        if ($subject->getAttribute('status') !== 'closed') {
            $clocks = $clocks->reject(fn (SlaClock $clock): bool => $clock->metric === SlaClock::METRIC_RESOLUTION
                && ! $clock->isActive());
        }

        return $clocks
            ->sortByDesc('id')
            ->unique('metric')
            ->map(fn (SlaClock $clock): array => $this->present($clock, $at))
            ->sortBy(fn (array $state): int => $state['metric'] === SlaClock::METRIC_FIRST_RESPONSE ? 0 : 1)
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function summary(Model $subject, ?CarbonInterface $at = null): ?array
    {
        return $this->all($subject, $at)
            ->sort(function (array $left, array $right): int {
                $urgency = $this->urgency($left['status']) <=> $this->urgency($right['status']);

                return $urgency !== 0
                    ? $urgency
                    : $right['progress_percent'] <=> $left['progress_percent'];
            })
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(SlaClock $clock, CarbonImmutable $at): array
    {
        $active = $clock->isActive();
        $elapsed = (int) $clock->elapsed_seconds;
        $archived = $clock->site?->isArchived() ?? false;

        if ($active && ! $archived && $at->greaterThan($clock->last_counted_at)) {
            $elapsed += SiteAvailability::elapsedOpenSeconds(
                $clock->site,
                CarbonImmutable::instance($clock->last_counted_at),
                $at,
            );
        }

        $breached = $clock->breached_at !== null
            || ($active ? $elapsed >= $clock->target_seconds : $elapsed > $clock->target_seconds);
        $paused = $active && ($archived || ! SiteAvailability::for($clock->site, $at)->open);
        $status = match (true) {
            ! $active && $breached => 'missed',
            ! $active => 'met',
            $breached => 'breached',
            $elapsed >= $clock->warning_seconds => 'warning',
            $paused => 'paused',
            default => 'on_track',
        };
        $remaining = max(0, $clock->target_seconds - $elapsed);
        $over = max(0, $elapsed - $clock->target_seconds);

        return [
            'clock_id' => $clock->id,
            'metric' => $clock->metric,
            'priority' => $clock->priority,
            'status' => $status,
            'tone' => in_array($status, ['breached', 'missed', 'warning'], true)
                ? 'attention'
                : ($status === 'paused' ? 'manual' : 'ready'),
            'label' => __('sla.states.'.$status.'.label'),
            'metric_label' => __('sla.metrics.'.$clock->metric),
            'detail' => match ($status) {
                'breached' => $over === 0
                    ? __('sla.states.breached.reached')
                    : __('sla.states.breached.detail', ['duration' => $this->duration($over)]),
                'missed' => __('sla.states.missed.detail', ['duration' => $this->duration($over)]),
                'met' => __('sla.states.met.detail', ['duration' => $this->duration($elapsed)]),
                'paused' => __('sla.states.paused.detail', ['duration' => $this->duration($remaining)]),
                'warning' => __('sla.states.warning.detail', ['duration' => $this->duration($remaining)]),
                default => __('sla.states.on_track.detail', ['duration' => $this->duration($remaining)]),
            },
            'elapsed_seconds' => $elapsed,
            'target_seconds' => $clock->target_seconds,
            'remaining_seconds' => $remaining,
            'progress_percent' => min(100, (int) floor(($elapsed / max(1, $clock->target_seconds)) * 100)),
        ];
    }

    private function urgency(string $status): int
    {
        return match ($status) {
            'breached' => 0,
            'warning' => 1,
            'paused' => 2,
            'on_track' => 3,
            'missed' => 4,
            default => 5,
        };
    }

    private function duration(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        if ($minutes < 60) {
            return trans_choice('sla.duration.minutes', $minutes, ['count' => ReaderNumber::count($minutes)]);
        }

        $hours = (int) ceil($minutes / 60);

        if ($hours < 24) {
            return trans_choice('sla.duration.hours', $hours, ['count' => ReaderNumber::count($hours)]);
        }

        $days = (int) ceil($hours / 24);

        return trans_choice('sla.duration.days', $days, ['count' => ReaderNumber::count($days)]);
    }
}
