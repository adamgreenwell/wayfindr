<?php

namespace App\Listeners;

use App\Events\ConversationMessageCreated;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Notifications\DatabaseNotification;

class NotifyAgentsOfVisitorMessage
{
    /**
     * Handle the event.
     */
    public function handle(ConversationMessageCreated $event): void
    {
        $message = $event->message;

        if ($message->sender_type !== Visitor::class) {
            return;
        }

        $message->loadMissing(['conversation.site.account']);

        $conversation = $message->conversation;

        if ($conversation->assigned_agent_id) {
            $assignedAgent = $conversation->site->account->agents()
                ->whereKey($conversation->assigned_agent_id)
                ->first();

            if ($assignedAgent && $conversation->site->supportsAgent($assignedAgent)) {
                if ($assignedAgent->shouldReceiveConversationAlert($conversation)) {
                    $this->notifyAgent($assignedAgent, new ConversationNeedsReply($message), $conversation->id);
                }

                return;
            }
        }

        $agentQuery = $conversation->site->hasExplicitSupportAgents()
            ? $conversation->site->eligibleSupportAgents()
            : $conversation->site->account->agents();

        $agentQuery
            ->get()
            ->filter(fn (User $agent): bool => $agent->shouldReceiveConversationAlert($conversation))
            ->each(fn (User $agent) => $this->notifyAgent($agent, new ConversationNeedsReply($message), $conversation->id));
    }

    private function notifyAgent(User $agent, ConversationNeedsReply $notification, int $conversationId): void
    {
        $existingNotification = $this->existingUnreadConversationNotification($agent, $conversationId);

        if (! $existingNotification) {
            $agent->notify($notification);

            return;
        }

        $existingData = $existingNotification->data;
        $messageCount = max(1, (int) data_get($existingData, 'message_count', 1)) + 1;

        $data = [
            ...$notification->toArray($agent),
            'message_count' => $messageCount,
        ];

        // A follow-up inside the same waiting episode must not re-arm the
        // unattended email — the stamp survives the data refresh.
        $unattendedEmailedAt = data_get($existingData, UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY);

        if (is_string($unattendedEmailedAt) && $unattendedEmailedAt !== '') {
            $data[UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY] = $unattendedEmailedAt;
        }

        $existingNotification->forceFill(['data' => $data])->save();
    }

    private function existingUnreadConversationNotification(User $agent, int $conversationId): ?DatabaseNotification
    {
        return $agent->unreadNotifications()
            ->where('type', ConversationNeedsReply::class)
            ->get()
            ->first(fn (DatabaseNotification $notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversationId);
    }
}
