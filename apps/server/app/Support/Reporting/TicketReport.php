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

            foreach ($this->eventsInWindow(self::CLOSED)->toBase()->cursor() as $row) {
                $key = $this->window->bucketKey(new \DateTimeImmutable((string) $row->occurred_at));

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
     * @return array{summary: DurationSummary, reopened: int, closed: int, unmeasurable: int}
     */
    public function resolution(): array
    {
        return $this->once('resolution', function (): array {
            if ($this->scope->isEmpty()) {
                return ['summary' => DurationSummary::empty(), 'reopened' => 0, 'closed' => 0, 'unmeasurable' => 0];
            }

            /** @var list<int> $ticketIds */
            $ticketIds = $this->eventsInWindow(self::CLOSED)
                ->toBase()
                ->distinct()
                ->pluck('subject_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            // The same walk the conversation half uses, so a ticket closed
            // three times contributes three resolutions rather than one long
            // one -- and the two halves cannot drift apart on what a resolution
            // measures.
            ['durations' => $durations, 'unmeasurable' => $unmeasurable] = ResolutionEpisodes::walk(
                (new Ticket)->getMorphClass(),
                self::CLOSED,
                self::REOPENED,
                $ticketIds,
                fn (array $chunk) => Ticket::query()->whereIn('id', $chunk)->pluck('created_at', 'id'),
                $this->window,
                $this->historyBeganAt(),
            );

            return [
                'summary' => DurationSummary::fromSeconds($durations),
                'unmeasurable' => $unmeasurable,
                'reopened' => $this->eventsInWindow(self::REOPENED)->count(),
                'closed' => $this->eventsInWindow(self::CLOSED)->count(),
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

            // Counted in SQL, exactly as the conversation half counts. Reading
            // every row back to tally them in PHP means a quarter of ticket
            // activity crosses the wire to produce one integer per agent.
            $counts = [];

            foreach ([self::REPLY_SENT => 'replies', self::CLOSED => 'closes'] as $action => $key) {
                $tallies = $this->eventsInWindow($action)
                    ->where('actor_type', User::class)
                    ->whereNotNull('actor_id')
                    ->selectRaw('actor_id, count(*) as aggregate')
                    ->groupBy('actor_id')
                    ->pluck('aggregate', 'actor_id');

                foreach ($tallies as $actorId => $aggregate) {
                    $id = (int) $actorId;
                    $counts[$id] ??= ['replies' => 0, 'closes' => 0];
                    $counts[$id][$key] = (int) $aggregate;
                }
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
