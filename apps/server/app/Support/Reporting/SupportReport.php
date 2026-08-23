<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\UnattendedConversationAlertCollector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the desk did over a span of days.
 *
 * Two rules shape every query in here.
 *
 * **Nothing is grouped or truncated by date in SQL.** See {@see ReportingWindow}
 * -- there is no spelling of "group by day" that means the same thing on SQLite
 * and PostgreSQL, and this suite runs on one while every install runs on the
 * other. Rows are streamed and bucketed in PHP so both databases agree.
 *
 * **Two of these numbers are older than others, and the report says so.**
 * Conversation opens and first-response times are recoverable from data the
 * product has always kept. Closes, resolution times and reopens are read from
 * lifecycle audit events, which only started being written in the release that
 * added them (ADR 0015). {@see self::historyBeganAt()} is what lets the screen
 * distinguish "nothing happened" from "nothing was recorded", and presenting a
 * short series as though it were complete is the failure this exists to
 * prevent.
 */
final class SupportReport
{
    /**
     * Where the instant lifecycle recording became trustworthy is stamped.
     *
     * @see database/migrations/2026_08_23_000000_record_when_conversation_lifecycle_recording_began.php
     */
    private const RECORDING_BEGAN_KEY = 'reporting.lifecycle_recording_began_at';

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly ReportingScope $scope,
        private readonly ReportingWindow $window,
    ) {}

    /**
     * Conversations opened and closed per day, plus the current open count.
     *
     * @return array{opened: array<string, int>, closed: array<string, int>, opened_total: int, closed_total: int, open_now: int}
     */
    public function volume(): array
    {
        return $this->once('volume', function (): array {
            $opened = $this->window->emptyBuckets();
            $closed = $this->window->emptyBuckets();

            foreach ($this->streamTimestamps($this->openedInWindow(), 'created_at') as $at) {
                $key = $this->window->bucketKey($at);
                if (array_key_exists($key, $opened)) {
                    $opened[$key]++;
                }
            }

            foreach ($this->streamTimestamps($this->lifecycleEventsInWindow(ConversationLifecycleLog::CLOSED), 'occurred_at') as $at) {
                $key = $this->window->bucketKey($at);
                if (array_key_exists($key, $closed)) {
                    $closed[$key]++;
                }
            }

            return [
                'opened' => $opened,
                'closed' => $closed,
                'opened_total' => array_sum($opened),
                'closed_total' => array_sum($closed),
                'open_now' => $this->scope->isEmpty() ? 0 : $this->scopedConversations()->where('status', 'open')->count(),
            ];
        });
    }

    /**
     * How long visitors waited for a first human reply.
     *
     * Measured from when the conversation was opened, which is when the visitor
     * started waiting. Conversations still awaiting a first reply are counted
     * separately rather than folded in as a zero or dropped silently: a desk
     * that answers half its conversations quickly and ignores the rest would
     * otherwise report an excellent median.
     *
     * @return array{summary: DurationSummary, awaiting: int}
     */
    public function firstResponse(): array
    {
        return $this->once('first_response', function (): array {
            $durations = [];
            $awaiting = 0;

            if (! $this->scope->isEmpty()) {
                $query = $this->openedInWindow()->addSelect([
                    'first_agent_reply_at' => ConversationMessage::query()
                        ->selectRaw('min(conversation_messages.created_at)')
                        ->whereColumn('conversation_messages.conversation_id', 'conversations.id')
                        ->where('conversation_messages.sender_type', User::class),
                ]);

                foreach ($query->toBase()->cursor() as $row) {
                    $repliedAt = $row->first_agent_reply_at ?? null;

                    if ($repliedAt === null) {
                        $awaiting++;

                        continue;
                    }

                    $opened = CarbonImmutable::parse($row->created_at);
                    $replied = CarbonImmutable::parse($repliedAt);

                    // A reply cannot land before the conversation exists, but
                    // clock skew across a restore or an import can say it did.
                    // Clamping beats letting a negative duration drag a median
                    // below zero.
                    $durations[] = max(0, $replied->getTimestamp() - $opened->getTimestamp());
                }
            }

            // Deliberately not counted here: how many of these exceeded the
            // unattended-alert threshold. That threshold asks "has anyone
            // *looked* at this in five minutes", which almost every first reply
            // misses, so the figure read "nearly all of them" whatever the desk
            // did. A number that is always alarming is the resting-state amber
            // pill ADR 0014 removed. The median and the p90 answer how fast
            // without borrowing a number that means something else.
            return [
                'summary' => DurationSummary::fromSeconds($durations),
                'awaiting' => $awaiting,
            ];
        });
    }

    /**
     * How long resolutions took, and how often they did not hold.
     *
     * A conversation closed three times contributes three resolutions, each
     * measured from the reopen that preceded it rather than all from the
     * original open. The second close ended the second stretch of work; charging
     * it with the time before the first close would describe work nobody did.
     *
     * This is the payoff of recording the sequence instead of a `reopen_count`
     * column -- the column could not answer it.
     *
     * Closes whose episode start predates recording are counted but not
     * measured: a conversation older than the lifecycle log may have been
     * closed and reopened before anything was written down, and measuring from
     * its creation would silently charge this close with work that was already
     * finished. {@see self::resolution()} reports how many were set aside so a
     * shorter sample is visible rather than a longer one being wrong.
     *
     * @return array{summary: DurationSummary, unmeasurable: int, reopened: int, reopened_by_visitor: int}
     */
    public function resolution(): array
    {
        return $this->once('resolution', function (): array {
            if ($this->scope->isEmpty()) {
                return ['summary' => DurationSummary::empty(), 'unmeasurable' => 0, 'reopened' => 0, 'reopened_by_visitor' => 0];
            }

            $conversationIds = $this->lifecycleEventsInWindow(ConversationLifecycleLog::CLOSED)
                ->toBase()
                ->distinct()
                ->pluck('subject_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $durations = [];
            $unmeasurable = 0;

            $recordingBegan = $this->historyBeganAt();

            // The reopen that starts an episode can be older than the window,
            // so the walk needs each conversation's whole history -- but only
            // for conversations that actually closed inside it. Chunked because
            // a quarter of closes is an unbounded number of bind parameters.
            foreach (array_chunk($conversationIds, 500) as $chunk) {
                $openedAt = Conversation::query()
                    ->whereIn('id', $chunk)
                    ->pluck('created_at', 'id');

                $events = AuditEvent::query()
                    ->where('subject_type', (new Conversation)->getMorphClass())
                    ->whereIn('subject_id', $chunk)
                    ->whereIn('action', [ConversationLifecycleLog::CLOSED, ConversationLifecycleLog::REOPENED])
                    ->orderBy('subject_id')
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->toBase()
                    ->get(['subject_id', 'action', 'occurred_at']);

                foreach ($events->groupBy('subject_id') as $subjectId => $conversationEvents) {
                    $start = $openedAt[(int) $subjectId] ?? null;

                    if ($start === null) {
                        continue;
                    }

                    $episodeStart = CarbonImmutable::parse($start);

                    // Whether the creation time can be trusted as an episode
                    // start. A conversation that predates recording may have
                    // been closed and reopened before anything was written
                    // down, in which case measuring from creation charges this
                    // close with stretches of work that were already resolved.
                    // Once a reopen has been seen, the episode start is known
                    // regardless of how old the conversation is.
                    $episodeStartIsKnown = $recordingBegan === null
                        || $episodeStart->greaterThanOrEqualTo($recordingBegan);

                    foreach ($conversationEvents as $event) {
                        $at = CarbonImmutable::parse($event->occurred_at);

                        if ($event->action === ConversationLifecycleLog::REOPENED) {
                            $episodeStart = $at;
                            $episodeStartIsKnown = true;

                            continue;
                        }

                        if (! $at->betweenIncluded($this->window->start, $this->window->end)) {
                            continue;
                        }

                        if (! $episodeStartIsKnown) {
                            // Counted as a close, never as a duration. An
                            // inflated median is invisible; a smaller sample
                            // that the page names is not.
                            $unmeasurable++;

                            continue;
                        }

                        $durations[] = max(0, $at->getTimestamp() - $episodeStart->getTimestamp());
                    }
                }
            }

            $reopened = $this->lifecycleEventsInWindow(ConversationLifecycleLog::REOPENED);

            return [
                'summary' => DurationSummary::fromSeconds($durations),
                'unmeasurable' => $unmeasurable,
                'reopened' => (clone $reopened)->count(),
                // `audit_events.metadata` is a real json column, so a JSON path
                // filter is safe here -- unlike `notifications.data`, which is
                // TEXT and breaks the same query on PostgreSQL.
                'reopened_by_visitor' => (clone $reopened)->where('metadata->actor', 'visitor')->count(),
            ];
        });
    }

    /**
     * Who carried the work.
     *
     * Replies and closes per agent, so an imbalance is visible while it is still
     * a scheduling problem. Deactivated agents still appear: they did the work,
     * and a total that changes when someone leaves is not a total.
     *
     * @return list<array{agent: ?User, name: string, replies: int, closes: int}>
     */
    public function agentActivity(): array
    {
        return $this->once('agent_activity', function (): array {
            if ($this->scope->isEmpty()) {
                return [];
            }

            $replies = ConversationMessage::query()
                ->where('sender_type', User::class)
                ->whereBetween('conversation_messages.created_at', [$this->window->start, $this->window->end])
                ->whereIn('conversation_id', $this->scopedConversations()->select('conversations.id'))
                ->selectRaw('sender_id, count(*) as aggregate')
                ->groupBy('sender_id')
                ->pluck('aggregate', 'sender_id');

            $closes = $this->lifecycleEventsInWindow(ConversationLifecycleLog::CLOSED)
                ->where('actor_type', User::class)
                ->selectRaw('actor_id, count(*) as aggregate')
                ->groupBy('actor_id')
                ->pluck('aggregate', 'actor_id');

            $ids = collect($replies->keys())
                ->merge($closes->keys())
                ->filter(fn (mixed $id): bool => $id !== null)
                ->map(fn (int|string $id): int => (int) $id)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return [];
            }

            $agents = User::query()->whereIn('id', $ids)->get()->keyBy('id');

            $rows = $ids
                ->map(function (int $id) use ($agents, $replies, $closes): array {
                    $agent = $agents->get($id);

                    return [
                        'agent' => $agent,
                        // An agent whose account row is gone still has work
                        // attributed to them; the report says so rather than
                        // dropping the rows and losing the total.
                        'name' => $agent?->name ?? 'Removed agent',
                        'replies' => (int) ($replies[$id] ?? 0),
                        'closes' => (int) ($closes[$id] ?? 0),
                    ];
                })
                ->sortByDesc(fn (array $row): int => $row['replies'] + $row['closes'])
                ->values()
                ->all();

            return $rows;
        });
    }

    /**
     * Conversations waiting on the desk right now.
     *
     * A point-in-time figure rather than a trend, and deliberately so. The
     * historical version would have to be reconstructed from
     * {@see UnattendedConversationAlertCollector}, which cannot answer it: that
     * definition reads unread notification state, and reading a notification
     * destroys the evidence. What is reused instead is its threshold, so the
     * report and the alerts cannot disagree about how long is too long.
     *
     * @return array{needs_reply: int, oldest_wait_seconds: ?int, threshold_minutes: int}
     */
    public function queueHealth(): array
    {
        return $this->once('queue_health', function (): array {
            $threshold = UnattendedConversationAlertCollector::THRESHOLD_MINUTES;

            if ($this->scope->isEmpty()) {
                return ['needs_reply' => 0, 'oldest_wait_seconds' => null, 'threshold_minutes' => $threshold];
            }

            $waiting = $this->scopedConversations()
                ->where('status', 'open')
                ->where(fn (Builder $query) => $query
                    ->whereDoesntHave('messages')
                    ->orWhereHas('latestMessage', fn (Builder $latest) => $latest->where('sender_type', '!=', User::class)))
                ->toBase()
                ->get(['conversations.id', 'conversations.last_message_at', 'conversations.created_at']);

            $oldest = null;

            foreach ($waiting as $conversation) {
                $since = CarbonImmutable::parse($conversation->last_message_at ?? $conversation->created_at);
                $seconds = max(0, CarbonImmutable::now()->getTimestamp() - $since->getTimestamp());
                $oldest = $oldest === null ? $seconds : max($oldest, $seconds);
            }

            return [
                'needs_reply' => $waiting->count(),
                'oldest_wait_seconds' => $oldest,
                'threshold_minutes' => $threshold,
            ];
        });
    }

    /**
     * When conversation lifecycle recording began for this account.
     *
     * Null means it never has -- no conversation has been closed or reopened
     * since the release that started recording. Either way the screen needs it,
     * because a flat line before this date is an absence of records, not an
     * absence of work, and only this date can tell the two apart.
     */
    public function historyBeganAt(): ?CarbonImmutable
    {
        return $this->once('history_began_at', function (): ?CarbonImmutable {
            // An install fact, stamped once when the recording release was
            // migrated. It cannot be derived from the events themselves: the
            // earliest event belongs to a conversation created before it, so
            // using it as the boundary would declare the first close on every
            // install unmeasurable and would move every time old history was
            // purged.
            $stamped = OperatorSetting::query()
                ->where('key', self::RECORDING_BEGAN_KEY)
                ->value('value');

            // Null is a real answer, not a missing one: it means this install
            // held no conversations when recording began, so nothing predates
            // the log and every close is measurable.
            return is_string($stamped) && $stamped !== ''
                ? CarbonImmutable::parse($stamped)
                : null;
        });
    }

    /**
     * True when the window reaches back further than the records do.
     */
    public function historyIsPartial(): bool
    {
        $began = $this->historyBeganAt();

        return $began !== null && $began->greaterThan($this->window->start);
    }

    /**
     * @return Builder<Conversation>
     */
    private function scopedConversations(): Builder
    {
        // The site allowlist is resolved from the account's own sites, so
        // pinning site_id pins the account with it.
        return Conversation::query()->whereIn('conversations.site_id', $this->scope->countableSiteIds());
    }

    /**
     * @return Builder<Conversation>
     */
    private function openedInWindow(): Builder
    {
        return $this->scopedConversations()
            ->whereBetween('conversations.created_at', [$this->window->start, $this->window->end]);
    }

    /**
     * @return Builder<AuditEvent>
     */
    private function scopedAuditEvents(): Builder
    {
        return AuditEvent::query()
            ->where('account_id', $this->scope->account->id)
            ->whereIn('site_id', $this->scope->countableSiteIds());
    }

    /**
     * @return Builder<AuditEvent>
     */
    private function lifecycleEventsInWindow(string $action): Builder
    {
        return $this->scopedAuditEvents()
            ->where('action', $action)
            ->whereBetween('occurred_at', [$this->window->start, $this->window->end]);
    }

    /**
     * Stream one timestamp column without hydrating models.
     *
     * A ninety-day report can cross a lot of rows, and every one of them would
     * otherwise become an Eloquent object just to have a date read off it.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return iterable<CarbonImmutable>
     */
    private function streamTimestamps(Builder $query, string $column): iterable
    {
        if ($this->scope->isEmpty()) {
            return;
        }

        foreach ($query->toBase()->cursor() as $row) {
            $value = $row->{$column} ?? null;

            if ($value !== null) {
                yield CarbonImmutable::parse($value);
            }
        }
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    private function once(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }

        /** @var TValue */
        return $this->memo[$key];
    }
}
