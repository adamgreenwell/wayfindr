<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReadState;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\Sites\SiteAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The middle alert cadence: email only when a visitor message has waited
 * UNSEEN past the threshold. "Unseen" is the unread ConversationNeedsReply
 * notification — opening the conversation marks it read — so no presence
 * tracking is needed: if nobody has seen it in five minutes, nobody is
 * effectively online.
 */
class UnattendedConversationAlertCollector
{
    public const UNATTENDED_EMAILED_AT_KEY = 'unattended_emailed_at';

    public const WAITING_SINCE_KEY = 'unattended_waiting_since';

    public const THRESHOLD_MINUTES = 5;

    /**
     * @return Collection<int, array{
     *     notification_id: string,
     *     reference: string,
     *     site_name: string,
     *     subject: string,
     *     waiting_since: string|null,
     *     url: string
     * }>
     */
    public function forAgent(User $agent): Collection
    {
        if (! $this->agentWantsUnattendedAlerts($agent)) {
            return collect();
        }

        return $agent
            ->unreadNotifications()
            ->where('type', ConversationNeedsReply::class)
            ->latest()
            ->get()
            // The episode clock, not the notification row's age: a re-armed
            // notification restarts its wait when a new episode begins.
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            // One email per waiting episode: the stamp lives on the unread
            // notification and is dropped when a new episode begins.
            ->reject(fn (DatabaseNotification $notification): bool => filled(data_get($notification->data, self::UNATTENDED_EMAILED_AT_KEY)))
            ->map(fn (DatabaseNotification $notification): ?array => $this->candidateFor($agent, $notification))
            ->filter()
            ->values();
    }

    private function agentWantsUnattendedAlerts(User $agent): bool
    {
        return ! $agent->isDeactivated()
            && $agent->wantsUnattendedAlertEmail();
    }

    /** Keep the shared queue/alert clock aligned with human message episodes. */
    public function conversationMessageCreated(ConversationMessage $message): void
    {
        $conversation = $message->conversation()->first();

        if (! $conversation) {
            return;
        }

        if ($message->sender_type === User::class) {
            $this->clearConversationWait($conversation);

            return;
        }

        if ($message->sender_type !== Visitor::class || $conversation->status === 'closed') {
            return;
        }

        $startedAt = $conversation->support_wait_started_at
            ? CarbonImmutable::instance($conversation->support_wait_started_at)
            : null;

        if ($startedAt === null || $this->anyAgentSawSince((int) $conversation->id, $startedAt)) {
            $this->resetConversationWait($conversation, $message->created_at ?? now());
        }
    }

    /** Closed time never carries into a newly reopened waiting episode. */
    public function conversationUpdated(Conversation $conversation): void
    {
        if (! $conversation->wasChanged('status')) {
            return;
        }

        if ($conversation->status === 'closed') {
            $this->clearConversationWait($conversation);

            return;
        }

        if ((string) $conversation->getOriginal('status') === 'closed' && $conversation->attentionState() === 'needs_reply') {
            $this->resetConversationWait($conversation, now());
        }
    }

    /**
     * Commit waiting time under the site's current calendar before that
     * calendar or a manual closure changes.
     */
    public function advanceSite(Site $site, CarbonInterface $at): void
    {
        Conversation::query()
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->needsHumanReply()
            ->select('id')
            ->lazyById(250)
            ->each(fn (Conversation $conversation) => $this->advanceConversation($conversation, $site, $at));
    }

    /** Resume queue clocks without charging time while the site was archived. */
    public function resumeSite(Site $site, CarbonInterface $at): void
    {
        Conversation::query()
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->needsHumanReply()
            ->whereNotNull('support_wait_started_at')
            ->update(['support_wait_last_counted_at' => CarbonImmutable::instance($at)]);
    }

    /**
     * Project each waiting episode from its persisted business-time boundary
     * without changing notification state. Queue-health reporting uses this
     * same clock so a schedule edit or manual closure cannot rewrite the wait.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int>
     */
    public function projectedElapsedSecondsByConversation(Site $site, array $conversationIds, CarbonInterface $at): array
    {
        $elapsedByConversation = [];

        if ($conversationIds === []) {
            return $elapsedByConversation;
        }

        $conversations = Conversation::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $conversationIds)
            ->where('status', 'open')
            ->needsHumanReply()
            ->whereNotNull('support_wait_started_at')
            ->get();

