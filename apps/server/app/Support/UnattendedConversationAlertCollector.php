<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\ConversationReadState;
use App\Models\Site;
use App\Models\User;
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

    public const ELAPSED_SECONDS_KEY = 'unattended_elapsed_seconds';

    public const LAST_COUNTED_AT_KEY = 'unattended_last_counted_at';

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
            && $agent->wantsUnattendedAlertEmail()
            && $agent->alertMode() !== User::ALERT_MODE_QUIET;
    }

    /**
     * Commit waiting time under the site's current calendar before that
     * calendar or a manual closure changes.
     */
    public function advanceSite(Site $site, CarbonInterface $at): void
    {
        $conversationIds = Conversation::query()
            ->where('site_id', $site->id)
            ->where('status', 'open')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $agentIds = User::query()
            ->where('account_id', $site->account_id)
            ->pluck('id')
            ->all();

        if ($conversationIds === [] || $agentIds === []) {
            return;
        }

        DatabaseNotification::query()
            ->where('type', ConversationNeedsReply::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $agentIds)
            ->whereNull('read_at')
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => in_array(
                (int) data_get($notification->data, 'conversation_id'),
                $conversationIds,
                true,
            ))
            ->each(fn (DatabaseNotification $notification) => $this->advanceNotification($notification, $site, $at));
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

        if ($this->advanceNotification($notification, $conversation->site, CarbonImmutable::now()) < self::THRESHOLD_MINUTES * 60) {
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

    private function advanceNotification(DatabaseNotification $notification, Site $site, CarbonInterface $at): int
    {
        return DB::transaction(function () use ($at, $notification, $site): int {
            $current = DatabaseNotification::query()->lockForUpdate()->find($notification->id);

            if (! $current || $current->read_at !== null) {
                return 0;
            }

            $waitingSince = $this->waitingSince($current);
            $lastCountedAt = $this->lastCountedAt($current, $waitingSince);
            $elapsed = max(0, (int) data_get($current->data, self::ELAPSED_SECONDS_KEY, 0));
            $countedAt = CarbonImmutable::instance($at);

            if ($lastCountedAt->lessThan($waitingSince)) {
                $lastCountedAt = $waitingSince;
                $elapsed = 0;
            }

            if ($countedAt->greaterThan($lastCountedAt)) {
                $elapsed += SiteAvailability::elapsedOpenSeconds($site, $lastCountedAt, $countedAt);
                $current->forceFill(['data' => [
                    ...$current->data,
                    self::ELAPSED_SECONDS_KEY => $elapsed,
                    self::LAST_COUNTED_AT_KEY => $countedAt->toISOString(),
                ]])->save();
            }

            return $elapsed;
        });
    }

    private function lastCountedAt(DatabaseNotification $notification, CarbonImmutable $waitingSince): CarbonImmutable
    {
        $stored = data_get($notification->data, self::LAST_COUNTED_AT_KEY);

        return is_string($stored) && trim($stored) !== ''
            ? CarbonImmutable::parse($stored)
            : $waitingSince;
    }
}
