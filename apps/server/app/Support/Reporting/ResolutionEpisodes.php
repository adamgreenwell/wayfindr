<?php

namespace App\Support\Reporting;

use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How long each close took, measured from the moment that close began.
 *
 * A thing closed three times contributes three resolutions, not one long one --
 * which is the payoff of ADR 0015 recording the sequence rather than a
 * `reopen_count` column, because a column cannot answer it. The reopen that
 * starts an episode is often older than the reporting window, so the walk needs
 * each subject's whole history and filters on the CLOSE.
 *
 * Shared by conversations and tickets rather than written twice. #775 named
 * exactly this risk: "a second implementation of 'measure from the reopen that
 * started it' is how the two halves come to disagree" -- and two halves of one
 * page disagreeing about resolution time is worse than one of them missing.
 */
final class ResolutionEpisodes
{
    /**
     * @param  list<int>  $subjectIds
     * @param  Collection<int, mixed>  $openedAt  Subject id => creation time.
     * @param  CarbonImmutable|null  $recordingBeganAt  When this install's
     *                                                  history for THIS kind of subject became trustworthy, or null when it
     *                                                  always was. Tickets predate the reporting work; conversations do not.
     * @return array{durations: list<int>, unmeasurable: int}
     */
    public static function walk(
        string $morphClass,
        string $closedAction,
        string $reopenedAction,
        array $subjectIds,
        Collection $openedAt,
        ReportingWindow $window,
        ?CarbonImmutable $recordingBeganAt,
    ): array {
        $durations = [];
        $unmeasurable = 0;

        // Chunked because a quarter of closes is an unbounded number of bind
        // parameters.
        foreach (array_chunk($subjectIds, 500) as $chunk) {
            $events = AuditEvent::query()
                ->where('subject_type', $morphClass)
                ->whereIn('subject_id', $chunk)
                ->whereIn('action', [$closedAction, $reopenedAction])
                ->orderBy('subject_id')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->toBase()
                ->get(['subject_id', 'action', 'occurred_at']);

            foreach ($events->groupBy('subject_id') as $subjectId => $subjectEvents) {
                $start = $openedAt[(int) $subjectId] ?? null;

                if ($start === null) {
                    continue;
                }

                $episodeStart = CarbonImmutable::parse($start);

                // Whether the creation time can be trusted as an episode start.
                // A subject that predates recording may have been closed and
                // reopened before anything was written down, in which case
                // measuring from creation charges this close with stretches of
                // work that were already resolved. Once a reopen has been seen,
                // the episode start is known however old the subject is.
                $episodeStartIsKnown = $recordingBeganAt === null
                    || $episodeStart->greaterThanOrEqualTo($recordingBeganAt);

                foreach ($subjectEvents as $event) {
                    $at = CarbonImmutable::parse($event->occurred_at);

                    if ($event->action === $reopenedAction) {
                        $episodeStart = $at;
                        $episodeStartIsKnown = true;

                        continue;
                    }

                    if (! $at->betweenIncluded($window->start, $window->end)) {
                        continue;
                    }

                    if (! $episodeStartIsKnown) {
                        // Counted as a close, never as a duration. An inflated
                        // median is invisible; a smaller sample the page names
                        // is not.
                        $unmeasurable++;

                        continue;
                    }

                    $durations[] = max(0, $at->getTimestamp() - $episodeStart->getTimestamp());
                }
            }
        }

        return ['durations' => $durations, 'unmeasurable' => $unmeasurable];
    }
}
