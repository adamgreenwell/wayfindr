<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
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
            ->where('created_at', '<=', now()->subMinutes(self::THRESHOLD_MINUTES))
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            // One email per waiting episode: the stamp lives on the unread
            // notification, and a new episode means a new notification.
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

        return [
            'notification_id' => (string) $notification->id,
            'reference' => $conversation->support_code,
            'site_name' => $conversation->site?->name ?? 'Unknown site',
            'subject' => $conversation->subject ?? 'Untitled conversation',
            'waiting_since' => $notification->created_at?->toISOString(),
            'url' => route('dashboard.conversations.show', $conversation->support_code, false),
        ];
    }
}