        foreach ($conversations as $conversation) {
            $elapsedByConversation[(int) $conversation->id] = $this->projectedConversationElapsed($conversation, $site, $at);
        }

        return $elapsedByConversation;
    }

    /**
     * @return array{
     *     notification_id: string,
     *     reference: string,
     *     site_name: string,
     *     subject: string,
     *     waiting_since: string|null,
     *     url: string
     * }|null
     */
    private function candidateFor(User $agent, DatabaseNotification $notification): ?array
    {
        $conversationId = (int) data_get($notification->data, 'conversation_id');
        $conversation = $conversationId > 0
            ? Conversation::query()->with(['latestMessage', 'site'])->find($conversationId)
            : null;

        if (
            ! $conversation
            || $conversation->status !== 'open'
            || $conversation->site?->isArchived()
            || $conversation->attentionState() !== 'needs_reply'
            || ! $agent->shouldReceiveConversationAlert($conversation)
        ) {
            return null;
        }

        // The fixed unattended cadence and configured SLA clocks share the
        // same business-time calculator. A five-minute heuristic that fires at
        // 03:00 while the site is closed would disagree with every SLA state
        // beside it and train agents to ignore both.
        $waitingSince = $this->waitingSince($notification);

        if ($this->advanceConversation($conversation, $conversation->site, CarbonImmutable::now()) < self::THRESHOLD_MINUTES * 60) {
            return null;
        }

        // "Unseen" is account-wide: another agent opening the conversation
        // marks only THEIR notification read, but the wait has been seen —
        // nobody needs the email.
        if ($this->anyAgentSawSince($conversationId, $waitingSince)) {
            return null;
        }

        return [
            'notification_id' => (string) $notification->id,
            'reference' => $conversation->support_code,
            'site_name' => $conversation->site?->name ?? 'Unknown site',
            'subject' => $conversation->subject ?? 'Untitled conversation',
            'waiting_since' => $waitingSince->toISOString(),
            'url' => route('dashboard.conversations.show', $conversation->support_code, false),
        ];
    }

    /**
     * Stamp the notifications behind the just-emailed candidates — but only
     * while they still belong to the emailed episode. The listener may have
     * re-armed a notification (new clock, stamp dropped) between collection
     * and this write; stamping the NEW episode would silently swallow its
     * email.
     *
     * @param  Collection<int, array{notification_id: string, waiting_since: string|null}>  $candidates
     */
    public function stampEmailed(Collection $candidates, CarbonInterface $emailedAt): void
    {
        $episodeByNotification = $candidates->pluck('waiting_since', 'notification_id');

        DatabaseNotification::query()
            ->whereIn('id', $candidates->pluck('notification_id')->all())
            ->get()
            ->each(function (DatabaseNotification $notification) use ($emailedAt, $episodeByNotification): void {
                if ($this->waitingSince($notification)->toISOString() !== $episodeByNotification->get((string) $notification->id)) {
                    return;
                }

                $notification->forceFill([
                    'data' => [
                        ...$notification->data,
                        self::UNATTENDED_EMAILED_AT_KEY => $emailedAt->toISOString(),
                    ],
                ])->save();
            });
    }

    public function anyAgentSawSince(int $conversationId, CarbonImmutable $episodeStart): bool
    {
        // ACCEPTED EDGE: an agent watching the open conversation live leaves
        // no trace here — the transcript refresh endpoint is a pure read BY
        // DESIGN (its own test guards that), because a background tab writing
        // read states would eat the queues' new-activity markers. If they
        // watch for the full threshold without replying or clicking anything,
        // one redundant metadata-only email goes out. Revisit with a
        // focus-aware presence ping only if dogfood shows it matters.
        //
        // The authoritative view record otherwise: every conversation open
        // writes a ConversationReadState, including opens by agents who never
        // had a notification (queue walk-ins, assigned-only agents).
        // Strictly after: with second-precision timestamps a read from the
        // PREVIOUS episode can share the new episode's starting second, and
        // wrongly suppressing the email starves the visitor — the worse error
        // than one redundant mail.
        $viewed = ConversationReadState::query()
            ->where('conversation_id', $conversationId)
            ->where('last_read_at', '>', $episodeStart)
            ->exists();

        if ($viewed) {
            return true;
        }

        // Dismissing the alert from the alert center counts as seen too.
        // notifications.data is a TEXT column, so a JSON-path where clause
        // breaks on PostgreSQL (SQLite happens to tolerate it). SQL narrows
        // by the plain columns — type and the recency-bounded read_at — and
        // PHP matches the conversation.
        return DatabaseNotification::query()
            ->where('type', ConversationNeedsReply::class)
            ->whereNotNull('read_at')
            ->where('read_at', '>', $episodeStart)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversationId);
    }

    /**
     * When the current waiting episode began: the explicit episode clock when
     * the notification has been re-armed, the row's creation time otherwise.
     */
    public function waitingSince(DatabaseNotification $notification): CarbonImmutable
    {
        $stored = data_get($notification->data, self::WAITING_SINCE_KEY);

        return is_string($stored) && trim($stored) !== ''
            ? CarbonImmutable::parse($stored)
            : CarbonImmutable::parse($notification->created_at);
    }

    private function advanceConversation(Conversation $conversation, Site $site, CarbonInterface $at): int
    {
        return DB::transaction(function () use ($at, $conversation, $site): int {
            // Match every calendar and conversation mutation: account, site,
            // then the waiting conversation. A scheduler can therefore finish
            // before the edit boundary or resume after it without deadlocking
            // an agent write whose SLA observer must visit the same site.
            Account::query()->whereKey($site->account_id)->lockForUpdate()->firstOrFail();
            $currentSite = Site::query()->whereKey($site->id)->lockForUpdate()->first();

            if (! $currentSite) {
                return 0;
            }

            $current = Conversation::query()
                ->with(['latestMessage', 'latestNonIntegrationMessage'])
                ->lockForUpdate()
                ->find($conversation->id);

            if (! $current || $current->status !== 'open' || $current->attentionState() !== 'needs_reply') {
                return 0;
            }

            $startedAt = $current->support_wait_started_at
                ? CarbonImmutable::instance($current->support_wait_started_at)
                : CarbonImmutable::instance($current->queueTimingContext()['wait_since'] ?? $current->created_at);
            $lastCountedAt = $current->support_wait_last_counted_at
                ? CarbonImmutable::instance($current->support_wait_last_counted_at)
                : $startedAt;
            $countedAt = CarbonImmutable::instance($at);
            $elapsed = max(0, (int) $current->support_wait_elapsed_seconds);

            if ($lastCountedAt->lessThan($startedAt)) {
                $lastCountedAt = $startedAt;
                $elapsed = 0;
            }

            if ($countedAt->greaterThan($lastCountedAt) && ! $currentSite->isArchived()) {
                $elapsed += SiteAvailability::elapsedOpenSeconds($currentSite, $lastCountedAt, $countedAt);
            }

            $current->forceFill([
                'support_wait_started_at' => $startedAt,
                'support_wait_elapsed_seconds' => $elapsed,
                'support_wait_last_counted_at' => $countedAt->greaterThan($lastCountedAt) ? $countedAt : $lastCountedAt,
            ])->saveQuietly();

            return $elapsed;
        });
    }

    private function projectedConversationElapsed(Conversation $conversation, Site $site, CarbonInterface $at): int
    {
        $startedAt = CarbonImmutable::instance($conversation->support_wait_started_at);
        $lastCountedAt = $conversation->support_wait_last_counted_at
            ? CarbonImmutable::instance($conversation->support_wait_last_counted_at)
            : $startedAt;
        $elapsed = max(0, (int) $conversation->support_wait_elapsed_seconds);
        $countedAt = CarbonImmutable::instance($at);

        if ($lastCountedAt->lessThan($startedAt)) {
            $lastCountedAt = $startedAt;
            $elapsed = 0;
        }

        if ($countedAt->greaterThan($lastCountedAt)) {
            $elapsed += SiteAvailability::elapsedOpenSeconds($site, $lastCountedAt, $countedAt);
        }

        return $elapsed;
    }

    private function resetConversationWait(Conversation $conversation, CarbonInterface $startedAt): void
    {
        $startedAt = CarbonImmutable::instance($startedAt);
        $conversation->forceFill([
            'support_wait_started_at' => $startedAt,
            'support_wait_elapsed_seconds' => 0,
            'support_wait_last_counted_at' => $startedAt,
        ])->saveQuietly();
    }

    private function clearConversationWait(Conversation $conversation): void
    {
        $conversation->forceFill([
            'support_wait_started_at' => null,
            'support_wait_elapsed_seconds' => 0,
            'support_wait_last_counted_at' => null,
        ])->saveQuietly();
    }
}
