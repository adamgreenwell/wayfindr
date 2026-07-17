<?php

namespace App\Listeners;

use App\Events\ConversationMessageCreated;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\UnattendedConversationAlertCollector;
use Carbon\CarbonImmutable;
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
                    $this->notifyAgent($assignedAgent, new ConversationNeedsReply($message), $conversation);
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
            ->each(fn (User $agent) => $this->notifyAgent($agent, new ConversationNeedsReply($message), $conversation));
    }

    private function notifyAgent(User $agent, ConversationNeedsReply $notification, Conversation $conversation): void
    {
        $existingNotification = $this->existingUnreadConversationNotification($agent, $conversation->id);

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

        // A follow-up inside the SAME waiting episode must not re-arm the
        // unattended email, so the stamp survives the data refresh — but an
        // agent reply since the stamp ends that episode, and a fresh visitor
        // message after it is a genuinely new wait that must email again.
        $unattendedEmailedAt = data_get($existingData, UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY);

        if (
            is_string($unattendedEmailedAt)
            && $unattendedEmailedAt !== ''
            && ! $this->agentRepliedSince($conversation, $unattendedEmailedAt)
        ) {
            $data[UnattendedConversationAlertCollector::UNATTENDED_EMAILED_AT_KEY] = $unattendedEmailedAt;
        }

        $existingNotification->forceFill(['data' => $data])->save();
    }

    private function agentRepliedSince(Conversation $conversation, string $timestamp): bool
    {
        return $conversation->messages()
            ->where('sender_type', User::class)
            ->where('created_at', '>', CarbonImmutable::parse($timestamp))
            ->exists();
    }

    private function existingUnreadConversationNotification(User $agent, int $conversationId): ?DatabaseNotification
    {
        return $agent->unreadNotifications()
            ->where('type', ConversationNeedsReply::class)
            ->get()
            ->first(fn (DatabaseNotification $notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversationId);
    }
}
