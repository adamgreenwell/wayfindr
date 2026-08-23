<?php

namespace App\Support\Reporting;

use App\Models\AuditEvent;
use App\Models\OperatorSetting;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The half of the page that had the most data and was not shown.
 *
 * Reports launched covering conversations only, whose lifecycle events began
 * on 22 August. Ticket lifecycle -- `ticket.closed`, `ticket.reopened` -- has
 * been audited since 24 May, so this half can describe a full quarter on
 * installs where the conversation half is still accumulating.
 *
 * This has its OWN recording boundary, and the first version wrongly claimed
 * it needed none. "Ticket events predate every install that has tickets" is
 * true of installs created after ticket auditing existed and false of every
 * install upgraded from before it -- where a ticket closed and reopened while
 * nothing was writing `ticket.reopened`, then closed again inside a window, is
 * measured from its original creation and inflates the median with work that
 * was already finished.
 *
 * It is usually much older than the conversation one, which is the honest
 * version of the original claim: this half still has the deeper history, it
 * simply does not have infinite history.
 */
final class TicketReport
{
    public const CLOSED = 'ticket.closed';

    public const REOPENED = 'ticket.reopened';

    public const REPLY_SENT = 'ticket.reply_sent';

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly ReportingScope $scope,
        private readonly ReportingWindow $window,
    ) {}

    /**
     * @return array{opened: array<string, int>, closed: array<string, int>, opened_total: int, closed_total: int, open_now: int}
     */
    public function volume(): array
    {
        return $this->once('volume', function (): array {
            if ($this->scope->isEmpty()) {
                return [
                    'opened' => $this->window->emptyBuckets(),
                    'closed' => $this->window->emptyBuckets(),
                    'opened_total' => 0,
                    'closed_total' => 0,
                    'open_now' => 0,
                ];
            }

            $opened = $this->window->emptyBuckets();
            $closed = $this->window->emptyBuckets();

            // Bucketed in PHP, never grouped in SQL. `date_trunc` is PostgreSQL
            // and `strftime` is SQLite, and the suite runs one while every
            // documented install runs the other.
            foreach ($this->ticketsInWindow()->toBase()->cursor() as $row) {
                $key = $this->window->bucketKey(new \DateTimeImmutable((string) $row->created_at));

                if (array_key_exists($key, $opened)) {
                    $opened[$key]++;
                }
            }

            // From the walk, not from raw rows: a day showing two closes for
            // one ticket that was closed once is the same lie as an inflated
            // median, just harder to notice on a chart.
            foreach ($this->episodes()['closes'] as $close) {
                $key = $this->window->bucketKey($close['at']->toDateTimeImmutable());

                if (array_key_exists($key, $closed)) {
                    $closed[$key]++;
                }
            }

            return [
                'opened' => $opened,
                'closed' => $closed,
                'opened_total' => array_sum($opened),
                'closed_total' => array_sum($closed),
                'open_now' => $this->scopedTickets()->where('status', 'open')->count(),
            ];
        });
    }

    /**
     * Every lifecycle figure on this tab, from one normalised walk.
     *
     * Deliberately not four separate queries. Counting `ticket.closed` rows
     * directly is what made the volume, resolution and agent figures disagree
     * with each other: a double-submitted close is two rows and one resolution,
     * and only the walk knows which is which.
     *
     * @return array{durations: list<int>, unmeasurable: int, closes: list<array{at: CarbonImmutable, actor_type: ?string, actor_id: ?int}>, reopens: list<array{at: CarbonImmutable, actor_type: ?string, actor_id: ?int}>}
     */
    private function episodes(): array
    {
        return $this->once('episodes', function (): array {
            if ($this->scope->isEmpty()) {
                return ['durations' => [], 'unmeasurable' => 0, 'closes' => [], 'reopens' => []];
            }

            // Every ticket with a close OR a reopen on record in the window.
            //
            // Closes alone is not enough, and getting that wrong is invisible:
            // a ticket closed before the range, reopened inside it, and still
            // open at the end has no in-window close, so it would never enter
            // the walk and its reopen would go uncounted -- a resolution that
            // demonstrably did not hold, reported as zero. That is the most
            // interesting event the report has, and the raw reopen query this
            // walk replaced did count it.
            //
            // A ticket whose only in-window close turns out to be a duplicate
            // still contributes nothing, because the walk decides that from its
            // history rather than from why it was selected.
            /** @var list<int> $ticketIds */
            $ticketIds = $this->eventsInWindow(self::CLOSED)
                ->toBase()
                ->distinct()
                ->pluck('subject_id')
                ->merge(
                    $this->eventsInWindow(self::REOPENED)->toBase()->distinct()->pluck('subject_id'),
                )
                ->map(fn (int|string $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            // The same walk the conversation half uses, so a ticket closed
            // three times contributes three resolutions rather than one long
            // one -- and the two halves cannot drift apart on what a resolution
            // measures.
            return ResolutionEpisodes::walk(
                (new Ticket)->getMorphClass(),
                self::CLOSED,
                self::REOPENED,
                $ticketIds,
                fn (array $chunk) => Ticket::query()->whereIn('id', $chunk)->pluck('created_at', 'id'),
                $this->window,
                $this->historyBeganAt(),
            );
        });
    }

    /**
     * @return array{summary: DurationSummary, reopened: int, closed: int, unmeasurable: int}
     */
    public function resolution(): array
    {
        return $this->once('resolution', function (): array {
            $episodes = $this->episodes();

            return [
                'summary' => DurationSummary::fromSeconds($episodes['durations']),
                'unmeasurable' => $episodes['unmeasurable'],
                // Genuine transitions, not rows. A reopen recorded when a
                // PENDING ticket was taken off hold resolved nothing and is not
                // reported as a resolution that failed.
                'reopened' => count($episodes['reopens']),
                'closed' => count($episodes['closes']),
            ];
        });
    }

    /**
     * Who carried the ticket work.
     *
     * Deliberately the same shape the conversation agent table uses, so the two
     * can sit in one place rather than growing a second table beside it.
     *
     * @return list<array{agent: ?User, name: string, replies: int, closes: int}>
     */
    public function agentActivity(): array
    {
        return $this->once('agents', function (): array {
            if ($this->scope->isEmpty()) {
                return [];
            }

            $counts = [];

            // Replies are counted in SQL, exactly as the conversation half
            // counts them: a reply is a reply, with no transition to normalise.
            $replies = $this->eventsInWindow(self::REPLY_SENT)
                ->where('actor_type', User::class)
                ->whereNotNull('actor_id')
                ->selectRaw('actor_id, count(*) as aggregate')
                ->groupBy('actor_id')
                ->pluck('aggregate', 'actor_id');

            foreach ($replies as $actorId => $aggregate) {
                $id = (int) $actorId;
                $counts[$id] ??= ['replies' => 0, 'closes' => 0];
                $counts[$id]['replies'] = (int) $aggregate;
            }

            // Closes come from the walk, so an agent who double-clicked is not
            // credited with two closes -- and the column adds up to the same
            // total the resolution section reports.
            foreach ($this->episodes()['closes'] as $close) {
                if ($close['actor_type'] !== User::class || $close['actor_id'] === null) {
                    continue;
                }

                $counts[$close['actor_id']] ??= ['replies' => 0, 'closes' => 0];
                $counts[$close['actor_id']]['closes']++;
            }

            // Deactivated agents stay listed: they did the work, and a total
            // that changes when somebody leaves is not a total.
            $agents = User::query()->whereIn('id', array_keys($counts))->get()->keyBy('id');

            $rows = [];

            foreach ($counts as $id => $tally) {
                $agent = $agents->get($id);

                $rows[] = [
                    'agent' => $agent,
                    'name' => $agent?->name ?: $agent?->email ?: 'Removed agent',
                    'replies' => $tally['replies'],
                    'closes' => $tally['closes'],
                ];
            }

            usort($rows, fn (array $a, array $b): int => ($b['replies'] + $b['closes']) <=> ($a['replies'] + $a['closes']));

            return $rows;
        });
    }

    /**
     * When this install's ticket history became trustworthy, or null when it
     * always was -- an install with no tickets at the time has nothing that
     * predates the boundary.
     */
    public function historyBeganAt(): ?CarbonImmutable
    {
        return $this->once('history', function (): ?CarbonImmutable {
            $value = OperatorSetting::query()
                ->where('key', 'reporting.ticket_lifecycle_recording_began_at')
                ->value('value');

            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        });
    }

    /**
     * @return Builder<Ticket>
     */
    private function scopedTickets(): Builder
    {
        return Ticket::query()
            ->where('account_id', $this->scope->account->id)
            ->whereIn('site_id', $this->scope->countableSiteIds());
    }

    /**
     * @return Builder<Ticket>
     */
    private function ticketsInWindow(): Builder
    {
        return $this->scopedTickets()->whereBetween('created_at', [$this->window->start, $this->window->end]);
    }

    /**
     * @return Builder<AuditEvent>
     */
    private function eventsInWindow(string $action): Builder
    {
        return AuditEvent::query()
            ->where('account_id', $this->scope->account->id)
            ->whereIn('site_id', $this->scope->countableSiteIds())
            ->where('action', $action)
            ->whereBetween('occurred_at', [$this->window->start, $this->window->end]);
    }

    private function once(string $key, callable $resolve): mixed
    {
        return $this->memo[$key] ??= $resolve();
    }
}
