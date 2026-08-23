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
 *
 * ## Only a transition counts
 *
 * The log is not a clean alternation of close and reopen, and reading it as one
 * produces confident nonsense:
 *
 * - **Consecutive closes.** A double-click, a retry or a stale page submits
 *   close twice, and ticket closes had no write-time guard until this change.
 *   Treating the second as a close makes one resolution contribute two
 *   durations and inflates every count taken from the log.
 * - **Reopens that reopen nothing.** The ticket UI offers the same Reopen
 *   control for a CLOSED ticket and a PENDING one, so `open -> pending ->
 *   reopen -> close` was recorded as a reopen. That claims a resolution failed
 *   when none was ever reached, and restarts the clock at the un-hold, hiding
 *   every hour before the ticket went on hold.
 *
 * So the walk tracks state rather than reacting to actions: a close ends an
 * episode only while the subject is open, and a reopen starts one only while it
 * is closed. Anything else is a duplicate or an unrelated status change and is
 * passed over. Write-time guards now prevent both, but history already recorded
 * cannot be rewritten -- it is read correctly instead.
 */
final class ResolutionEpisodes
{
    private const OPEN = 'open';

    private const CLOSED = 'closed';

    /**
     * The subject predates this install's recording, so its state at the
     * boundary is genuinely unknown -- not assumed open, which would measure a
     * close against a creation time that may be several resolutions stale.
     */
    private const UNKNOWN = 'unknown';

    /**
     * On hold. Distinct from closed, and the distinction is only visible
     * because `ticket.pending` is on record.
     */
    private const PENDING = 'pending';

    /**
     * @param  list<int>  $subjectIds
     * @param  callable(list<int>): Collection<int, mixed>  $openedAt  Creation
     *                                                                 times for one chunk of ids. A callable rather than a prepared map,
     *                                                                 because loading every creation time up front is one bind parameter per
     *                                                                 subject and a busy quarter exceeds the driver's limit -- the page then
     *                                                                 fails outright rather than being slow.
     * @param  CarbonImmutable|null  $recordingBeganAt  When this install's
     *                                                  history for THIS kind of subject became trustworthy, or null when it
     *                                                  always was. Tickets and conversations each have their own boundary.
     * @return array{durations: list<int>, unmeasurable: int, closes: list<array{at: CarbonImmutable, actor_type: ?string, actor_id: ?int}>, reopens: list<array{at: CarbonImmutable, actor_type: ?string, actor_id: ?int}>}
     */
    public static function walk(
        string $morphClass,
        string $closedAction,
        string $reopenedAction,
        array $subjectIds,
        callable $openedAt,
        ReportingWindow $window,
        ?CarbonImmutable $recordingBeganAt,
        ?string $pendingAction = null,
    ): array {
        $durations = [];
        $unmeasurable = 0;
        $closes = [];
        $reopens = [];

        // Chunked because a quarter of closes is an unbounded number of bind
        // parameters.
        foreach (array_chunk($subjectIds, 500) as $chunk) {
            $created = $openedAt($chunk);

            $events = AuditEvent::query()
                ->where('subject_type', $morphClass)
                ->whereIn('subject_id', $chunk)
                ->whereIn('action', array_values(array_filter([$closedAction, $reopenedAction, $pendingAction])))
                ->orderBy('subject_id')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->toBase()
                ->get(['subject_id', 'action', 'occurred_at', 'actor_type', 'actor_id']);

            foreach ($events->groupBy('subject_id') as $subjectId => $subjectEvents) {
                $start = $created[(int) $subjectId] ?? null;

                if ($start === null) {
                    continue;
                }

                $episodeStart = CarbonImmutable::parse($start);

                // Whether the creation time can be trusted as an episode start.
                // A subject that predates recording may have been closed and
                // reopened before anything was written down, in which case
                // measuring from creation charges this close with stretches of
                // work that were already resolved.
                $episodeStartIsKnown = $recordingBeganAt === null
                    || $episodeStart->greaterThanOrEqualTo($recordingBeganAt);

                // Everything is created open. A subject older than the boundary
                // may have closed since, unrecorded, so its state is unknown --
                // and a close arriving in that state is counted without being
                // measured, exactly as an unknown start is.
                $state = $episodeStartIsKnown ? self::OPEN : self::UNKNOWN;

                foreach ($subjectEvents as $event) {
                    $at = CarbonImmutable::parse($event->occurred_at);
                    $inWindow = $at->betweenIncluded($window->start, $window->end);

                    // Being put on hold settles a state that was unknown, which
                    // is the whole reason for reading these. History recorded
                    // before the write-path guard existed wrote `reopened` for
                    // an un-hold, and from UNKNOWN that is indistinguishable
                    // from a genuine reopen -- unless the `pending` that
                    // preceded it is also on record, which it is.
                    if ($pendingAction !== null && $event->action === $pendingAction) {
                        $state = self::PENDING;

                        continue;
                    }

                    if ($event->action === $reopenedAction) {
                        // Only from closed, or from unknown. From OPEN it
                        // reopens nothing, and from PENDING it is an un-hold --
                        // which the current write path records as its own
                        // action, but older history recorded as a reopen.
                        if ($state === self::OPEN || $state === self::PENDING) {
                            $state = self::OPEN;

                            continue;
                        }

                        $state = self::OPEN;
                        $episodeStart = $at;
                        $episodeStartIsKnown = true;

                        if ($inWindow) {
                            $reopens[] = self::entry($at, $event);
                        }

                        continue;
                    }

                    // A close while already closed is the duplicate submission,
                    // not a second resolution. Closing from PENDING is real --
                    // a ticket on hold can be resolved without being reopened
                    // first.
                    if ($state === self::CLOSED) {
                        continue;
                    }

                    $state = self::CLOSED;

                    if (! $inWindow) {
                        continue;
                    }

                    $closes[] = self::entry($at, $event);

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

        return [
            'durations' => $durations,
            'unmeasurable' => $unmeasurable,
            'closes' => $closes,
            'reopens' => $reopens,
        ];
    }

    /**
     * @return array{at: CarbonImmutable, actor_type: ?string, actor_id: ?int}
     */
    private static function entry(CarbonImmutable $at, mixed $event): array
    {
        return [
            'at' => $at,
            'actor_type' => $event->actor_type === null ? null : (string) $event->actor_type,
            'actor_id' => $event->actor_id === null ? null : (int) $event->actor_id,
        ];
    }
}
